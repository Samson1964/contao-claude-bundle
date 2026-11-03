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
use Schachbulle\ContaoClaudeBundle\OAuth\Endpoints;
use Schachbulle\ContaoClaudeBundle\OAuth\Pkce;
use Schachbulle\ContaoClaudeBundle\OAuth\RedirectUriMatcher;
use Schachbulle\ContaoClaudeBundle\OAuth\Scopes;

/**
 * Prüft PKCE, Scopes, Rücksprungadressen und den Adressvergleich.
 */
class PkceAndScopesTest extends TestCase
{
	/**
	 * Das Beispiel aus RFC 7636 Anhang B.
	 */
	public function testPkceMatchesRfcExample(): void
	{
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

		$this->assertSame($challenge, Pkce::challengeFor($verifier));
		$this->assertTrue(Pkce::verify($verifier, $challenge));
		$this->assertFalse(Pkce::verify($verifier.'x', $challenge));
		$this->assertFalse(Pkce::verify('zu-kurz', Pkce::challengeFor('zu-kurz')));
		$this->assertFalse(Pkce::isValidChallenge('plain-ist-nicht-erlaubt'));
	}

	/**
	 * Ohne freigegebenes Schreiben gibt es nur contao:read; Unbekanntes fällt
	 * still heraus.
	 */
	public function testScopes(): void
	{
		$this->assertSame(array('contao:read'), Scopes::grant('contao:read offline_access'));
		$this->assertSame(array('contao:read'), Scopes::grant('contao:write'));
		$this->assertSame(array('contao:read'), Scopes::grant(null));
		$this->assertSame(array('a', 'b'), Scopes::split(' a  b '));
	}

	/**
	 * Mit freigegebenem Schreiben: contao:write nur auf Anfrage oder ohne
	 * Angabe, Lesen immer dabei.
	 */
	public function testScopesWithWriteEnabled(): void
	{
		$this->assertSame(array('contao:read', 'contao:write'), Scopes::grantable(true));
		$this->assertSame(array('contao:read', 'contao:write'), Scopes::grant(null, true));
		$this->assertSame(array('contao:read', 'contao:write'), Scopes::grant('contao:read contao:write', true));
		$this->assertSame(array('contao:read', 'contao:write'), Scopes::grant('contao:write', true));
		$this->assertSame(array('contao:read'), Scopes::grant('contao:read', true));
	}

	/**
	 * HTTPS-Adressen müssen exakt passen.
	 */
	public function testExactRedirect(): void
	{
		$registered = array('https://claude.ai/api/mcp/auth_callback');

		$this->assertTrue(RedirectUriMatcher::matches($registered, 'https://claude.ai/api/mcp/auth_callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'https://claude.ai/api/mcp/auth_callback/'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'https://claude.ai.example.org/api/mcp/auth_callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'https://claude.ai:8443/api/mcp/auth_callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'https://claude.ai/api/mcp/auth_callback#x'));
	}

	/**
	 * Rücksprünge auf den eigenen Rechner gelten mit jedem Port, aber nur mit
	 * gleichem Host und Pfad.
	 */
	public function testLoopbackRedirect(): void
	{
		$registered = array('http://localhost/callback', 'http://127.0.0.1/callback');

		$this->assertTrue(RedirectUriMatcher::matches($registered, 'http://localhost:3118/callback'));
		$this->assertTrue(RedirectUriMatcher::matches($registered, 'http://127.0.0.1:61234/callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'http://127.0.0.1:61234/anders'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'http://[::1]:61234/callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'https://localhost:3118/callback'));
		$this->assertFalse(RedirectUriMatcher::matches($registered, 'http://localhost.example.org:3118/callback'));
		$this->assertTrue(RedirectUriMatcher::matches(array('http://[::1]/callback'), 'http://[::1]:5000/callback'));
	}

	/**
	 * Adressen werden ohne Rücksicht auf Schreibweise und Schrägstrich verglichen.
	 */
	public function testNormalize(): void
	{
		$this->assertSame('https://www.example.org/mcp', Endpoints::normalize('HTTPS://WWW.Example.org/mcp/'));
		$this->assertSame('http://127.0.0.1:8160/mcp', Endpoints::normalize('http://127.0.0.1:8160/mcp'));
		$this->assertSame('', Endpoints::normalize('keine adresse'));
	}
}
