<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

/**
 * Der Schlüssel war gültig, sein Backend-Benutzer lässt sich aber nicht
 * anmelden (gelöscht, deaktiviert, abgelaufen oder gesperrt).
 *
 * Die Meldung ist für den Client bestimmt und nennt deshalb keine Namen.
 */
final class LoginFailedException extends \RuntimeException
{
}
