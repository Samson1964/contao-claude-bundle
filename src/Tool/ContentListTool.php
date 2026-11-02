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
use Schachbulle\ContaoClaudeBundle\Data\ContentElements;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * content_list: die Inhaltselemente eines Artikels, einer Nachricht oder
 * eines anderen Elternelements.
 */
class ContentListTool extends AbstractReadTool
{
	/**
	 * @param TableAccess     $tableAccess Prüft die Rechte auf Elternelement und tl_content
	 * @param ContentElements $elements    Liest und kürzt die Elemente
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly ContentElements $elements,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'content_list';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Inhaltselemente';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Listet die Inhaltselemente eines Elternelements in ihrer Reihenfolge: ID, Typ, versteckt (invisible), Überschrift, ein Textauszug und gegebenenfalls das Bild. Mit full: true kommen alle Felder jedes Elements. Elterntabellen sind etwa tl_article (Artikel einer Seite) und tl_news (Nachricht).';
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
				'parentTable' => array('type' => 'string', 'description' => 'Tabelle des Elternelements, etwa „tl_article“, „tl_news“ oder „tl_calendar_events“. Vorgabe tl_article.'),
				'parentId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Artikels, der Nachricht oder des Termins.'),
				'full' => array('type' => 'boolean', 'description' => 'true: alle lesbaren Felder je Element statt der Kurzfassung.'),
			),
			'required' => array('parentId'),
		);
	}

	/**
	 * Liefert die Elemente.
	 *
	 * @param int    $parentId    ID des Elternelements
	 * @param string $parentTable Tabelle des Elternelements
	 * @param bool   $full        Alle Felder statt der Kurzfassung
	 *
	 * @return array<string, mixed> parent und elements
	 */
	public function __invoke(int $parentId, string $parentTable = 'tl_article', bool $full = false): array
	{
		$this->tableAccess->assertReadable($parentTable);

		if (!$this->tableAccess->canReadRow($parentTable, $parentId)) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $parentId, $parentTable));
		}

		$parentDca = $this->tableAccess->loadDca($parentTable) ?? array();

		if (!\in_array('tl_content', (array) ($parentDca['config']['ctable'] ?? array()), true)) {
			throw new ToolCallException(sprintf('Die Tabelle %s hat keine Inhaltselemente.', $parentTable));
		}

		return array(
			'parent' => array('table' => $parentTable, 'id' => $parentId),
			'elements' => $this->elements->listFor($parentTable, $parentId, $full),
		);
	}
}
