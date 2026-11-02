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
 * Ein zugelassener OAuth-Client.
 */
final class OAuthClient
{
	/**
	 * @param string       $id           Die client_id, bei Claude eine HTTPS-Adresse
	 * @param string       $name         Anzeigename aus der Positivliste, nicht aus dem Dokument
	 * @param list<string> $redirectUris Zulässige Rücksprungadressen
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $redirectUris,
	) {
	}

	/**
	 * Liefert den Host der client_id für die Bestätigungsseite.
	 *
	 * Die MCP-Spezifikation verlangt, auf der Bestätigungsseite den Host der
	 * client_id zu nennen, weil der Name im Dokument selbst behauptet ist.
	 *
	 * @return string Etwa „claude.ai“
	 */
	public function getHost(): string
	{
		return (string) parse_url($this->id, PHP_URL_HOST);
	}
}
