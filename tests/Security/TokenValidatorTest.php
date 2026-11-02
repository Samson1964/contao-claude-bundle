<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Security;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;
use Schachbulle\ContaoClaudeBundle\Security\TokenValidator;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Prüft das Auslesen und Prüfen des Bearer-Schlüssels.
 */
class TokenValidatorTest extends TestCase
{
	/**
	 * Das Schema „Bearer“ wird ohne Rücksicht auf die Schreibweise erkannt.
	 */
	public function testExtractBearer(): void
	{
		$this->assertSame('abc-123', TokenValidator::extractBearer('Bearer abc-123'));
		$this->assertSame('abc-123', TokenValidator::extractBearer('  bearer   abc-123 '));
		$this->assertNull(TokenValidator::extractBearer(null));
		$this->assertNull(TokenValidator::extractBearer('Basic dXNlcjpwYXNz'));
		$this->assertNull(TokenValidator::extractBearer('Bearer'));
		$this->assertNull(TokenValidator::extractBearer('Bearer zwei teile'));
	}

	/**
	 * Der richtige Schlüssel liefert den Testbenutzer mit Leserecht.
	 */
	public function testValidTokenYieldsReadClient(): void
	{
		$client = $this->validator('geheim', 42)->validate('geheim');

		$this->assertInstanceOf(AuthenticatedClient::class, $client);
		$this->assertSame(42, $client->userId);
		$this->assertTrue($client->hasScope(AuthenticatedClient::SCOPE_READ));
		$this->assertFalse($client->hasScope(AuthenticatedClient::SCOPE_WRITE));
	}

	/**
	 * Ein falscher Schlüssel wird abgewiesen.
	 */
	public function testWrongTokenIsRejected(): void
	{
		$this->assertNull($this->validator('geheim', 42)->validate('falsch'));
		$this->assertNull($this->validator('geheim', 42)->validate(''));
	}

	/**
	 * Ohne Testbenutzer gilt der Schlüssel als abgeschaltet.
	 */
	public function testTokenWithoutUserIsRejected(): void
	{
		$this->assertNull($this->validator('geheim', 0)->validate('geheim'));
	}

	/**
	 * Ohne gespeicherten Hash wird nichts akzeptiert.
	 */
	public function testNoStoredHash(): void
	{
		$settings = $this->createMock(Settings::class);
		$settings->method('getTestTokenHash')->willReturn(null);
		$settings->method('getTestUserId')->willReturn(42);

		$this->assertNull((new TokenValidator($settings, $this->oauth()))->validate('geheim'));
	}

	/**
	 * Ist es nicht der Testschlüssel, entscheidet der OAuth-Server.
	 */
	public function testFallsBackToOAuth(): void
	{
		$client = $this->validator('geheim', 42)->validate('oauth-schluessel');

		$this->assertNotNull($client);
		$this->assertTrue($client->isOAuth());
		$this->assertSame('Claude Code', $client->clientName);
	}

	/**
	 * Baut einen Validator mit gespeichertem Testschlüssel.
	 *
	 * @param string $token  Der Schlüssel im Klartext, dessen Hash gespeichert ist
	 * @param int    $userId Der Testbenutzer, 0 für „keiner“
	 *
	 * @return TokenValidator Der Validator
	 */
	private function validator(string $token, int $userId): TokenValidator
	{
		$settings = $this->createMock(Settings::class);
		$settings->method('getTestTokenHash')->willReturn(Settings::hashToken($token));
		$settings->method('getTestUserId')->willReturn($userId);

		return new TokenValidator($settings, $this->oauth());
	}

	/**
	 * Baut einen OAuth-Server, der nur „oauth-schluessel“ kennt.
	 *
	 * @return AuthorizationServer Die Attrappe
	 */
	private function oauth(): AuthorizationServer
	{
		$server = $this->createMock(AuthorizationServer::class);
		$server->method('validateAccessToken')->willReturnCallback(static fn (string $token): ?AuthenticatedClient => 'oauth-schluessel' === $token ? new AuthenticatedClient(7, array(AuthenticatedClient::SCOPE_READ), 'Claude Code', 3) : null);

		return $server;
	}
}
