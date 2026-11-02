<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Backend-Modul „MCP-Server“ unter System.
 *
 * In der ersten Ausbaustufe enthält es nur die Einstellungen. Clients,
 * Verbindungen und Protokoll kommen mit der Anmeldung über OAuth als
 * weitere Tabellen hinzu.
 */
$GLOBALS['BE_MOD']['system']['claude_mcp'] = array
(
	'tables' => array('tl_claude_settings'),
);
