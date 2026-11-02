<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * record_get: einen Datensatz einer beliebigen Tabelle lesen.
 */
class RecordGetTool extends AbstractReadTool
{
	/**
	 * @param TableAccess     $tableAccess Prüft Tabellen- und Datensatzrecht
	 * @param RecordFormatter $formatter   Bereitet die Werte auf
	 * @param Connection      $connection  Liest den Datensatz
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly RecordFormatter $formatter,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'record_get';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Datensatz lesen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Liefert einen Datensatz einer Contao-Tabelle mit den Feldern, die das Backend für ihn zeigt (Palette seines Typs), ohne leere Werte. Mit includeEmpty: true kommen alle für dich sichtbaren Felder der Tabelle, auch leere. Dateifelder kommen als UUID mit Pfad, Datumsfelder als ISO-Datum, Checkboxen als true/false, serialisierte Felder als Liste.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname, etwa „tl_article“.'),
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Datensatzes.'),
				'includeEmpty' => array('type' => 'boolean', 'description' => 'true: alle sichtbaren Felder der Tabelle, auch leere und solche außerhalb der Palette.'),
			),
			'required' => array('table', 'id'),
		);
	}

	/**
	 * Liefert den Datensatz.
	 *
	 * @param string $table        Tabellenname
	 * @param int    $id           ID des Datensatzes
	 * @param bool   $includeEmpty Alle sichtbaren Felder statt der Palette, auch leere
	 *
	 * @return array<string, mixed> table und record
	 */
	public function __invoke(string $table, int $id, bool $includeEmpty = false): array
	{
		$dca = $this->tableAccess->assertReadable($table);

		if (!$this->tableAccess->canReadRow($table, $id)) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $id, $table));
		}

		$row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id));

		if (false === $row) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht.', $id, $table));
		}

		$fields = $includeEmpty
			? $this->tableAccess->getReadableFields($table, $dca)
			: $this->tableAccess->getVisibleFields($table, $dca, $row);

		return array(
			'table' => $table,
			'record' => $this->formatter->format($row, $fields, !$includeEmpty),
		);
	}
}
