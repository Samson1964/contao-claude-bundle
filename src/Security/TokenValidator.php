<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

use Schachbulle\ContaoClaudeBundle\OAuth\AuthorizationServer;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Prüft den Bearer-Schlüssel einer Anfrage an /mcp.
 *
 * Zwei Quellen: der Testschlüssel aus den Einstellungen (Ausbaustufe 1,
 * nur Leserechte, für Claude Code mit festem Header und den MCP Inspector)
 * und die Zugriffsschlüssel aus der OAuth-Anmeldung (tl_claude_token).
 */
class TokenValidator
{
	/**
	 * @param Settings            $settings            Liefert Hash und Benutzer des Testschlüssels
	 * @param AuthorizationServer $authorizationServer Prüft die OAuth-Zugriffsschlüssel
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly AuthorizationServer $authorizationServer,
	) {
	}

	/**
	 * Holt den Schlüssel aus dem Authorization-Header.
	 *
	 * Das Schema „Bearer“ wird ohne Rücksicht auf Groß- und Kleinschreibung
	 * erkannt (RFC 6750, Abschnitt 2.1).
	 *
	 * @param string|null $header Der Wert des Authorization-Headers
	 *
	 * @return string|null Der Schlüssel, oder null, wenn keiner mitkam
	 */
	public static function extractBearer(?string $header): ?string
	{
		if (null === $header || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $match)) {
			return null;
		}

		return $match[1];
	}

	/**
	 * Prüft einen Schlüssel.
	 *
	 * Zuerst gegen den Testschlüssel, dann gegen die OAuth-Verbindungen.
	 * Der Vergleich mit dem Testschlüssel läuft über hash_equals() auf den
	 * Hashes, damit die Antwortzeit nichts über übereinstimmende
	 * Anfangszeichen verrät; OAuth-Schlüssel werden über ihren Hash gesucht.
	 * Ohne gewählten Testbenutzer gilt der Testschlüssel als abgeschaltet.
	 *
	 * @param string $token Der Schlüssel aus dem Header
	 *
	 * @return AuthenticatedClient|null Der zugehörige Zugang, oder null, wenn
	 *                                  der Schlüssel unbekannt, abgelaufen oder abgeschaltet ist
	 */
	public function validate(string $token): ?AuthenticatedClient
	{
		if ('' === $token) {
			return null;
		}

		$hash = $this->settings->getTestTokenHash();
		$userId = $this->settings->getTestUserId();

		// Der Testschlüssel trägt beide Scopes; ob geschrieben werden darf,
		// entscheidet wie bei OAuth-Verbindungen der Schalter in den Einstellungen
		if (null !== $hash && $userId > 0 && hash_equals($hash, Settings::hashToken($token))) {
			return new AuthenticatedClient($userId, array(AuthenticatedClient::SCOPE_READ, AuthenticatedClient::SCOPE_WRITE), 'Testschlüssel');
		}

		return $this->authorizationServer->validateAccessToken($token, time());
	}
}
