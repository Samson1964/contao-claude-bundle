<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_claude_log']['tstamp'] = array('Time', 'Time of the call.');
$GLOBALS['TL_LANG']['tl_claude_log']['username'] = array('User', 'Back end account whose permissions Claude used.');
$GLOBALS['TL_LANG']['tl_claude_log']['clientName'] = array('Connection', 'Claude, Claude Code or test key.');
$GLOBALS['TL_LANG']['tl_claude_log']['tool'] = array('Tool', 'Tool that was called.');
$GLOBALS['TL_LANG']['tl_claude_log']['tableName'] = array('Table', 'Affected table.');
$GLOBALS['TL_LANG']['tl_claude_log']['recordId'] = array('ID', 'ID of the record, 0 if none was created.');
$GLOBALS['TL_LANG']['tl_claude_log']['fields'] = array('Fields', 'Names of the submitted fields; the record versions show the values.');
$GLOBALS['TL_LANG']['tl_claude_log']['result'] = array('Result', '"ok" or "abgewiesen" (rejected).');
$GLOBALS['TL_LANG']['tl_claude_log']['message'] = array('Message', 'Reason for rejected attempts.');
