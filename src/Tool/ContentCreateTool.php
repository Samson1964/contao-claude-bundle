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
 * content_create: ein Inhaltselement in einem Artikel, einer Nachricht,
 * einem Termin oder einem anderen Elterndatensatz anlegen.
 */
class ContentCreateTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Legt den Datensatz an
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
		return 'content_create';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Inhaltselement anlegen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Legt ein Inhaltselement (tl_content) an, standardmäßig am Ende des Elterndatensatzes. Elterntabelle ist meist tl_article (Seiteninhalt) oder tl_news (Nachrichtentext). Unter einem veröffentlichten Elterndatensatz entsteht das Element versteckt, unter einem unveröffentlichten sichtbar. Contao prüft alle Werte wie beim Speichern im Backend; welche Felder ein Typ hat, zeigt dca_describe mit table „tl_content“ und type.';
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
				'parentTable' => array('type' => 'string', 'description' => 'Elterntabelle, etwa „tl_article“, „tl_news“, „tl_calendar_events“.'),
				'parentId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Elterndatensatzes.'),
				'type' => array('type' => 'string', 'description' => 'Elementtyp, etwa „text“, „headline“, „image“, „html“.'),
				'values' => array('type' => 'object', 'description' => self::valuesHint()),
				'position' => array('type' => 'string', 'enum' => array('end', 'start', 'after'), 'description' => 'Wohin: ans Ende (Vorgabe), an den Anfang oder nach afterId.'),
				'afterId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Bei position „after“: das Element, hinter dem eingefügt wird.'),
			),
			'required' => array('parentTable', 'parentId', 'type'),
		);
	}

	/**
	 * Legt das Element an.
	 *
	 * @param string               $parentTable Elterntabelle
	 * @param int                  $parentId    Elterndatensatz
	 * @param string               $type        Elementtyp
	 * @param array<string, mixed> $values      Weitere Feldwerte
	 * @param string               $position    end, start oder after
	 * @param int|null             $afterId     Bezugselement bei after
	 *
	 * @return array<string, mixed> Das neue Element wie bei record_get, mit Backend-Link
	 */
	public function __invoke(string $parentTable, int $parentId, string $type, array $values = array(), string $position = 'end', ?int $afterId = null): array
	{
		$values = array('type' => $type) + $values;
		$id = $this->writer->create($this->getName(), 'tl_content', $values, $parentId, $parentTable, $position, $afterId);

		return $this->results->build('tl_content', $id, 'created');
	}
}
