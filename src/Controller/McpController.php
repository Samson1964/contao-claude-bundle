<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoClaudeBundle\Mcp\CallContext;
use Schachbulle\ContaoClaudeBundle\Mcp\HttpBridge;
use Schachbulle\ContaoClaudeBundle\Mcp\ServerFactory;
use Schachbulle\ContaoClaudeBundle\OAuth\Endpoints;
use Schachbulle\ContaoClaudeBundle\OAuth\Scopes;
use Schachbulle\ContaoClaudeBundle\Security\BackendUserLogin;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Security\LoginFailedException;
use Schachbulle\ContaoClaudeBundle\Security\TokenValidator;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der MCP-Endpunkt /mcp (Streamable HTTP).
 *
 * Reihenfolge je Anfrage: Notschalter, Schlüssel, Backend-Benutzer, dann
 * übernimmt das SDK. Die Route liegt in keiner Contao-Firewall und prüft
 * kein Request-Token (_token_check: false); die Anmeldung erledigt allein
 * der Bearer-Schlüssel.
 *
 * Jede Antwort trägt „Cache-Control: no-store, private“. Contaos Seitencache
 * hält Antworten sonst bis zu einem Jahr fest (s-maxage auf schachbund.de).
 */
class McpController
{
	/**
	 * @param ContaoFramework  $framework      Wird vor allem anderen gestartet
	 * @param Settings         $settings       Notschalter
	 * @param TokenValidator   $tokenValidator Prüft den Bearer-Schlüssel
	 * @param BackendUserLogin $login          Meldet den Backend-Benutzer an
	 * @param CallContext      $context        Nimmt den Zugang für die Werkzeuge auf
	 * @param ServerFactory    $serverFactory  Baut den MCP-Server
	 * @param LoggerInterface  $logger         Für unerwartete Fehler
	 * @param Endpoints        $endpoints      Adresse der Metadaten für die 401-Antwort
	 * @param ConnectionPolicy $policy         Wer sich über OAuth verbinden darf
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Settings $settings,
		private readonly TokenValidator $tokenValidator,
		private readonly BackendUserLogin $login,
		private readonly CallContext $context,
		private readonly ServerFactory $serverFactory,
		private readonly LoggerInterface $logger,
		private readonly Endpoints $endpoints,
		private readonly ConnectionPolicy $policy,
	) {
	}

	/**
	 * Beantwortet eine Anfrage an /mcp.
	 *
	 * GET beantwortet der Server mit 405: Einen Ereignisstrom für
	 * Nachrichten des Servers bietet er nicht an, das erlaubt die
	 * Spezifikation ausdrücklich. OPTIONS (CORS-Vorabfrage aus dem Browser,
	 * etwa vom MCP Inspector) geht ohne Schlüssel an das SDK.
	 *
	 * @param Request $request Die Anfrage
	 *
	 * @return Response Die Antwort, bei Fehlern als JSON-RPC-Fehlerobjekt
	 */
	public function __invoke(Request $request): Response
	{
		// Die Route selbst hat keinen _scope, sonst schickte die Backend-Firewall
		// jede Anfrage ohne Sitzung zur Anmeldung. Die Firewall ist an dieser
		// Stelle schon durchlaufen; für Contao gilt die Anfrage ab jetzt als
		// Backend-Anfrage. Das braucht das Schreiben: Im Frontend maskiert
		// Input::post() Insert-Tags („{{“ wird zu „&#123;&#123;“), in 4.13 über
		// TL_MODE, das beim Start des Frameworks festgelegt wird, in 5.7 über
		// den ScopeMatcher bei jedem Aufruf.
		$request->attributes->set('_scope', 'backend');

		$this->framework->initialize();

		if ($request->isMethod('GET')) {
			$response = $this->error(Response::HTTP_METHOD_NOT_ALLOWED, -32000, 'GET wird nicht unterstützt; Nachrichten bitte per POST senden.');
			$response->headers->set('Allow', 'POST, DELETE, OPTIONS');

			return $response;
		}

		if ($request->isMethod('OPTIONS')) {
			return $this->finish($this->runServer($request));
		}

		if (!$this->settings->isEnabled()) {
			return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, -32000, 'Der MCP-Server dieser Contao-Installation ist abgeschaltet.');
		}

		$token = TokenValidator::extractBearer($request->headers->get('Authorization'));

		if (null === $token) {
			return $this->unauthorized('Anmeldung erforderlich: Der Header „Authorization: Bearer <Schlüssel>“ fehlt.', null);
		}

		$client = $this->tokenValidator->validate($token);

		if (null === $client) {
			return $this->unauthorized('Der Schlüssel ist ungültig oder abgeschaltet.', 'invalid_token');
		}

