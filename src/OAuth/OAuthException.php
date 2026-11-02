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
 * Ein OAuth-Fehler mit Code nach RFC 6749 Abschnitt 5.2.
 *
 * Der Code (etwa „invalid_grant“) geht als „error“ an den Client; Claude
 * wertet ihn aus und erneuert z. B. nur bei invalid_grant nicht mehr. Die
 * Beschreibung ist für Menschen und darf deshalb deutsch sein.
 */
final class OAuthException extends \RuntimeException
{
	/**
	 * @param string $error       Fehlercode, etwa „invalid_request“ oder „invalid_grant“
	 * @param string $description Erklärung für Menschen
	 * @param int    $status      HTTP-Status der Antwort, meist 400
	 */
	public function __construct(
		public readonly string $error,
		string $description,
		public readonly int $status = 400,
	) {
		parent::__construct($description);
	}
}
