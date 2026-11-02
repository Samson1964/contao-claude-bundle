<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\OAuth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\OAuth\ClientRegistry;
use Schachbulle\ContaoClaudeBundle\OAuth\Endpoints;
use Schachbulle\ContaoClaudeBundle\OAuth\GrantRepository;
use Schachbulle\ContaoClaudeBundle\OAuth\OAuthClient;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Gemeinsame Grundlage der OAuth-Tests mit SQLite im Arbeitsspeicher.
 *
 * Angelegt werden tl_claude_token wie in der DCA und ein schmales tl_user
 * mit drei Benutzern: 1 Redakteur in Gruppe 3, 2 Administrator,
 * 3 deaktivierter Redakteur.
 */
abstract class OAuthTestCase extends TestCase
{
	protected const RESOURCE = 'https://example.org/mcp';
	protected const CODE_CLIENT = 'https://claude.ai/oauth/claude-code-client-metadata';
	protected const REDIRECT = 'http://127.0.0.1:61234/callback';
	protected const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	protected const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	protected Connection $connection;
	protected GrantRepository $grants;
	protected AuthorizationServer $server;

	/**
	 * @var array<string, mixed> Einstellungen, die die Attrappe liefert
	 */
	protected array $settingValues = array('allowAdmins' => false, 'groups' => array(), 'enabled' => true);

	/**
	 * Legt Datenbank und Dienste an.
	 */
	protected function setUp(): void
	{
		$this->connection = DriverManager::getConnection(array('driver' => 'pdo_sqlite', 'memory' => true));
		$this->connection->executeStatement("CREATE TABLE tl_claude_token (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, userId INTEGER NOT NULL DEFAULT 0, clientId TEXT NOT NULL DEFAULT '', clientName TEXT NOT NULL DEFAULT '', scope TEXT NOT NULL DEFAULT '', resource TEXT NOT NULL DEFAULT '', redirectUri TEXT NOT NULL DEFAULT '', codeHash TEXT NOT NULL DEFAULT '', codeChallenge TEXT NOT NULL DEFAULT '', codeExpires INTEGER NOT NULL DEFAULT 0, codeUsed TEXT NOT NULL DEFAULT '', accessHash TEXT NOT NULL DEFAULT '', accessExpires INTEGER NOT NULL DEFAULT 0, refreshHash TEXT NOT NULL DEFAULT '', refreshExpires INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL DEFAULT 0, lastUsed INTEGER NOT NULL DEFAULT 0, revoked TEXT NOT NULL DEFAULT '')");
		$this->connection->executeStatement("CREATE TABLE tl_user (id INTEGER PRIMARY KEY, name TEXT, username TEXT, admin TEXT NOT NULL DEFAULT '', disable TEXT NOT NULL DEFAULT '', start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '', locked INTEGER NOT NULL DEFAULT 0, `groups` BLOB)");
		$this->connection->insert('tl_user', array('id' => 1, 'name' => 'Rita Redakteur', 'username' => 'rita', 'groups' => serialize(array('3'))));
		$this->connection->insert('tl_user', array('id' => 2, 'name' => 'Anton Admin', 'username' => 'anton', 'admin' => '1', 'groups' => serialize(array())));
		$this->connection->insert('tl_user', array('id' => 3, 'name' => 'Dora Deaktiviert', 'username' => 'dora', 'disable' => '1', 'groups' => serialize(array('3'))));

		$this->grants = new GrantRepository($this->connection);
		$this->server = new AuthorizationServer($this->grants, new ConnectionPolicy($this->connection, $this->settings()), $this->endpoints());
	}

	/**
	 * Einstellungen als Attrappe, gesteuert über $settingValues.
	 *
	 * @return Settings Die Attrappe
	 */
	protected function settings(): Settings
	{
		$settings = $this->createMock(Settings::class);
		$settings->method('allowsAdmins')->willReturnCallback(fn (): bool => $this->settingValues['allowAdmins']);
		$settings->method('getAllowedGroups')->willReturnCallback(fn (): array => $this->settingValues['groups']);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->settingValues['enabled']);

		return $settings;
	}

	/**
	 * Adressen als Attrappe für https://example.org.
	 *
	 * @return Endpoints Die Attrappe
	 */
	protected function endpoints(): Endpoints
	{
		$endpoints = $this->createMock(Endpoints::class);
		$endpoints->method('getIssuer')->willReturn('https://example.org');
		$endpoints->method('getResource')->willReturn(self::RESOURCE);
		$endpoints->method('getAuthorizationEndpoint')->willReturn('https://example.org/contao/mcp/authorize');
		$endpoints->method('isOwnResource')->willReturnCallback(static fn (string $resource): bool => Endpoints::normalize($resource) === self::RESOURCE);

		return $endpoints;
	}

	/**
	 * Die Positivliste mit den hinterlegten Werten (Abruf schlägt fehl).
	 *
	 * @return ClientRegistry Die Positivliste
	 */
	protected function registry(): ClientRegistry
	{
		return new ClientRegistry(new ArrayAdapter(), new NullLogger(), new MockHttpClient(new MockResponse('', array('http_code' => 404))));
	}

	/**
	 * Der Client Claude Code.
	 *
	 * @return OAuthClient Der Client
	 */
	protected function codeClient(): OAuthClient
	{
		return $this->registry()->find(self::CODE_CLIENT);
	}

	/**
	 * Stellt einen Code für Benutzer 1 aus.
	 *
	 * @param int $now Zeitpunkt der Ausstellung
	 *
	 * @return string Der Code
	 */
	protected function issueCode(int $now = 0): string
	{
		return $this->server->issueCode(1, $this->codeClient(), self::REDIRECT, self::CHALLENGE, array('contao:read'), self::RESOURCE, $now ?: time());
	}

	/**
	 * Die Felder eines gültigen Code-Tauschs.
	 *
	 * @param string $code Der Code
	 *
	 * @return array<string, string> Die Formularfelder
	 */
	protected function exchangeParams(string $code): array
	{
		return array('grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => self::CODE_CLIENT, 'code_verifier' => self::VERIFIER, 'resource' => self::RESOURCE);
	}
}
