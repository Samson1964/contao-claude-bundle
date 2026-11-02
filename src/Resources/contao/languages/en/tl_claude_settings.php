<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_claude_settings']['server_legend'] = 'Server';
$GLOBALS['TL_LANG']['tl_claude_settings']['instructions_legend'] = 'House rules';
$GLOBALS['TL_LANG']['tl_claude_settings']['access_legend'] = 'Access';
$GLOBALS['TL_LANG']['tl_claude_settings']['test_legend'] = 'Test key (stage 1)';

$GLOBALS['TL_LANG']['tl_claude_settings']['claudeMcpEnabled'] = array('MCP server enabled', 'Without the checkmark, /mcp answers every client with "503 Service Unavailable" (kill switch).');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeInstructions'] = array('House rules for Claude', 'Passed to Claude as instructions when it connects. One rule per line.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeBlockedTables'] = array('Additionally blocked tables', 'Comma-separated, e.g. "tl_foo, tl_bar_*". A trailing asterisk blocks all tables with that prefix. Users, members, settings, versions, logs and form submissions are always blocked.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeTestUser'] = array('Back end user of the test key', 'Claude works with this user\'s permissions when it signs in with the test key. Leave empty to disable the test key.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeTestTokenNew'] = array('Create a new test key', 'Saving creates a new key; the previous one stops working. The key is shown only once.');

$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenCreated'] = 'New test key (visible only now, copy it right away): %s';
$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenStatus'] = 'A test key is set (created on %s).';
$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenMissing'] = 'There is no test key yet.';
$GLOBALS['TL_LANG']['tl_claude_settings']['endpoint'] = 'Address of the MCP endpoint: %s';
$GLOBALS['TL_LANG']['tl_claude_settings']['adminOnly'] = 'The MCP server settings are reserved for administrators.';
$GLOBALS['TL_LANG']['tl_claude_settings']['userAdmin'] = 'administrator';
$GLOBALS['TL_LANG']['tl_claude_settings']['userDisabled'] = 'disabled';
