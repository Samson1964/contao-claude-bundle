<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DC_Table;

/*
 * Verbindungen zwischen Claude und einem Backend-Benutzer (OAuth).
 *
 * Eine Zeile je Verbindung: Sie beginnt als Autorisierungscode, nach dem
 * Tausch hält sie Zugriffs- und Erneuerungsschlüssel, bei jeder Erneuerung
 * werden beide ersetzt. Gespeichert sind nur SHA-256-Hashes, nie die
 * Schlüssel selbst.
 *
 * Die Tabelle wird nicht über DC_Table bearbeitet; angezeigt und widerrufen
 * wird im eigenen Modul System → Claude-Verbindungen
 * (Backend\ConnectionsModule). Für die allgemeinen Werkzeuge ist sie
 * gesperrt (tl_claude_*). Die DCA beschreibt nur die Datenbankstruktur.
 */
$GLOBALS['TL_DCA']['tl_claude_token'] = array
(
	'config' => array
	(
		'dataContainer'    => DC_Table::class,
		'closed'           => true,
		'notEditable'      => true,
		'notCopyable'      => true,
		'notDeletable'     => true,
		'sql' => array
		(
			'keys' => array
			(
				'id'          => 'primary',
				'userId'      => 'index',
				'codeHash'    => 'index',
				'accessHash'  => 'index',
				'refreshHash' => 'index',
			),
		),
	),

	'fields' => array
	(
		'id'             => array('sql' => 'int(10) unsigned NOT NULL auto_increment'),
		'tstamp'         => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'userId'         => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'clientId'       => array('sql' => "varchar(255) NOT NULL default ''"),
		'clientName'     => array('sql' => "varchar(64) NOT NULL default ''"),
		'scope'          => array('sql' => "varchar(255) NOT NULL default ''"),
		'resource'       => array('sql' => "varchar(255) NOT NULL default ''"),
		'redirectUri'    => array('sql' => "varchar(512) NOT NULL default ''"),
		'codeHash'       => array('sql' => "char(64) NOT NULL default ''"),
		'codeChallenge'  => array('sql' => "varchar(128) NOT NULL default ''"),
		'codeExpires'    => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'codeUsed'       => array('sql' => "char(1) NOT NULL default ''"),
		'accessHash'     => array('sql' => "char(64) NOT NULL default ''"),
		'accessExpires'  => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'refreshHash'    => array('sql' => "char(64) NOT NULL default ''"),
		'refreshExpires' => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'created'        => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'lastUsed'       => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'revoked'        => array('sql' => "char(1) NOT NULL default ''"),
	),
);
