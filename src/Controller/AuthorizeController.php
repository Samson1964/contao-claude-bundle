<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\OAuth\ClientRegistry;
use Schachbulle\ContaoClaudeBundle\OAuth\Endpoints;
use Schachbulle\ContaoClaudeBundle\OAuth\Pkce;
use Schachbulle\ContaoClaudeBundle\OAuth\RedirectUriMatcher;
use Schachbulle\ContaoClaudeBundle\OAuth\Scopes;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

/**
 * Die Bestätigungsseite der Anmeldung (/contao/mcp/authorize).
 *
 * Die Route liegt hinter der Backend-Firewall (_scope: backend): Ohne
 * Backend-Sitzung leitet Contao zur Anmeldung um, samt Zwei-Faktor-Abfrage,
 * und danach hierher zurück. Bestätigt wird per POST mit Contaos
 * Request-Token, das Contao für Backend-Routen selbst prüft.
 *
 * Fehlerbehandlung nach RFC 6749 Abschnitt 4.1.2.1: Solange Client oder
 * Rücksprungadresse nicht geprüft sind, wird nie umgeleitet (sonst ließe
 * sich die Seite als offene Weiterleitung missbrauchen); danach gehen
 * Fehler als error-Parameter an die Rücksprungadresse.
 */
class AuthorizeController
{
	/**
	 * Parameter, die von der Anzeige zur Bestätigung weitergereicht werden.
	 */
	private const PARAMETERS = array('response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource');

	/**
	 * @param ContaoFramework        $framework    Für die Einstellungen
	 * @param Settings               $settings     Notschalter
	 * @param ClientRegistry         $clients      Die zugelassenen Clients
	 * @param Endpoints              $endpoints    Herausgeber und Ressource
	 * @param AuthorizationServer    $server       Stellt den Code aus
	 * @param ConnectionPolicy       $policy       Wer sich verbinden darf
	 * @param TokenStorageInterface  $tokenStorage Liefert den angemeldeten Backend-Benutzer
	 * @param Environment            $twig         Rendert die Seite
	 * @param ContaoCsrfTokenManager $csrf         Liefert das Request-Token für das Formular
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Settings $settings,
		private readonly ClientRegistry $clients,
		private readonly Endpoints $endpoints,
		private readonly AuthorizationServer $server,
		private readonly ConnectionPolicy $policy,
		private readonly TokenStorageInterface $tokenStorage,
		private readonly Environment $twig,
		private readonly ContaoCsrfTokenManager $csrf,
	) {
	}

	/**
	 * Zeigt die Bestätigung (GET) oder verarbeitet die Entscheidung (POST).
	 *
	 * Bei POST wird alles noch einmal geprüft: Die Felder kommen aus dem
	 * Formular und könnten verändert sein.
	 *
	 * @param Request $request Die Anfrage mit den Parametern der Autorisierung
	 *
	 * @return Response Die Seite oder die Weiterleitung zu Claude
	 */
	public function __invoke(Request $request): Response
	{
		$this->framework->initialize();

		$params = array();

		foreach (self::PARAMETERS as $name) {
			$value = $request->isMethod('POST') ? $request->request->get($name) : $request->query->get($name);
			$params[$name] = \is_string($value) ? $value : '';
		}

		if (!$this->settings->isEnabled()) {
			return $this->page(array('error' => 'Der MCP-Server dieser Contao-Installation ist abgeschaltet.'), Response::HTTP_SERVICE_UNAVAILABLE);
		}

		$client = $this->clients->find($params['client_id']);

		if (null === $client) {
			return $this->page(array('error' => 'Dieser Client ist hier nicht zugelassen. Anmelden können sich nur die Claude-Apps und Claude Code.'), Response::HTTP_BAD_REQUEST);
		}

		$redirectUri = $params['redirect_uri'];

		if (!RedirectUriMatcher::matches($client->redirectUris, $redirectUri)) {
			return $this->page(array('error' => 'Die Rücksprungadresse ist für '.$client->name.' nicht zugelassen.'), Response::HTTP_BAD_REQUEST);
		}

		// Ab hier gehen Fehler an den Client zurück
		$state = $params['state'];

		if ('code' !== $params['response_type']) {
			return $this->redirect($redirectUri, array('error' => 'unsupported_response_type', 'error_description' => 'Nur response_type=code.'), $state);
		}

		if ('S256' !== $params['code_challenge_method'] || !Pkce::isValidChallenge($params['code_challenge'])) {
			return $this->redirect($redirectUri, array('error' => 'invalid_request', 'error_description' => 'PKCE mit S256 ist Pflicht.'), $state);
		}

		$resource = '' === $params['resource'] ? $this->endpoints->getResource() : $params['resource'];

		if (!$this->endpoints->isOwnResource($resource)) {
			return $this->redirect($redirectUri, array('error' => 'invalid_target', 'error_description' => 'Die Ressource gehört nicht zu dieser Installation.'), $state);
		}

		$user = $this->tokenStorage->getToken()?->getUser();

		if (!$user instanceof BackendUser) {
			return $this->page(array('error' => 'Bitte melde dich im Contao-Backend an.'), Response::HTTP_UNAUTHORIZED);
		}

		$denied = $this->policy->check((int) $user->id);
		$cancelUrl = $this->buildUrl($redirectUri, array('error' => 'access_denied'), $state);

		// Als einfaches Feld: Twig erreicht die magischen Eigenschaften von BackendUser nicht verlässlich
		$userView = array('name' => (string) $user->name, 'username' => (string) $user->username, 'isAdmin' => (bool) $user->isAdmin);

		if (null !== $denied) {
			return $this->page(array('error' => $denied, 'cancelUrl' => $cancelUrl, 'user' => $userView), Response::HTTP_FORBIDDEN);
		}

		$scopes = Scopes::grant($params['scope']);

		if ($request->isMethod('POST')) {
			if ('allow' !== $request->request->get('decision')) {
				return new RedirectResponse($cancelUrl, Response::HTTP_SEE_OTHER);
			}

			$code = $this->server->issueCode((int) $user->id, $client, $redirectUri, $params['code_challenge'], $scopes, $resource, time());

			return $this->redirect($redirectUri, array('code' => $code), $state);
		}

		return $this->page(array(
			'client' => $client,
			'redirectHost' => (string) parse_url($redirectUri, PHP_URL_HOST),
			'loopback' => RedirectUriMatcher::isLoopback($redirectUri),
			'user' => $userView,
			'host' => $request->getHost(),
			'scopes' => array_map(static fn (string $scope): string => Scopes::DESCRIPTIONS[$scope] ?? $scope, $scopes),
			'params' => array_merge($params, array('resource' => $resource)),
			'requestToken' => $this->csrf->getDefaultTokenValue(),
			'action' => $this->endpoints->getAuthorizationEndpoint(),
			'cancelUrl' => $cancelUrl,
		));
	}

