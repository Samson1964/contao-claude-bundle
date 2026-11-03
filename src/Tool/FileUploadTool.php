<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Write\FileWriter;

/**
 * file_upload: eine neue Datei in die Dateiverwaltung legen.
 */
class FileUploadTool extends AbstractWriteTool
{
	/**
	 * @param FileWriter $files Legt die Datei ab
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
		return 'file_upload';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Datei hochladen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Legt eine neue Datei in einem vorhandenen Ordner unter files/ ab, Inhalt als Base64 (contentBase64) oder von einer öffentlichen https-Adresse (url). Vorhandene Dateien werden nie überschrieben; erlaubt sind die Dateitypen und die Größe aus den Contao-Einstellungen, SVG nicht. Der Name wird wie beim Upload bereinigt. Optional gleich Titel und Alternativtext für eine Sprache. Den Pfad danach in Dateifeldern wie singleSRC verwenden.';
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
				'folder' => array('type' => 'string', 'description' => 'Vorhandener Zielordner, etwa „files/presse/2026“ (file_list zeigt die Ordner).'),
				'filename' => array('type' => 'string', 'description' => 'Dateiname mit Endung, etwa „siegerehrung.jpg“.'),
				'contentBase64' => array('type' => 'string', 'description' => 'Inhalt als Base64 (auch als data:-URL).'),
				'url' => array('type' => 'string', 'description' => 'Alternativ: öffentliche https-Adresse, von der die Datei geholt wird.'),
				'language' => array('type' => 'string', 'description' => 'Sprache für title und alt, Vorgabe „de“.'),
				'title' => array('type' => 'string', 'description' => 'Titel der Datei (Metadaten).'),
				'alt' => array('type' => 'string', 'description' => 'Alternativtext für Bilder (Metadaten).'),
			),
			'required' => array('folder', 'filename'),
		);
	}

	/**
	 * Lädt die Datei hoch und setzt auf Wunsch Metadaten.
	 *
	 * @param string      $folder        Zielordner
	 * @param string      $filename      Dateiname
	 * @param string|null $contentBase64 Inhalt als Base64
	 * @param string|null $url           Adresse zum Herunterladen
	 * @param string      $language      Sprache der Metadaten
	 * @param string|null $title         Titel
	 * @param string|null $alt           Alternativtext
	 *
	 * @return array<string, mixed> path, uuid, size und ggf. meta oder metaError
	 */
	public function __invoke(string $folder, string $filename, ?string $contentBase64 = null, ?string $url = null, string $language = 'de', ?string $title = null, ?string $alt = null): array
	{
		$result = $this->files->upload($folder, $filename, $contentBase64, $url);
		$meta = array_filter(array('title' => $title, 'alt' => $alt), static fn ($value): bool => null !== $value && '' !== $value);

		if (array() !== $meta) {
			try {
				$result['meta'] = $this->files->updateMeta($result['path'], $language, $meta);
			} catch (ToolCallException $exception) {
				$result['metaError'] = 'Datei hochgeladen, Metadaten nicht gesetzt: '.$exception->getMessage();
			}
		}

		return array('action' => 'uploaded') + $result;
	}
}
