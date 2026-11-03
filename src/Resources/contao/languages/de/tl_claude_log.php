<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_claude_log']['tstamp'] = array('Zeit', 'Zeitpunkt des Aufrufs.');
$GLOBALS['TL_LANG']['tl_claude_log']['username'] = array('Benutzer', 'Backend-Zugang, mit dessen Rechten Claude gearbeitet hat.');
$GLOBALS['TL_LANG']['tl_claude_log']['clientName'] = array('Verbindung', 'Claude, Claude Code oder Testschlüssel.');
$GLOBALS['TL_LANG']['tl_claude_log']['tool'] = array('Werkzeug', 'Aufgerufenes Werkzeug.');
$GLOBALS['TL_LANG']['tl_claude_log']['tableName'] = array('Tabelle', 'Betroffene Tabelle.');
$GLOBALS['TL_LANG']['tl_claude_log']['recordId'] = array('ID', 'ID des Datensatzes, 0 wenn keiner entstand.');
$GLOBALS['TL_LANG']['tl_claude_log']['fields'] = array('Felder', 'Namen der übergebenen Felder; die Werte zeigen die Versionen des Datensatzes.');
$GLOBALS['TL_LANG']['tl_claude_log']['result'] = array('Ergebnis', '„ok“ oder „abgewiesen“.');
$GLOBALS['TL_LANG']['tl_claude_log']['message'] = array('Meldung', 'Grund bei abgewiesenen Versuchen.');