	/**
	 * Rendert die Seite mit Schutz gegen Einbetten und Zwischenspeichern.
	 *
	 * X-Frame-Options und frame-ancestors verhindern, dass eine fremde Seite
	 * die Bestätigung unsichtbar einbettet und den Klick auf „Erlauben“
	 * erschleicht (Clickjacking).
	 *
	 * @param array<string, mixed> $context Variablen für das Template
	 * @param int                  $status  HTTP-Status
	 *
	 * @return Response Die Seite
	 */
	private function page(array $context, int $status = Response::HTTP_OK): Response
	{
		$response = new Response($this->twig->render('@ContaoClaude/authorize.html.twig', $context + array('host' => '')), $status);
		$response->headers->set('Cache-Control', 'no-store, private');
		$response->headers->set('X-Frame-Options', 'DENY');
		$response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
		$response->headers->set('Referrer-Policy', 'no-referrer');

		return $response;
	}

	/**
	 * Leitet zur Rücksprungadresse des Clients weiter.
	 *
	 * @param string                $redirectUri Die geprüfte Rücksprungadresse
	 * @param array<string, string> $query       Anzuhängende Parameter (code oder error)
	 * @param string                $state       Der Parameter state der Anfrage
	 *
	 * @return RedirectResponse Die Weiterleitung (303, auch nach POST als GET)
	 */
	private function redirect(string $redirectUri, array $query, string $state): RedirectResponse
	{
		return new RedirectResponse($this->buildUrl($redirectUri, $query, $state), Response::HTTP_SEE_OTHER);
	}

	/**
	 * Hängt Parameter an die Rücksprungadresse.
	 *
	 * Neben code bzw. error gehen state (unverändert zurück) und iss
	 * (RFC 9207, schützt vor Verwechslung mehrerer Autorisierungsserver) mit.
	 *
	 * @param string                $redirectUri Die Rücksprungadresse, eventuell mit Query
	 * @param array<string, string> $query       Anzuhängende Parameter
	 * @param string                $state       Der Parameter state, leer wenn keiner kam
	 *
	 * @return string Die vollständige Adresse
	 */
	private function buildUrl(string $redirectUri, array $query, string $state): string
	{
		if ('' !== $state) {
			$query['state'] = $state;
		}

		$query['iss'] = $this->endpoints->getIssuer();

		return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}
}
