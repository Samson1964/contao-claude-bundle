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
 * record_restore: eine frühere Version eines Datensatzes wiederherstellen.
 */
class RecordRestoreTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Stellt die Version her
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
		return 'record_restore';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Version wiederherstellen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Stellt eine frühere Version eines Datensatzes wieder her, wie „Version wiederherstellen“ im Backend; die vorhandenen Versionen liefert record_history. Alle versionierten Felder bekommen den Stand dieser Version, ID, Eltern und Position bleiben. Gut, um eine eigene Änderung rückgängig zu machen. Gelöschte Datensätze stellt ein Mensch unter System → Wiederherstellen wieder her.';
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
				'version' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Versionsnummer aus record_history.'),
			),
			'required' => array('table', 'id', 'version'),
		);
	}

	/**
	 * Stellt die Version her.
	 *
	 * @param string $table   Tabelle
	 * @param int    $id      ID
	 * @param int    $version Versionsnummer
	 *
	 * @return array<string, mixed> Der Datensatz danach, mit Backend-Link
	 */
	public function __invoke(string $table, int $id, int $version): array
	{
		$this->writer->restore($this->getName(), $table, $id, $version);

		return $this->results->build($table, $id, 'restored', array('version' => $version));
	}

	/**
	 * Überschreibt den aktuellen Stand, deshalb als verändernd gekennzeichnet.
	 *
	 * @return bool true
	 */
	protected function isDestructive(): bool
	{
		return true;
	}

	/**
	 * Dieselbe Version noch einmal herzustellen ändert nichts mehr.
	 *
	 * @return bool true
	 */
	protected function isIdempotent(): bool
	{
		return true;
	}
}
