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
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\OAuth\OAuthException;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token-Endpunkt (/mcp/token) und Widerruf (/mcp/revoke).
 *
 * Erwartet Formulardaten (application/x-www-form-urlencoded), wie RFC 6749
 * es vorschreibt und Claude sie sendet. Die Route prüft kein Request-Token
 * und liegt in keiner Firewall; der Client weist sich über Code und PKCE
 * bzw. den Erneuerungsschlüssel aus.
 */
class TokenController
{
	/**
	 * @param ContaoFramework     $framework Für die Einstellungen
	 * @param Settings            $settings  Notschalter
	 * @param AuthorizationServer $server    Die OAuth-Regeln
	 * @param LoggerInterface     $logger    Für unerwartete Fehler
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Settings $settings,
		private readonly AuthorizationServer $server,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Tauscht einen Code bzw. erneuert die Schlüssel.
	 *
	 * Fehler kommen als JSON mit error und error_description (RFC 6749
	 * Abschnitt 5.2). Wichtig für Claude: Ein ungültiger Erneuerungsschlüssel
	 * ergibt invalid_grant, dann fragt Claude den Benutzer neu.
	 *
	 * @param Request $request Die Anfrage
	 *
	 * @return Response Die Schlüssel oder das Fehlerobjekt
	 */
	public function token(Request $request): Response
	{
		return $this->handle($request, function (array $params): array {
			return match ($params['grant_type'] ?? '') {
				'authorization_code' => $this->server->exchangeCode($params, time()),
				'refresh_token' => $this->server->refresh($params, time()),
				'' => throw new OAuthException('invalid_request', 'Der Parameter grant_type fehlt.'),
				default => throw new OAuthException('unsupported_grant_type', 'Unterstützt werden authorization_code und refresh_token.'),
			};
		});
	}

	/**
	 * Widerruft eine Verbindung über einen ihrer Schlüssel (RFC 7009).
	 *
	 * Antwortet immer mit 200, auch für unbekannte Schlüssel.
	 *
	 * @param Request $request Die Anfrage mit dem Feld token
	 *
	 * @return Response Leeres JSON-Objekt oder Fehlerobjekt
	 */
	public function revoke(Request $request): Response
	{
		return $this->handle($request, function (array $params): array {
			if ('' === ($params['token'] ?? '')) {
				throw new OAuthException('invalid_request', 'Der Parameter token fehlt.');
			}

			$this->server->revoke($params['token'], $params['client_id'] ?? '');

			return array();
		});
	}

	/**
	 * Gemeinsamer Rahmen: Methode, Notschalter, Parameter, Fehler, Header.
	 *
	 * @param Request  $request Die Anfrage
	 * @param callable $action  Erhält die Parameter und liefert die Antwort als Feld
	 *
	 * @return Response Die Antwort
	 */
	private function handle(Request $request, callable $action): Response
	{
		$this->framework->initialize();

		if ($request->isMethod('OPTIONS')) {
			return $this->json(null, Response::HTTP_NO_CONTENT);
		}

		try {
			if (!$request->isMethod('POST')) {
				throw new OAuthException('invalid_request', 'Nur POST ist erlaubt.', Response::HTTP_METHOD_NOT_ALLOWED);
			}

			if (!$this->settings->isEnabled()) {
				throw new OAuthException('temporarily_unavailable', 'Der MCP-Server dieser Contao-Installation ist abgeschaltet.', Response::HTTP_SERVICE_UNAVAILABLE);
			}

			$params = array();

			// Felder mit Listen ([]) sind im Protokoll nicht vorgesehen und fallen weg
			foreach ($request->request->all() as $name => $value) {
				if (\is_string($value)) {
					$params[(string) $name] = $value;
				}
			}

			return $this->json($action($params), Response::HTTP_OK);
		} catch (OAuthException $exception) {
			return $this->json(array('error' => $exception->error, 'error_description' => $exception->getMessage()), $exception->status);
		} catch (\Throwable $exception) {
			$this->logger->error('OAuth-Anfrage fehlgeschlagen: '.$exception->getMessage(), array('exception' => $exception));

			return $this->json(array('error' => 'server_error', 'error_description' => 'Interner Fehler.'), Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Baut eine JSON-Antwort mit den Pflicht-Headern.
	 *
	 * Token-Antworten dürfen nicht zwischengespeichert werden (RFC 6749
	 * Abschnitt 5.1: Cache-Control no-store, Pragma no-cache).
	 *
	 * @param array<string, mixed>|null $data   Der Inhalt, null für einen leeren Rumpf
	 * @param int                       $status HTTP-Status
	 *
	 * @return Response Die Antwort
	 */
	private function json(?array $data, int $status): Response
	{
		$response = null === $data ? new Response('', $status) : new JsonResponse(array() === $data ? new \stdClass() : $data, $status);
		$response->headers->set('Pragma', 'no-cache');

		return OAuthMetadataController::withCommonHeaders($response);
	}
}
