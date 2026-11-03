<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Schachbulle\ContaoClaudeBundle\Write\FileWriter;

/**
 * file_meta_update: Titel, Alternativtext, Bildunterschrift und Link einer
 * Datei für eine Sprache setzen.
 */
class FileMetaUpdateTool extends AbstractWriteTool
{
	/**
	 * @param FileWriter $files Schreibt die Metadaten
	 */
	public function __construct(private readonly FileWriter $files)
	{
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'file_meta_update';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Metadaten einer Datei ändern';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Setzt die Metadaten einer Datei für eine Sprache: title, alt, caption (Bildunterschrift), link, unter Contao 5 auch license. Nur die übergebenen Angaben ändern sich, ein leerer Wert leert die Angabe. Die Metadaten gelten überall, wo die Datei eingebunden ist; achte auf die Hausregeln, ob vorhandene Dateien geändert werden dürfen.';
	}

	/**
	 * Liefert das JSON-Schema der Argumente. Die Schlüssel entsprechen den
	 * Parameternamen von __invoke(); das SDK ordnet die Werte danach zu.
	 *
	 * @return array<string, mixed> Das Schema
	 */
	public function getInputSchema(): array
	{
		return array(
			'type' => 'object',
			'properties' => array(
				'path' => array('type' => 'string', 'description' => 'Pfad der Datei, etwa „files/presse/bild.jpg“.'),
				'language' => array('type' => 'string', 'description' => 'Sprachkürzel, etwa „de“.'),
				'meta' => array(
					'type' => 'object',
					'description' => 'Angaben, etwa {"title": "…", "alt": "…", "caption": "…"}.',
					'additionalProperties' => array('type' => 'string'),
				),
			),
			'required' => array('path', 'language', 'meta'),
		);
	}

	/**
	 * Setzt die Metadaten.
	 *
	 * @param string                $path     Pfad der Datei
	 * @param string                $language Sprache
	 * @param array<string, string> $meta     Angaben
	 *
	 * @return array<string, mixed> path, language und die Metadaten danach
	 */
	public function __invoke(string $path, string $language, array $meta): array
	{
		return array(
			'action' => 'updated',
			'path' => $path,
			'language' => $language,
			'meta' => $this->files->updateMeta($path, $language, $meta),
		);
	}

	/**
	 * Dieselben Angaben noch einmal gesetzt ändern nichts mehr.
	 *
	 * @return bool true
	 */
	protected function isIdempotent(): bool
	{
		return true;
	}
}
