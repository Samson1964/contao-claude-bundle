<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Schachbulle\ContaoClaudeBundle\Write\RecordWriter;
use Schachbulle\ContaoClaudeBundle\Write\ResultBuilder;

/**
 * content_update: Felder eines Inhaltselements ändern.
 */
class ContentUpdateTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Speichert die Werte
	 * @param ResultBuilder $results Baut die Antwort
	 */
	public function __construct(
		private readonly RecordWriter $writer,
		private readonly ResultBuilder $results,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'content_update';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Inhaltselement ändern';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Ändert Felder eines Inhaltselements (tl_content). Nur die übergebenen Felder ändern sich; Contao prüft sie wie beim Speichern im Backend und legt eine Version an (record_history, record_restore). Lies das Element vorher mit record_get, damit du vorhandenen Text nicht versehentlich ersetzt. Ein neuer type wechselt die Palette; Felder des neuen Typs gleich mitgeben. Sichtbarkeit (invisible) nur über publish_toggle.';
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
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Inhaltselements.'),
				'values' => array('type' => 'object', 'description' => self::valuesHint()),
			),
			'required' => array('id', 'values'),
		);
	}

	/**
	 * Speichert die Werte.
	 *
	 * @param int                  $id     ID des Elements
	 * @param array<string, mixed> $values Feldwerte
	 *
	 * @return array<string, mixed> Das Element nach dem Speichern, mit Backend-Link
	 */
	public function __invoke(int $id, array $values): array
	{
		$this->writer->update($this->getName(), 'tl_content', $id, $values);

		return $this->results->build('tl_content', $id, 'updated', array('changedFields' => array_keys($values)));
	}

	/**
	 * Dieselben Werte noch einmal zu speichern ändert nichts mehr.
	 *
	 * @return bool true
	 */
	protected function isIdempotent(): bool
	{
		return true;
	}
}
