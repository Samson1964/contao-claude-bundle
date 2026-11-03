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
 * Protokoll der schreibenden Werkzeuge (System → Claude-Protokoll).
 *
 * Eine Zeile je Schreibversuch, auch für abgewiesene: Zeit, Benutzer,
 * Verbindung, Werkzeug, Tabelle, ID, die Namen der geänderten Felder und
 * das Ergebnis. Inhalte werden nicht gespeichert; was sich geändert hat,
 * zeigen die Versionen des Datensatzes.
 *
 * Nur lesen: Die Zeilen schreibt Write\AuditLog, gelöscht wird nach
 * 180 Tagen durch den täglichen Cronjob. Wer nicht Administrator ist, sieht
 * nur die eigenen Einträge (EventListener\DataContainer\LogListener). Für
 * die Werkzeuge ist die Tabelle gesperrt (tl_claude_*).
 */
$GLOBALS['TL_DCA']['tl_claude_log'] = array
(
	'config' => array
	(
		'dataContainer'    => DC_Table::class,
		'closed'           => true,
		'notEditable'      => true,
		'notCopyable'      => true,
		'notDeletable'     => true,
		'notSortable'      => true,
		'sql' => array
		(
			'keys' => array
			(
				'id'     => 'primary',
				'tstamp' => 'index',
				'userId' => 'index',
			),
		),
	),

	'list' => array
	(
		'sorting' => array
		(
			'mode'        => 2,
			'fields'      => array('tstamp DESC'),
			'flag'        => 6,
			'panelLayout' => 'filter;search,limit',
		),
		'label' => array
		(
			'fields'      => array('tstamp', 'username', 'tool', 'tableName', 'recordId', 'result'),
			'showColumns' => true,
		),
		'operations' => array
		(
			'show' => array
			(
				'href' => 'act=show',
				'icon' => 'show.svg',
			),
		),
	),

	'fields' => array
	(
		'id'         => array('sql' => 'int(10) unsigned NOT NULL auto_increment'),
		'tstamp'     => array
		(
			'sorting' => true,
			'flag'    => 6,
			'eval'    => array('rgxp' => 'datim'),
			'sql'     => "int(10) unsigned NOT NULL default '0'",
		),
		'userId'     => array('sql' => "int(10) unsigned NOT NULL default '0'"),
		'username'   => array
		(
			'filter' => true,
			'sql'    => "varchar(64) NOT NULL default ''",
		),
		'clientName' => array
		(
			'filter' => true,
			'sql'    => "varchar(64) NOT NULL default ''",
		),
		'tool'       => array
		(
			'filter' => true,
			'sql'    => "varchar(64) NOT NULL default ''",
		),
		'tableName'  => array
		(
			'filter' => true,
			'sql'    => "varchar(64) NOT NULL default ''",
		),
		'recordId'   => array
		(
			'search' => true,
			'sql'    => "int(10) unsigned NOT NULL default '0'",
		),
		'fields'     => array
		(
			'search' => true,
			'sql'    => 'text NULL',
		),
		'result'     => array
		(
			'filter' => true,
			'sql'    => "varchar(16) NOT NULL default ''",
		),
		'message'    => array
		(
			'search' => true,
			'sql'    => "varchar(255) NOT NULL default ''",
		),
	),
);
