<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\OAuth;

use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\OAuth\OAuthException;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;

/**
 * Prüft Code-Tausch, Erneuerung, Widerruf, Schlüsselprüfung und Zugangsregel.
 */
class AuthorizationServerTest extends OAuthTestCase
{
	/**
	 * Der normale Ablauf: Code → Schlüssel → Zugriff.
	 */
	public function testExchangeAndValidate(): void
	{
		$tokens = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());

		$this->assertSame('Bearer', $tokens['token_type']);
		$this->assertSame(3600, $tokens['expires_in']);
		$this->assertSame('contao:read', $tokens['scope']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $tokens['access_token']);

		$client = $this->server->validateAccessToken((string) $tokens['access_token'], time());

		$this->assertNotNull($client);
		$this->assertSame(1, $client->userId);
		$this->assertSame('Claude Code', $client->clientName);
		$this->assertTrue($client->isOAuth());

		// In der Datenbank stehen nur Hashes
		$row = $this->connection->fetchAssociative('SELECT * FROM tl_claude_token');
		$this->assertStringNotContainsString((string) $tokens['access_token'], (string) json_encode($row));
	}

	/**
	 * Ein Code gilt einmal; der zweite Versuch widerruft die Verbindung.
	 */
	public function testCodeReuseRevokesGrant(): void
	{
		$code = $this->issueCode();
		$tokens = $this->server->exchangeCode($this->exchangeParams($code), time());

		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode($this->exchangeParams($code), time()));
		$this->assertNull($this->server->validateAccessToken((string) $tokens['access_token'], time()));
	}

	/**
	 * Falscher Verifier, falsche Rücksprungadresse, fremder Client, abgelaufen.
	 */
	public function testExchangeRejections(): void
	{
		$params = $this->exchangeParams($this->issueCode());

		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode(array('code_verifier' => str_repeat('a', 43)) + $params, time()));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode(array('redirect_uri' => 'http://127.0.0.1:9/callback') + $params, time()));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode(array('client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata') + $params, time()));
		$this->assertOAuthError('invalid_target', fn () => $this->server->exchangeCode(array('resource' => 'https://anders.example.org/mcp') + $params, time()));
		$this->assertOAuthError('invalid_request', fn () => $this->server->exchangeCode(array('code_verifier' => '') + $params, time()));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode($params, time() + AuthorizationServer::CODE_TTL + 1));
	}

	/**
	 * Erneuern dreht beide Schlüssel; der alte Erneuerungsschlüssel ist danach ungültig.
	 */
	public function testRefreshRotation(): void
	{
		$first = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());
		$second = $this->server->refresh(array('refresh_token' => (string) $first['refresh_token'], 'client_id' => self::CODE_CLIENT), time());

		$this->assertNotSame($first['refresh_token'], $second['refresh_token']);
		$this->assertNull($this->server->validateAccessToken((string) $first['access_token'], time()));
		$this->assertNotNull($this->server->validateAccessToken((string) $second['access_token'], time()));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->refresh(array('refresh_token' => (string) $first['refresh_token'], 'client_id' => self::CODE_CLIENT), time()));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->refresh(array('refresh_token' => (string) $second['refresh_token'], 'client_id' => 'https://claude.ai/oauth/mcp-oauth-client-metadata'), time()));
		$this->assertOAuthError('invalid_scope', fn () => $this->server->refresh(array('refresh_token' => (string) $second['refresh_token'], 'client_id' => self::CODE_CLIENT, 'scope' => 'contao:write'), time()));
	}

	/**
	 * Zugriffsschlüssel laufen nach einer Stunde ab, Erneuerung nach 30 Tagen.
	 */
	public function testExpiry(): void
	{
		$tokens = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());

		$this->assertNull($this->server->validateAccessToken((string) $tokens['access_token'], time() + 3601));
		$this->assertOAuthError('invalid_grant', fn () => $this->server->refresh(array('refresh_token' => (string) $tokens['refresh_token'], 'client_id' => self::CODE_CLIENT), time() + AuthorizationServer::REFRESH_TTL + 1));
	}

	/**
	 * Widerruf über den Erneuerungsschlüssel beendet die ganze Verbindung.
	 */
	public function testRevoke(): void
	{
		$tokens = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());

		$this->server->revoke((string) $tokens['refresh_token'], 'https://andere.example.org');
		$this->assertNotNull($this->server->validateAccessToken((string) $tokens['access_token'], time()), 'fremde client_id widerruft nicht');

		$this->server->revoke((string) $tokens['refresh_token'], '');
		$this->assertNull($this->server->validateAccessToken((string) $tokens['access_token'], time()));
		$this->server->revoke('unbekannt', '');
	}

	/**
	 * Ein Schlüssel für eine andere Ressource gilt hier nicht.
	 */
	public function testOtherResource(): void
	{
		$code = $this->server->issueCode(1, $this->codeClient(), self::REDIRECT, self::CHALLENGE, array('contao:read'), 'https://anders.example.org/mcp', time());
		$params = array('resource' => '') + $this->exchangeParams($code);
		$tokens = $this->server->exchangeCode($params, time());

		$this->assertNull($this->server->validateAccessToken((string) $tokens['access_token'], time()));
	}

	/**
	 * Die Zugangsregel greift beim Tausch und bei der Erneuerung.
	 */
	public function testPolicyOnExchangeAndRefresh(): void
	{
		$tokens = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());

		// Gruppe 3 erlaubt: Rita (Gruppe 3) darf
		$this->settingValues['groups'] = array(3);
		$this->assertArrayHasKey('access_token', $this->server->refresh(array('refresh_token' => (string) $tokens['refresh_token'], 'client_id' => self::CODE_CLIENT), time()));

		// Nur Gruppe 4 erlaubt: Rita nicht mehr
		$this->settingValues['groups'] = array(4);
		$code = $this->issueCode();
		$this->assertOAuthError('invalid_grant', fn () => $this->server->exchangeCode($this->exchangeParams($code), time()));
	}

	/**
	 * Regeln: Administratoren nur per Schalter, Deaktivierte nie, Gruppen wahlweise.
	 */
	public function testConnectionPolicy(): void
	{
		$policy = new ConnectionPolicy($this->connection, $this->settings());

		$this->assertNull($policy->check(1));
		$this->assertStringContainsString('Administratoren', (string) $policy->check(2));
		$this->assertStringContainsString('deaktiviert', (string) $policy->check(3));
		$this->assertNotNull($policy->check(99));

		$this->settingValues['allowAdmins'] = true;
		$this->assertNull($policy->check(2));

		$this->settingValues['groups'] = array(5);
		$this->assertStringContainsString('Gruppe', (string) $policy->check(1));
		$this->assertNull($policy->check(2), 'Gruppen gelten nicht für Administratoren');
	}

	/**
	 * Unter Contao 5 fehlt tl_user.locked; die Regel muss trotzdem greifen.
	 */
	public function testConnectionPolicyWithoutLockedColumn(): void
	{
		$this->connection->executeStatement('CREATE TABLE tl_user_57 AS SELECT id, name, username, admin, disable, start, stop, `groups` FROM tl_user');
		$this->connection->executeStatement('DROP TABLE tl_user');
		$this->connection->executeStatement('ALTER TABLE tl_user_57 RENAME TO tl_user');

		$policy = new ConnectionPolicy($this->connection, $this->settings());

		$this->assertNull($policy->check(1));
		$this->assertStringContainsString('deaktiviert', (string) $policy->check(3));
	}

	/**
	 * Die Bereinigung entfernt alte Codes und Verbindungen gelöschter Benutzer.
	 */
	public function testCleanup(): void
	{
		$this->issueCode(time() - 2 * 86400);
		$this->server->issueCode(42, $this->codeClient(), self::REDIRECT, self::CHALLENGE, array('contao:read'), self::RESOURCE, time());
		$this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());

		$this->assertSame(2, $this->grants->cleanup(time()));
		$this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_claude_token'));
		$this->assertCount(1, $this->grants->listForBackend(1));
		$this->assertCount(0, $this->grants->listForBackend(2));
	}

	/**
	 * Prüft, dass ein Aufruf mit einem bestimmten OAuth-Fehler scheitert.
	 *
	 * @param string   $error    Der erwartete Fehlercode
	 * @param callable $callback Der Aufruf
	 */
	private function assertOAuthError(string $error, callable $callback): void
	{
		try {
			$callback();
			$this->fail('Erwartet: '.$error);
		} catch (OAuthException $exception) {
			$this->assertSame($error, $exception->error, $exception->getMessage());
		}
	}
}
