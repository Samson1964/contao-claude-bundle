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
 * Vergleicht eine angefragte Rücksprungadresse mit den registrierten.
 *
 * Grundsätzlich exakt, Zeichen für Zeichen. Ausnahme: Rücksprünge auf den
 * eigenen Rechner (http://localhost, http://127.0.0.1, http://[::1]) gelten
 * unabhängig vom Port, weil Claude Code bei jeder Anmeldung einen freien
 * Port wählt (RFC 8252 Abschnitt 7.3). Anthropic verlangt das ausdrücklich
 * auch für „localhost“, weil Claude Code beide Schreibweisen anmeldet.
 */
final class RedirectUriMatcher
{
	private const LOOPBACK_HOSTS = array('localhost', '127.0.0.1', '[::1]');

	/**
	 * Prüft, ob eine angefragte Adresse zu einer registrierten passt.
	 *
	 * @param list<string> $registered Die registrierten Adressen des Clients
	 * @param string       $requested  Der Parameter redirect_uri der Anfrage
	 *
	 * @return bool true, wenn eine registrierte Adresse passt
	 */
	public static function matches(array $registered, string $requested): bool
	{
		if ('' === $requested || str_contains($requested, '#')) {
			return false;
		}

		foreach ($registered as $uri) {
			if ($uri === $requested) {
				return true;
			}

			if (self::isLoopback($uri) && self::isLoopback($requested) && self::withoutPort($uri) === self::withoutPort($requested)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prüft, ob eine Adresse auf den eigenen Rechner zeigt.
	 *
	 * Nur http zählt: Ein lokaler Rücksprung braucht kein TLS, und https auf
	 * localhost kommt in der Praxis nicht vor.
	 *
	 * @param string $uri Die Adresse
	 *
	 * @return bool true für http://localhost, http://127.0.0.1 und http://[::1]
	 */
	public static function isLoopback(string $uri): bool
	{
		$parts = parse_url($uri);

		if (!\is_array($parts) || 'http' !== strtolower($parts['scheme'] ?? '')) {
			return false;
		}

		$host = strtolower((string) ($parts['host'] ?? ''));

		return \in_array($host, self::LOOPBACK_HOSTS, true) || \in_array('['.$host.']', self::LOOPBACK_HOSTS, true);
	}

	/**
	 * Entfernt den Port aus einer Adresse, alles andere bleibt.
	 *
	 * @param string $uri Die Adresse
	 *
	 * @return string Schema, Host, Pfad und Query ohne Port
	 */
	private static function withoutPort(string $uri): string
	{
		$parts = parse_url($uri) ?: array();
		$host = strtolower((string) ($parts['host'] ?? ''));

		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '['.$host.']';
		}

		return strtolower((string) ($parts['scheme'] ?? '')).'://'.$host.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
	}
}
