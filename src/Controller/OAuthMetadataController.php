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
use Schachbulle\ContaoClaudeBundle\OAuth\Endpoints;
use Schachbulle\ContaoClaudeBundle\OAuth\Scopes;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Metadaten, über die Claude die Anmeldung findet.
 *
 * Ablauf aus Sicht von Claude: /mcp antwortet 401 mit einem Verweis auf
 * die Metadaten der Ressource (RFC 9728), diese nennen den
 * Autorisierungsserver, dessen Metadaten (RFC 8414) die Endpunkte. Ressource
 * und Autorisierungsserver sind hier dieselbe Contao-Installation.
 */
class OAuthMetadataController
{
	/**
	 * @param ContaoFramework $framework Für die Einstellungen
	 * @param Settings        $settings  Notschalter
	 * @param Endpoints       $endpoints Die Adressen
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Settings $settings,
		private readonly Endpoints $endpoints,
	) {
	}

	/**
	 * Metadaten der geschützten Ressource /mcp (RFC 9728).
	 *
	 * Claude nutzt nur den ersten Eintrag in authorization_servers, und
	 * resource muss genau der Adresse entsprechen, die der Benutzer in Claude
	 * einträgt.
	 *
	 * @param Request $request Die Anfrage, für die CORS-Vorabfrage
	 *
	 * @return Response Das JSON-Dokument, 503 bei ausgeschaltetem Server
	 */
	public function protectedResource(Request $request): Response
	{
		return $this->respond($request, fn (): array => array(
			'resource' => $this->endpoints->getResource(),
			'authorization_servers' => array($this->endpoints->getIssuer()),
			'scopes_supported' => Scopes::grantable($this->settings->isWriteEnabled()),
			'bearer_methods_supported' => array('header'),
			'resource_name' => 'Contao '.$request->getHost(),
		));
	}

	/**
	 * Metadaten des Autorisierungsservers (RFC 8414).
	 *
	 * Claude wählt das Client ID Metadata Document nur, wenn sowohl
	 * client_id_metadata_document_supported als auch „none“ unter
	 * token_endpoint_auth_methods_supported stehen; sonst sucht es einen
	 * registration_endpoint, den es hier nicht gibt.
	 *
	 * @param Request $request Die Anfrage, für die CORS-Vorabfrage
	 *
	 * @return Response Das JSON-Dokument, 503 bei ausgeschaltetem Server
	 */
	public function authorizationServer(Request $request): Response
	{
		return $this->respond($request, fn (): array => array(
			'issuer' => $this->endpoints->getIssuer(),
			'authorization_endpoint' => $this->endpoints->getAuthorizationEndpoint(),
			'token_endpoint' => $this->endpoints->getTokenEndpoint(),
			'revocation_endpoint' => $this->endpoints->getRevocationEndpoint(),
			'scopes_supported' => Scopes::grantable($this->settings->isWriteEnabled()),
			'response_types_supported' => array('code'),
			'response_modes_supported' => array('query'),
			'grant_types_supported' => array('authorization_code', 'refresh_token'),
			'token_endpoint_auth_methods_supported' => array('none'),
			'revocation_endpoint_auth_methods_supported' => array('none'),
			'code_challenge_methods_supported' => array('S256'),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
		));
	}

	/**
	 * Baut die Antwort samt CORS und Notschalter.
	 *
	 * @param Request  $request  Die Anfrage
	 * @param callable $document Liefert das Dokument als Feld
	 *
	 * @return Response Die Antwort
	 */
	private function respond(Request $request, callable $document): Response
	{
		$this->framework->initialize();

		if ($request->isMethod('OPTIONS')) {
			$response = new Response('', Response::HTTP_NO_CONTENT);
		} elseif (!$this->settings->isEnabled()) {
			$response = new JsonResponse(array('error' => 'temporarily_unavailable', 'error_description' => 'Der MCP-Server dieser Contao-Installation ist abgeschaltet.'), Response::HTTP_SERVICE_UNAVAILABLE);
		} else {
			$response = new JsonResponse($document(), Response::HTTP_OK, array(), false);
			$response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return self::withCommonHeaders($response);
	}

	/**
	 * Setzt CORS- und Cache-Header für die öffentlichen OAuth-Endpunkte.
	 *
	 * CORS für alle Herkünfte ist hier unbedenklich: Die Endpunkte arbeiten
	 * ohne Cookies, und ohne Schlüssel oder Code liefern sie nichts Geheimes.
	 *
	 * @param Response $response Die Antwort
	 *
	 * @return Response Dieselbe Antwort
	 */
	public static function withCommonHeaders(Response $response): Response
	{
		$response->headers->set('Access-Control-Allow-Origin', '*');
		$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version');
		$response->headers->set('Cache-Control', 'no-store, private');
		$response->headers->set('X-Robots-Tag', 'noindex');

		return $response;
	}
}
