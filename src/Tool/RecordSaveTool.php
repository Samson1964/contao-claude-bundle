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
 * record_save: einen Datensatz einer beliebigen Tabelle anlegen oder ändern.
 */
class RecordSaveTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Legt an oder speichert
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
		return 'record_save';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Datensatz speichern';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Ändert (mit id) oder legt an (ohne id) einen Datensatz einer beliebigen Contao-Tabelle, auch der eigenen Erweiterungen, mit den Rechten deines Backend-Zugangs. Für Inhaltselemente und Nachrichten gibt es eigene Werkzeuge. Felder und Werte zeigt dca_describe. Beim Anlegen in einer Kindtabelle parentId angeben (bei tl_content auch parentTable); Neues entsteht unveröffentlicht. Contao prüft alle Werte wie beim Speichern im Backend.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname, etwa „tl_calendar_events“.'),
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID zum Ändern; weglassen zum Anlegen.'),
				'values' => array('type' => 'object', 'description' => self::valuesHint()),
				'parentId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Nur beim Anlegen: Elterndatensatz (pid), etwa das Archiv oder die übergeordnete Seite.'),
				'parentTable' => array('type' => 'string', 'description' => 'Nur beim Anlegen in tl_content: Elterntabelle.'),
				'position' => array('type' => 'string', 'enum' => array('end', 'start', 'after'), 'description' => 'Nur beim Anlegen: ans Ende (Vorgabe), an den Anfang oder nach afterId.'),
				'afterId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Bei position „after“: Datensatz, hinter dem eingefügt wird.'),
			),
			'required' => array('table', 'values'),
		);
	}

	/**
	 * Legt an oder speichert.
	 *
	 * @param string               $table       Tabelle
	 * @param array<string, mixed> $values      Feldwerte
	 * @param int|null             $id          ID zum Ändern, null zum Anlegen
	 * @param int|null             $parentId    Elterndatensatz beim Anlegen
	 * @param string|null          $parentTable Elterntabelle bei tl_content
	 * @param string               $position    end, start oder after
	 * @param int|null             $afterId     Bezugsdatensatz bei after
	 *
	 * @return array<string, mixed> Der Datensatz nach dem Speichern, mit Backend-Link
	 */
	public function __invoke(string $table, array $values, ?int $id = null, ?int $parentId = null, ?string $parentTable = null, string $position = 'end', ?int $afterId = null): array
	{
		if (null !== $id) {
			$this->writer->update($this->getName(), $table, $id, $values);

			return $this->results->build($table, $id, 'updated', array('changedFields' => array_keys($values)));
		}

		$id = $this->writer->create($this->getName(), $table, $values, $parentId, $parentTable, $position, $afterId);

		return $this->results->build($table, $id, 'created');
	}
}
