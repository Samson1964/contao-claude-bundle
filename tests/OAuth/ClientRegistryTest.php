<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Schachbulle\ContaoClaudeBundle\OAuth\ClientRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Prüft die Positivliste und den Abruf der Metadatendokumente.
 */
class ClientRegistryTest extends TestCase
{
	private const CODE = 'https://claude.ai/oauth/claude-code-client-metadata';

	/**
	 * Fremde client_ids werden gar nicht erst abgerufen.
	 */
	public function testUnknownClientIsNotFetched(): void
	{
		$requests = 0;
		$http = new MockHttpClient(static function () use (&$requests): MockResponse {
			++$requests;

			return new MockResponse('{}');
		});

		$this->assertNull($this->registry($http)->find('https://evil.example.org/client.json'));
		$this->assertSame(0, $requests);
	}

	/**
	 * Ein stimmiges Dokument liefert seine Adressen; fremde HTTPS-Hosts fallen heraus.
	 */
	public function testValidDocument(): void
	{
		$document = array(
			'client_id' => self::CODE,
			'client_name' => 'Selbst behauptet',
			'redirect_uris' => array('http://localhost/callback', 'https://claude.ai/neu', 'https://evil.example.org/cb'),
			'token_endpoint_auth_method' => 'none',
		);

		$client = $this->registry(new MockHttpClient(new MockResponse((string) json_encode($document))))->find(self::CODE);

		$this->assertNotNull($client);
		$this->assertSame('Claude Code', $client->name);
		$this->assertSame('claude.ai', $client->getHost());
		$this->assertSame(array('http://localhost/callback', 'https://claude.ai/neu'), $client->redirectUris);
	}

	/**
	 * Nicht erreichbar oder nicht stimmig: Es gelten die hinterlegten Werte.
	 */
	public function testFallback(): void
	{
		$fallback = ClientRegistry::CLIENTS[self::CODE]['redirect_uris'];

		$this->assertSame($fallback, $this->registry(new MockHttpClient(new MockResponse('', array('http_code' => 503))))->find(self::CODE)->redirectUris);
		$this->assertSame($fallback, $this->registry(new MockHttpClient(new MockResponse('kein json')))->find(self::CODE)->redirectUris);
		$this->assertSame($fallback, $this->registry(new MockHttpClient(new MockResponse((string) json_encode(array('client_id' => 'https://anders.example.org', 'redirect_uris' => array('http://localhost/x'))))))->find(self::CODE)->redirectUris);
		$this->assertSame($fallback, $this->registry(new MockHttpClient(new MockResponse((string) json_encode(array('client_id' => self::CODE, 'token_endpoint_auth_method' => 'client_secret_post', 'redirect_uris' => array('http://localhost/x'))))))->find(self::CODE)->redirectUris);
	}

	/**
	 * Das Dokument wird nur einmal abgerufen.
	 */
	public function testCaching(): void
	{
		$requests = 0;
		$http = new MockHttpClient(static function () use (&$requests): MockResponse {
			++$requests;

			return new MockResponse((string) json_encode(array('client_id' => self::CODE, 'redirect_uris' => array('http://localhost/callback'))));
		});

		$registry = $this->registry($http);
		$registry->find(self::CODE);
		$registry->find(self::CODE);

		$this->assertSame(1, $requests);
	}

	/**
	 * Baut die Positivliste mit einem Attrappen-Client.
	 *
	 * @param MockHttpClient $http Liefert die Antworten
	 *
	 * @return ClientRegistry Die Positivliste
	 */
	private function registry(MockHttpClient $http): ClientRegistry
	{
		return new ClientRegistry(new ArrayAdapter(), new NullLogger(), $http);
	}
}
