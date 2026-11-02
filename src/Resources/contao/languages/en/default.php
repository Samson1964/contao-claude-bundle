<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['MSC']['claude_connections'] = array
(
	'empty'          => 'There are no connections with Claude.',
	'user'           => 'User',
	'client'         => 'Application',
	'created'        => 'Connected on',
	'lastUsed'       => 'Last used',
	'expires'        => 'Valid until',
	'status'         => 'Status',
	'status_active'  => 'active',
	'status_expired' => 'expired',
	'status_revoked' => 'revoked',
	'revoke'         => 'Revoke',
	'confirm'        => 'Really revoke this connection? Claude will have to sign in again.',
	'revoked'        => 'The connection has been revoked.',
);
