<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Die zugelassenen OAuth-Clients: nur Claude.
 *
 * Claude weist sich mit einem Client ID Metadata Document (CIMD) aus: Die
 * client_id ist eine HTTPS-Adresse bei Anthropic, unter der ein JSON-Dokument
 * mit Name und Rücksprungadressen liegt. Zugelassen sind genau die beiden
 * Dokumente aus der Positivliste – für die Claude-Apps (claude.ai, Desktop,
 * Handy) und für Claude Code. Andere Adressen werden nie abgerufen; damit
 * entfällt der SSRF-Schutz, den ein offener CIMD-Abruf bräuchte.
 *
 * Das Dokument wird beim ersten Bedarf abgerufen und einen Tag
 * zwischengespeichert, damit geänderte Rücksprungadressen bei Anthropic ohne
 * neue Bundle-Fassung greifen. Ist es nicht erreichbar oder nicht stimmig,
 * gelten die hier hinterlegten Werte (Stand 02.11.2026, abgerufen von
 * claude.ai).
 */
class ClientRegistry
{
	/**
	 * Positivliste mit den Werten, die gelten, wenn das Dokument fehlt.
	 */
	public const CLIENTS = array(
		'https://claude.ai/oauth/mcp-oauth-client-metadata' => array(
			'name' => 'Claude',
			'redirect_uris' => array('https://claude.ai/api/mcp/auth_callback'),
		),
		'https://claude.ai/oauth/claude-code-client-metadata' => array(
			'name' => 'Claude Code',
			'redirect_uris' => array('http://localhost/callback', 'http://127.0.0.1/callback'),
		),
	);

	private const CACHE_TTL = 86400;

	private HttpClientInterface $httpClient;

	/**
	 * @param CacheInterface           $cache      Zwischenspeicher für die Dokumente (cache.app)
	 * @param LoggerInterface          $logger     Meldet unbrauchbare Dokumente
	 * @param HttpClientInterface|null $httpClient Für den Abruf; ohne Angabe ein eigener Client
	 */
	public function __construct(
		private readonly CacheInterface $cache,
		private readonly LoggerInterface $logger,
		?HttpClientInterface $httpClient = null,
	) {
		// Contao registriert den Dienst http_client nicht in jeder Fassung
		$this->httpClient = $httpClient ?? HttpClient::create();
	}

	/**
	 * Sucht einen zugelassenen Client.
	 *
	 * @param string $clientId Der Parameter client_id
	 *
	 * @return OAuthClient|null Der Client, oder null, wenn er nicht auf der Positivliste steht
	 */
	public function find(string $clientId): ?OAuthClient
	{
		if (!isset(self::CLIENTS[$clientId])) {
			return null;
		}

		$known = self::CLIENTS[$clientId];
		$redirectUris = $this->cache->get('contao_claude.cimd.'.sha1($clientId), function (ItemInterface $item) use ($clientId, $known): array {
			$item->expiresAfter(self::CACHE_TTL);

			return $this->fetchRedirectUris($clientId) ?? $known['redirect_uris'];
		});

		return new OAuthClient($clientId, $known['name'], $redirectUris);
	}

	/**
	 * Ruft das Metadatendokument ab und prüft es.
	 *
	 * Verlangt werden: Status 200, eine client_id gleich der Adresse, die
	 * Anmeldung ohne Geheimnis („none“) und Rücksprungadressen, die entweder
	 * auf den eigenen Rechner zeigen oder per HTTPS auf denselben Host wie
	 * das Dokument. Andere Adressen fallen heraus.
	 *
	 * @param string $clientId Die Adresse des Dokuments
	 *
	 * @return list<string>|null Die Rücksprungadressen, oder null, wenn das
	 *                           Dokument nicht abrufbar oder nicht stimmig ist
	 */
	private function fetchRedirectUris(string $clientId): ?array
	{
		try {
			$response = $this->httpClient->request('GET', $clientId, array('timeout' => 5, 'max_redirects' => 0, 'headers' => array('Accept' => 'application/json')));

			if (200 !== $response->getStatusCode()) {
				throw new \RuntimeException('HTTP '.$response->getStatusCode());
			}

			$document = json_decode($response->getContent(), true, 8, JSON_THROW_ON_ERROR);
		} catch (\Throwable $exception) {
			$this->logger->warning('Client-Metadaten von '.$clientId.' nicht abrufbar: '.$exception->getMessage());

			return null;
		}

		if (!\is_array($document) || ($document['client_id'] ?? null) !== $clientId || 'none' !== ($document['token_endpoint_auth_method'] ?? 'none')) {
			$this->logger->warning('Client-Metadaten von '.$clientId.' sind nicht stimmig.');

			return null;
		}

		$host = (string) parse_url($clientId, PHP_URL_HOST);
		$uris = array();

		foreach ((array) ($document['redirect_uris'] ?? array()) as $uri) {
			if (\is_string($uri) && (RedirectUriMatcher::isLoopback($uri) || (str_starts_with($uri, 'https://') && parse_url($uri, PHP_URL_HOST) === $host))) {
				$uris[] = $uri;
			}
		}

		return array() === $uris ? null : $uris;
	}
}
