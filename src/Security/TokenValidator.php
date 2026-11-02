<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Prüft den Bearer-Schlüssel einer Anfrage an /mcp.
 *
 * Ausbaustufe 1 kennt nur den Testschlüssel aus den Einstellungen; er trägt
 * ausschließlich Leserechte. Mit der Anmeldung über OAuth (Stufe 2) kommen
 * die Zugriffsschlüssel aus tl_claude_token hinzu – dieselbe Schnittstelle,
 * nur eine zweite Quelle.
 */
class TokenValidator
{
	/**
	 * @param Settings $settings Liefert Hash und Benutzer des Testschlüssels
	 */
	public function __construct(private readonly Settings $settings)
	{
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
	 * Der Vergleich läuft über hash_equals() auf den Hashes, damit die
	 * Antwortzeit nichts über übereinstimmende Anfangszeichen verrät. Ohne
	 * gewählten Testbenutzer gilt der Testschlüssel als abgeschaltet.
	 *
	 * @param string $token Der Schlüssel aus dem Header
	 *
	 * @return AuthenticatedClient|null Der zugehörige Zugang, oder null, wenn
	 *                                  der Schlüssel unbekannt oder abgeschaltet ist
	 */
	public function validate(string $token): ?AuthenticatedClient
	{
		$hash = $this->settings->getTestTokenHash();
		$userId = $this->settings->getTestUserId();

		if (null === $hash || $userId < 1 || '' === $token) {
			return null;
		}

		if (!hash_equals($hash, Settings::hashToken($token))) {
			return null;
		}

		return new AuthenticatedClient($userId, array(AuthenticatedClient::SCOPE_READ), 'Testschlüssel');
	}
}
