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
	'empty'          => 'Es gibt keine Verbindungen mit Claude.',
	'user'           => 'Benutzer',
	'client'         => 'Anwendung',
	'created'        => 'Verbunden am',
	'lastUsed'       => 'Zuletzt benutzt',
	'expires'        => 'Gültig bis',
	'status'         => 'Status',
	'status_active'  => 'aktiv',
	'status_expired' => 'abgelaufen',
	'status_revoked' => 'widerrufen',
	'revoke'         => 'Widerrufen',
	'confirm'        => 'Diese Verbindung wirklich widerrufen? Claude muss sich danach neu anmelden.',
	'revoked'        => 'Die Verbindung ist widerrufen.',
	'help_connect'   => 'Claude verbinden',
	'help_url'       => 'Adresse für den Konnektor:',
	'help_steps'     => array
	(
		'In claude.ai unter <em>Einstellungen → Konnektoren</em> „Benutzerdefinierten Konnektor hinzufügen“ wählen.',
		'Einen Namen vergeben, der die Installation nennt, und die Adresse oben eintragen.',
		'„Verbinden“ wählen, mit dem eigenen Backend-Zugang anmelden und die Anfrage bestätigen.',
		'Die Claude-Apps für Desktop und Handy übernehmen den Konnektor; dort muss er nicht neu angelegt werden.',
	),
	'help_trouble'   => 'Wenn die Verbindung nicht klappt',
	'help_problems'  => array
	(
		'<strong>„Registrierung fehlgeschlagen“</strong>: Die Seite liegt hinter einem HTTP-Basic-Login (Passwortabfrage des Webservers). /mcp, /mcp/token, /mcp/revoke und /.well-known/oauth-* müssen davon ausgenommen sein.',
		'<strong>„Der Server konnte nicht überprüft werden“ oder Fehler 500</strong>: Contao bricht beim Start für /mcp ab, meist weil eine andere Erweiterung oder eine eigene config.php die Backend-Anmeldung erzwingt (BackendUser::authenticate()). Datei und Zeile stehen im System-Log unter „Claude-Bundle: Contao brach beim Start …“.',
		'<strong>Fehler 503</strong>: Der MCP-Server ist unter <em>System → MCP-Server</em> abgeschaltet.',
		'<strong>Fehler 403 oder „kein Zugang“ nach der Anmeldung</strong>: Der Benutzer gehört keiner freigegebenen Gruppe an, oder Administratoren sind nicht zugelassen (<em>System → MCP-Server</em>).',
		'<strong>Claude verlangt immer wieder eine Anmeldung</strong>: Der Header „Authorization“ erreicht PHP nicht. In der .htaccess hilft <code>RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code>.',
		'<strong>Claude kann nicht schreiben</strong>: Schreibrechte gelten nur für Verbindungen, die nach dem Einschalten entstanden sind. Die Verbindung hier widerrufen und in claude.ai neu verbinden.',
		'<strong>Die Verbindung ist plötzlich weg</strong>: In der Liste unten nachsehen, ob sie widerrufen oder abgelaufen ist, und in claude.ai neu verbinden.',
	),
);
