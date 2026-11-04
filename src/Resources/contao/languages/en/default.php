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
	'help_connect'   => 'Connect Claude',
	'help_url'       => 'Connector URL:',
	'help_steps'     => array
	(
		'In claude.ai, open <em>Settings → Connectors</em> and choose “Add custom connector”.',
		'Enter a name that identifies this installation and the URL above.',
		'Choose “Connect”, sign in with your own back end account and confirm the request.',
		'The Claude desktop and mobile apps pick up the connector; there is no need to add it again.',
	),
	'help_trouble'   => 'If the connection fails',
	'help_problems'  => array
	(
		'<strong>“Registration failed”</strong>: The site is behind an HTTP basic login (web server password prompt). /mcp, /mcp/token, /mcp/revoke and /.well-known/oauth-* must be excluded from it.',
		'<strong>“The server could not be verified” or error 500</strong>: Contao aborts while starting up for /mcp, usually because another extension or a custom config.php enforces the back end login (BackendUser::authenticate()). File and line are in the system log under “Claude-Bundle: Contao brach beim Start …”.',
		'<strong>Error 503</strong>: The MCP server is switched off under <em>System → MCP server</em>.',
		'<strong>Error 403 or “no access” after signing in</strong>: The user is not in an allowed group, or administrators are not allowed (<em>System → MCP server</em>).',
		'<strong>Claude keeps asking to sign in</strong>: The “Authorization” header does not reach PHP. In the .htaccess, <code>RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code> helps.',
		'<strong>Claude cannot write</strong>: Write access only applies to connections made after it was switched on. Revoke the connection here and reconnect in claude.ai.',
		'<strong>The connection is suddenly gone</strong>: Check in the list below whether it was revoked or has expired, and reconnect in claude.ai.',
	),
);
