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
use Schachbulle\ContaoClaudeBundle\Write\RecordWriter;
use Schachbulle\ContaoClaudeBundle\Write\ResultBuilder;

/**
 * championslist_add: einen Eintrag in eine Meisterliste schreiben.
 *
 * Läuft über RecordWriter wie record_save, also mit allen Rechten und
 * Prüfungen des Backends und den Rechteprüfungen des Meisterlisten-Bundles
 * in dessen onload_callback. Der Eintrag entsteht unveröffentlicht.
 */
class ChampionslistAddTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer     Legt den Eintrag an
	 * @param ResultBuilder $results    Baut die Antwort
	 * @param Connection    $connection Prüft, ob das Bundle installiert ist
	 */
	public function __construct(
		private readonly RecordWriter $writer,
		private readonly ResultBuilder $results,
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
		return 'championslist_add';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Eintrag in eine Meisterliste';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Trägt in eine Meisterliste (championslist_search ohne Angaben listet sie) einen neuen Meister oder Platzierten ein: Jahr, Name, dazu über values etwa number (Nummer der Meisterschaft), place (Ort), verein, age, rating, info. Der Eintrag entsteht unveröffentlicht; sichtbar macht ihn publish_toggle oder ein Mensch im Backend. Vorher mit championslist_search prüfen, ob es das Jahr schon gibt. Felder zeigt dca_describe mit table „tl_championslists_items“.';
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
				'listId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID der Meisterliste.'),
				'year' => array('type' => 'string', 'description' => 'Jahr, etwa „2026“.'),
				'name' => array('type' => 'string', 'description' => 'Name des Meisters bzw. der Meisterin, mit Titel wie in den übrigen Einträgen (etwa „GM Max Muster“).'),
				'values' => array('type' => 'object', 'description' => 'Weitere Felder. '.self::valuesHint()),
			),
			'required' => array('listId', 'year', 'name'),
		);
	}

	/**
	 * Erscheint nur, wenn das Schreiben freigegeben und das Bundle installiert ist.
	 *
	 * @return bool true, wenn beides zutrifft
	 */
	public function isAvailable(): bool
	{
		return parent::isAvailable() && $this->connection->createSchemaManager()->tablesExist(array('tl_championslists_items'));
	}

	/**
	 * Legt den Eintrag an.
	 *
	 * @param int                  $listId Meisterliste
	 * @param string               $year   Jahr
	 * @param string               $name   Name
	 * @param array<string, mixed> $values Weitere Felder
	 *
	 * @return array<string, mixed> Der neue Eintrag wie bei record_get, mit Backend-Link
	 */
	public function __invoke(int $listId, string $year, string $name, array $values = array()): array
	{
		$id = $this->writer->create($this->getName(), 'tl_championslists_items', array('year' => $year, 'name' => $name) + $values, $listId);

		return $this->results->build('tl_championslists_items', $id, 'created');
	}
}
