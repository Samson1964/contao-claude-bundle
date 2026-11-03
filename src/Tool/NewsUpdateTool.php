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
 * news_update: Felder einer Nachricht ändern.
 */
class NewsUpdateTool extends AbstractWriteTool
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
		return 'news_update';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Nachricht ändern';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Ändert Felder einer Nachricht (tl_news) wie Überschrift, Teaser, Datum oder Bild. Den Nachrichtentext in den Inhaltselementen ändert content_update (Elemente liefert news_get). Nur die übergebenen Felder ändern sich; Contao legt eine Version an. Veröffentlichen nur über publish_toggle.';
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
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID der Nachricht.'),
				'values' => array('type' => 'object', 'description' => self::valuesHint()),
			),
			'required' => array('id', 'values'),
		);
	}

	/**
	 * Speichert die Werte.
	 *
	 * @param int                  $id     ID der Nachricht
	 * @param array<string, mixed> $values Feldwerte
	 *
	 * @return array<string, mixed> Die Nachricht nach dem Speichern, mit Backend-Link
	 */
	public function __invoke(int $id, array $values): array
	{
		$this->writer->update($this->getName(), 'tl_news', $id, $values);

		return $this->results->build('tl_news', $id, 'updated', array('changedFields' => array_keys($values)));
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