		// Eine geänderte Einstellung oder Gruppe wirkt sofort auch auf bestehende
		// Verbindungen. 403 statt 401: Eine neue Anmeldung würde nichts ändern.
		$denied = $client->isOAuth() ? $this->policy->check($client->userId) : null;

		if (null !== $denied) {
			return $this->error(Response::HTTP_FORBIDDEN, -32001, $denied);
		}

		try {
			$this->login->login($client->userId);
		} catch (LoginFailedException $exception) {
			return $this->unauthorized($exception->getMessage(), 'invalid_token');
		}

		$this->context->setClient($client);

		try {
			return $this->finish($this->runServer($request));
		} catch (\Throwable $exception) {
			$this->logger->error('MCP-Anfrage fehlgeschlagen: '.$exception->getMessage(), array('exception' => $exception));

			return $this->error(Response::HTTP_INTERNAL_SERVER_ERROR, -32603, 'Interner Fehler des MCP-Servers.');
		} finally {
			$this->context->setClient(null);
			$this->login->logout();
		}
	}

	/**
	 * Lässt das SDK die Anfrage beantworten.
	 *
	 * Statt der Vorgabe-Middleware des SDK läuft nur CORS (für alle
	 * Herkünfte, ohne Cookies). Der Schutz gegen DNS-Rebinding des SDK
	 * lässt nur „localhost“ als Host zu und ist für lokale Server gedacht;
	 * hier schützt der Bearer-Schlüssel, den eine fremde Seite nicht kennt.
	 *
	 * @param Request $request Die Anfrage
	 *
	 * @return Response Die Antwort des SDK
	 */
	private function runServer(Request $request): Response
	{
		$factory = new Psr17Factory();
		$transport = new StreamableHttpTransport(
			HttpBridge::toPsr($request, $factory),
			$factory,
			$factory,
			$this->logger,
			array(new CorsMiddleware(array('*'))),
		);

		// Eine CORS-Vorabfrage braucht keinen angemeldeten Benutzer und
		// erreicht deshalb auch keine Werkzeuge
		$server = $request->isMethod('OPTIONS') ? $this->createBareServer() : $this->serverFactory->create();

		return HttpBridge::toSymfony($server->run($transport));
	}

	/**
	 * Baut einen Server ohne Werkzeuge für die CORS-Vorabfrage.
	 *
	 * @return Server Der Server
	 */
	private function createBareServer(): Server
	{
		return Server::builder()->setServerInfo('contao-claude', 'dev')->build();
	}

	/**
	 * Antwortet mit 401 und dem Header WWW-Authenticate.
	 *
	 * Der Header startet bei Claude die Anmeldung: resource_metadata zeigt auf
	 * die Metadaten der Ressource (RFC 9728), scope nennt die Rechte, die
	 * Claude anfragen soll. Er bleibt ASCII (Header-Werte vertragen keine
	 * Umlaute); die deutsche Meldung steht im JSON-Rumpf.
	 *
	 * @param string      $message Meldung für den Client
	 * @param string|null $error   Fehlercode nach RFC 6750, null ohne Schlüssel
	 *
	 * @return Response Die Antwort
	 */
	private function unauthorized(string $message, ?string $error): Response
	{
		$response = $this->error(Response::HTTP_UNAUTHORIZED, -32001, $message);
		$header = 'Bearer realm="Contao MCP"';

		if (null !== $error) {
			$header .= sprintf(', error="%s"', $error);
		}

		$header .= sprintf(', resource_metadata="%s", scope="%s"', $this->endpoints->getResourceMetadataUrl(), implode(' ', Scopes::grantable($this->settings->isWriteEnabled())));

		$response->headers->set('WWW-Authenticate', $header);

		return $response;
	}

	/**
	 * Baut eine Fehlerantwort als JSON-RPC-Fehlerobjekt.
	 *
	 * @param int    $status  HTTP-Status
	 * @param int    $code    JSON-RPC-Fehlercode
	 * @param string $message Meldung für den Client
	 *
	 * @return Response Die Antwort, schon mit den gemeinsamen Headern
	 */
	private function error(int $status, int $code, string $message): Response
	{
		$response = new JsonResponse(
			array('jsonrpc' => '2.0', 'id' => null, 'error' => array('code' => $code, 'message' => $message)),
			$status
		);

		$response->headers->set('Access-Control-Allow-Origin', '*');
		$response->headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');

		return $this->finish($response);
	}

	/**
	 * Setzt die Header, die jede Antwort von /mcp trägt.
	 *
	 * @param Response $response Die Antwort
	 *
	 * @return Response Dieselbe Antwort
	 */
	private function finish(Response $response): Response
	{
		$response->headers->set('Cache-Control', 'no-store, private');
		$response->headers->set('X-Robots-Tag', 'noindex');

		return $response;
	}
}
