<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_claude_settings']['server_legend'] = 'Server';
$GLOBALS['TL_LANG']['tl_claude_settings']['instructions_legend'] = 'Hausregeln';
$GLOBALS['TL_LANG']['tl_claude_settings']['access_legend'] = 'Zugriff';
$GLOBALS['TL_LANG']['tl_claude_settings']['test_legend'] = 'Testschlüssel (Ausbaustufe 1)';

$GLOBALS['TL_LANG']['tl_claude_settings']['connect_legend'] = 'Wer darf Claude verbinden';
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeAllowedGroups'] = array('Erlaubte Benutzergruppen', 'Nur Mitglieder dieser Gruppen dürfen Claude über die Anmeldung mit ihrem Backend-Zugang verbinden. Ohne Auswahl dürfen alle. Claude arbeitet immer mit den Rechten des jeweiligen Benutzers.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeAllowAdmins'] = array('Administratoren dürfen sich verbinden', 'Ohne Haken weist die Anmeldung Administrator-Zugänge ab. Empfohlen ist ein eigener Zugang mit eingeschränkter Gruppe.');
$GLOBALS['TL_LANG']['tl_claude_settings']['write_legend'] = 'Schreiben';
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeWriteEnabled'] = array('Schreibende Werkzeuge freigeben', 'Claude darf Inhalte anlegen und ändern, soweit der Backend-Zugang das darf. Neues legt Claude immer unveröffentlicht an. Bestehende Verbindungen müssen sich danach einmal neu anmelden, um das Recht zu erhalten.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudePublishEnabled'] = array('Veröffentlichen erlauben', 'Claude darf Datensätze veröffentlichen und verstecken (Werkzeug publish_toggle). Ohne Haken bleibt das Handarbeit im Backend.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeDeleteEnabled'] = array('Löschen erlauben', 'Claude darf Datensätze löschen (Werkzeug record_delete). Gelöscht wird wie im Backend über „Wiederherstellen“ (tl_undo).');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeMcpEnabled'] =array('MCP-Server eingeschaltet', 'Ohne Haken antwortet /mcp allen Clients mit „503 Service Unavailable“ (Notschalter).');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeInstructions'] = array('Hausregeln für Claude', 'Wird Claude beim Verbindungsaufbau als Anweisung mitgegeben, etwa „E-Mail-Adressen immer als {{email::adresse}}“. Eine Regel je Zeile.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeBlockedTables'] = array('Zusätzlich gesperrte Tabellen', 'Durch Komma getrennt, z. B. „tl_foo, tl_bar_*“. Ein Stern am Ende sperrt alle Tabellen mit diesem Anfang. Benutzer, Mitglieder, Einstellungen, Versionen, Protokolle und Formular-Einsendungen sind immer gesperrt.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeTestUser'] = array('Backend-Benutzer des Testschlüssels', 'Mit dessen Rechten arbeitet Claude, wenn es sich mit dem Testschlüssel anmeldet. Leer lassen schaltet den Testschlüssel ab.');
$GLOBALS['TL_LANG']['tl_claude_settings']['claudeTestTokenNew'] = array('Neuen Testschlüssel erzeugen', 'Beim Speichern entsteht ein neuer Schlüssel; der bisherige wird ungültig. Der Schlüssel wird nur ein einziges Mal angezeigt.');

$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenCreated'] = 'Neuer Testschlüssel (nur jetzt sichtbar, bitte sofort kopieren): %s';
$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenStatus'] = 'Ein Testschlüssel ist gesetzt (erzeugt am %s).';
$GLOBALS['TL_LANG']['tl_claude_settings']['testTokenMissing'] = 'Es gibt noch keinen Testschlüssel.';
$GLOBALS['TL_LANG']['tl_claude_settings']['endpoint'] = 'Adresse des MCP-Endpunkts: %s';
$GLOBALS['TL_LANG']['tl_claude_settings']['adminOnly'] = 'Die Einstellungen des MCP-Servers sind Administratoren vorbehalten.';
$GLOBALS['TL_LANG']['tl_claude_settings']['userAdmin'] = 'Administrator';
$GLOBALS['TL_LANG']['tl_claude_settings']['userDisabled'] = 'deaktiviert';
