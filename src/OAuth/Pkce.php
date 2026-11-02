<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

/**
 * PKCE nach RFC 7636, nur das Verfahren S256.
 *
 * OAuth 2.1 und die MCP-Spezifikation verlangen PKCE für jeden Client;
 * „plain“ wird bewusst nicht unterstützt.
 */
final class Pkce
{
	/**
	 * Prüft die Schreibweise einer Code-Challenge.
	 *
	 * Eine S256-Challenge ist ein base64url-kodierter SHA-256-Hash ohne
	 * Auffüllzeichen und damit genau 43 Zeichen lang.
	 *
	 * @param string $challenge Der Wert von code_challenge
	 *
	 * @return bool true bei gültiger Schreibweise
	 */
	public static function isValidChallenge(string $challenge): bool
	{
		return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
	}

	/**
	 * Prüft den Code-Verifier gegen die gespeicherte Challenge.
	 *
	 * Der Verifier muss 43 bis 128 Zeichen aus dem Alphabet „unreserved“
	 * haben (RFC 7636 Abschnitt 4.1). Verglichen wird zeitkonstant.
	 *
	 * @param string $verifier  Der Wert von code_verifier aus der Token-Anfrage
	 * @param string $challenge Die bei /authorize gespeicherte Challenge
	 *
	 * @return bool true, wenn der Verifier zur Challenge gehört
	 */
	public static function verify(string $verifier, string $challenge): bool
	{
		if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !self::isValidChallenge($challenge)) {
			return false;
		}

		return hash_equals($challenge, self::challengeFor($verifier));
	}

	/**
	 * Berechnet die S256-Challenge zu einem Verifier.
	 *
	 * @param string $verifier Der Code-Verifier
	 *
	 * @return string base64url(SHA-256(verifier)) ohne Auffüllzeichen
	 */
	public static function challengeFor(string $verifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}
}
