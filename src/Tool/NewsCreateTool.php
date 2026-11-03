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
 * news_create: eine Nachricht unveröffentlicht anlegen, auf Wunsch gleich
 * mit einem Textelement.
 */
class NewsCreateTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Legt Nachricht und Element an
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
		return 'news_create';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Nachricht anlegen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Legt eine Nachricht (tl_news) in einem Archiv an, immer unveröffentlicht. Der eigentliche Text einer Nachricht steht in Inhaltselementen: Mit text wird gleich ein Textelement mit diesem HTML angelegt, weitere Elemente mit content_create (parentTable „tl_news“). Teaser, Datum, Autor und Bild über values; Alias erzeugt Contao aus der Überschrift. Archive liefert news_search bzw. site_info.';
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
				'archiveId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Nachrichtenarchivs (tl_news_archive).'),
				'headline' => array('type' => 'string', 'description' => 'Überschrift der Nachricht.'),
				'values' => array('type' => 'object', 'description' => 'Weitere Felder der Nachricht, etwa teaser (HTML), date, time, subheadline. '.self::valuesHint()),
				'text' => array('type' => 'string', 'description' => 'HTML des Nachrichtentextes; wird als Inhaltselement „text“ angelegt.'),
			),
			'required' => array('archiveId', 'headline'),
		);
	}

	/**
	 * Legt die Nachricht an.
	 *
	 * Nachricht und Textelement entstehen in zwei Schritten. Scheitert das
	 * Textelement, bleibt die Nachricht bestehen; die Meldung nennt ihre ID,
	 * damit Claude das Element mit content_create nachreichen kann.
	 *
	 * @param int                  $archiveId Archiv
	 * @param string               $headline  Überschrift
	 * @param array<string, mixed> $values    Weitere Felder
	 * @param string|null          $text      HTML für ein Textelement
	 *
	 * @return array<string, mixed> Die neue Nachricht, mit Backend-Link und ggf. contentId
	 */
	public function __invoke(int $archiveId, string $headline, array $values = array(), ?string $text = null): array
	{
		$id = $this->writer->create($this->getName(), 'tl_news', array('headline' => $headline) + $values, $archiveId);
		$extra = array();

		if (null !== $text && '' !== trim($text)) {
			try {
				$extra['contentId'] = $this->writer->create($this->getName(), 'tl_content', array('type' => 'text', 'text' => $text), $id, 'tl_news');
			} catch (\Mcp\Exception\ToolCallException $exception) {
				$extra['contentError'] = 'Die Nachricht '.$id.' ist angelegt, das Textelement nicht: '.$exception->getMessage();
			}
		}

		return $this->results->build('tl_news', $id, 'created', $extra);
	}
}
