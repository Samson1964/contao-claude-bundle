<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Schachbulle\ContaoClaudeBundle\Backend\ConnectionsModule;

/*
 * Backend-Module unter System.
 *
 * „MCP-Server“ (Einstellungen) ist Administratoren vorbehalten.
 * „Claude-Verbindungen“ folgt den normalen Modulrechten: Wer Claude nutzen
 * soll, bekommt das Modul über seine Benutzergruppe und sieht dort seine
 * eigenen Verbindungen; Administratoren sehen alle.
 */
$GLOBALS['BE_MOD']['system']['claude_mcp'] = array
(
	'tables' => array('tl_claude_settings'),
);

$GLOBALS['BE_MOD']['system']['claude_connections'] = array
(
	'callback' => ConnectionsModule::class,
);

$GLOBALS['BE_MOD']['system']['claude_log'] = array
(
	'tables' => array('tl_claude_log'),
);
