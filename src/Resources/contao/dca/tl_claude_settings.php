<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DC_File;

/*
 * Einstellungen des MCP-Servers (System → MCP-Server).
 *
 * Wie tl_settings schreibt DC_File in die localconfig.php; gelesen werden
 * die Werte über Settings\Settings. Die Maske ist Administratoren
 * vorbehalten (EventListener\DataContainer\SettingsListener::checkAccess()),
 * weil der Testschlüssel mit den Rechten jedes beliebigen Backend-Benutzers
 * ausgestellt werden kann.
 *
 * claudeTestTokenNew ist ein Schalter ohne eigenen Wert: Sein save_callback
 * erzeugt den Schlüssel, legt nur dessen Hash ab und gibt den Schlüssel
 * einmal als Meldung aus.
 */
$GLOBALS['TL_DCA']['tl_claude_settings'] = array
(
	'config' => array
	(
		'dataContainer' => DC_File::class,
		'closed'        => true,
	),

	'palettes' => array
	(
		'default' => '{server_legend},claudeMcpEnabled;{connect_legend},claudeAllowedGroups,claudeAllowAdmins;{instructions_legend},claudeInstructions;{access_legend},claudeBlockedTables;{test_legend},claudeTestUser,claudeTestTokenNew',
	),

	'fields' => array
	(
		'claudeMcpEnabled' => array
		(
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'w50'),
		),
		'claudeAllowedGroups' => array
		(
			'inputType'  => 'checkbox',
			'foreignKey' => 'tl_user_group.name',
			'eval'       => array('multiple' => true, 'tl_class' => 'clr'),
		),
		'claudeAllowAdmins' => array
		(
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'clr'),
		),
		'claudeInstructions' => array
		(
			'inputType' => 'textarea',
			// preserveTags, damit Beispiele wie <strong> oder {{email::…}}
			// in den Hausregeln unverändert ankommen
			'eval'      => array('preserveTags' => true, 'decodeEntities' => true, 'rows' => 12, 'tl_class' => 'clr'),
		),
		'claudeBlockedTables' => array
		(
			'inputType' => 'text',
			'eval'      => array('maxlength' => 1024, 'decodeEntities' => true, 'tl_class' => 'long clr'),
		),
		'claudeTestUser' => array
		(
			'inputType' => 'select',
			'eval'      => array('includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'),
		),
		'claudeTestTokenNew' => array
		(
			'inputType' => 'checkbox',
			'eval'      => array('doNotSaveEmpty' => true, 'tl_class' => 'w50 m12'),
		),
	),
);
