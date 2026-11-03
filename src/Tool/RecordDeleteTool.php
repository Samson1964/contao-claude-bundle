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

/**
 * record_delete: einen Datensatz löschen, wiederherstellbar über tl_undo.
 *
 * Erscheint nur, wenn das Löschen in den Einstellungen eigens freigegeben ist.
 */
class RecordDeleteTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter $writer Löscht
	 */
	public function __construct(private readonly RecordWriter $writer)
	{
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'record_delete';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Datensatz löschen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Löscht einen Datensatz samt seiner Kinddatensätze (Seite mit Unterseiten und Artikeln, Nachricht mit Inhaltselementen …), wie „Löschen“ im Backend. Wiederherstellen kann ein Mensch unter System → Wiederherstellen. Vorher wird nach Verweisen gesucht (Insert-Tags, Weiterleitungen, eingebundene Elemente); gibt es welche, wird nicht gelöscht und die Liste zurückgegeben. Nur auf ausdrücklichen Wunsch des Benutzers verwenden und vorher bestätigen lassen.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname.'),
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Datensatzes.'),
				'ignoreReferences' => array('type' => 'boolean', 'description' => 'true: trotz gefundener Verweise löschen, erst nach Rückfrage beim Benutzer.'),
			),
			'required' => array('table', 'id'),
		);
	}

	/**
	 * Gibt das Werkzeug nur bei freigegebenem Löschen frei.
	 *
	 * @return bool true, wenn Schreiben und Löschen erlaubt sind
	 */
	public function isAvailable(): bool
	{
		return null !== $this->settings && $this->settings->isDeleteEnabled();
	}

	/**
	 * Löscht den Datensatz.
	 *
	 * @param string $table            Tabelle
	 * @param int    $id               ID
	 * @param bool   $ignoreReferences Trotz Verweisen löschen
	 *
	 * @return array<string, mixed> table, id, action und die Verweise, die bestanden
	 */
	public function __invoke(string $table, int $id, bool $ignoreReferences = false): array
	{
		$references = $this->writer->delete($this->getName(), $table, $id, $ignoreReferences);

		return array(
			'table' => $table,
			'id' => $id,
			'action' => 'deleted',
			'references' => $references,
			'restore' => 'Wiederherstellen im Backend unter System → Wiederherstellen.',
		);
	}

	/**
	 * Löschen entfernt Daten.
	 *
	 * @return bool true
	 */
	protected function isDestructive(): bool
	{
		return true;
	}
}
