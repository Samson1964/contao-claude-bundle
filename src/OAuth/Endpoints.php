<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Die Adressen von Ressource und Autorisierungsserver.
 *
 * Alle Adressen werden aus der laufenden Anfrage gebildet, damit dieselbe
 * Installation unter mehreren Domains (oder hinter einem Proxy) jeweils
 * ihre eigenen Adressen ankündigt. Der Herausgeber (issuer) ist Schema,
 * Host und Basispfad der Installation, die Ressource die Adresse von /mcp.
 */
class Endpoints
{
	/**
	 * @param UrlGeneratorInterface $urlGenerator Erzeugt die Adressen der Routen
	 * @param RequestStack          $requestStack Liefert Schema, Host und Basispfad
	 */
	public function __construct(
		private readonly UrlGeneratorInterface $urlGenerator,
		private readonly RequestStack $requestStack,
	) {
	}

	/**
	 * Liefert den Herausgeber, etwa „https://www.example.org“.
	 *
	 * @return string Schema, Host und Basispfad ohne Schrägstrich am Ende
	 */
	public function getIssuer(): string
	{
		$request = $this->requestStack->getCurrentRequest();

		return null === $request ? '' : rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
	}

	/**
	 * Liefert die kanonische Adresse der Ressource /mcp.
	 *
	 * @return string Etwa „https://www.example.org/mcp“
	 */
	public function getResource(): string
	{
		return $this->url('claude_mcp');
	}

	/**
	 * Liefert die Adresse der Metadaten der Ressource (RFC 9728).
	 *
	 * Gemeint ist die Form mit angehängtem Pfad, die Clients zuerst
	 * versuchen, wenn die Ressource einen Pfad hat.
	 *
	 * @return string Etwa „https://www.example.org/.well-known/oauth-protected-resource/mcp“
	 */
	public function getResourceMetadataUrl(): string
	{
		return $this->url('claude_oauth_resource_metadata_mcp');
	}

	/**
	 * Liefert die Adresse der Bestätigungsseite.
	 *
	 * Sie folgt dem eingestellten Backend-Präfix (contao.backend.route_prefix)
	 * und liegt hinter dem Backend-Login.
	 *
	 * @return string Etwa „https://www.example.org/contao/mcp/authorize“
	 */
	public function getAuthorizationEndpoint(): string
	{
		return $this->url('claude_oauth_authorize');
	}

	/**
	 * Liefert die Adresse für Code-Tausch und Erneuerung.
	 *
	 * @return string Etwa „https://www.example.org/mcp/token“
	 */
	public function getTokenEndpoint(): string
	{
		return $this->url('claude_oauth_token');
	}

	/**
	 * Liefert die Adresse für den Widerruf nach RFC 7009.
	 *
	 * @return string Etwa „https://www.example.org/mcp/revoke“
	 */
	public function getRevocationEndpoint(): string
	{
		return $this->url('claude_oauth_revoke');
	}

	/**
	 * Prüft, ob eine angegebene Ressource diese Installation meint.
	 *
	 * Groß- und Kleinschreibung von Schema und Host sowie ein Schrägstrich
	 * am Ende spielen keine Rolle.
	 *
	 * @param string $resource Der Parameter resource (RFC 8707) oder die gespeicherte Ressource
	 *
	 * @return bool true, wenn die Ressource /mcp dieser Installation ist
	 */
	public function isOwnResource(string $resource): bool
	{
		return self::normalize($resource) === self::normalize($this->getResource());
	}

	/**
	 * Vereinheitlicht eine Adresse für den Vergleich.
	 *
	 * @param string $url Die Adresse
	 *
	 * @return string Schema und Host klein, ohne Schrägstrich am Ende
	 */
	public static function normalize(string $url): string
	{
		$parts = parse_url(trim($url));

		if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}

		$port = isset($parts['port']) ? ':'.$parts['port'] : '';

		return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.rtrim($parts['path'] ?? '', '/');
	}

	/**
	 * Erzeugt die absolute Adresse einer Route des Bundles.
	 *
	 * @param string $route Name der Route
	 *
	 * @return string Die absolute Adresse
	 */
	private function url(string $route): string
	{
		return $this->urlGenerator->generate($route, array(), UrlGeneratorInterface::ABSOLUTE_URL);
	}
}
