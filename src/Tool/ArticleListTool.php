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
 * article_list: die Artikel einer Seite.
 */
class ArticleListTool extends AbstractReadTool
{
	/**
	 * @param TableAccess $tableAccess Prüft die Rechte auf Seite und Artikel
	 * @param Connection  $connection  Liest Artikel und zählt Elemente
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
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
		return 'article_list';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Artikel einer Seite';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Listet die Artikel einer Seite in ihrer Reihenfolge: ID, Titel, Alias, Layoutspalte (inColumn), Veröffentlichung, Anzeigezeitraum und Zahl der Inhaltselemente. Die Elemente selbst liefert content_list mit parentTable „tl_article“.';
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
				'pageId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID der Seite aus page_tree.'),
			),
			'required' => array('pageId'),
		);
	}

	/**
	 * Liefert die Artikel.
	 *
	 * @param int $pageId ID der Seite
	 *
	 * @return array<string, mixed> page (id, title) und articles
	 */
	public function __invoke(int $pageId): array
	{
		$this->tableAccess->assertReadable('tl_article');

		if (!$this->tableAccess->canReadRow('tl_page', $pageId)) {
			throw new ToolCallException(sprintf('Die Seite %d gibt es nicht oder sie liegt außerhalb deiner Seitenfreigaben.', $pageId));
		}

		$title = (string) $this->connection->fetchOne('SELECT title FROM tl_page WHERE id = ?', array($pageId));
		$rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_article WHERE pid = ? ORDER BY sorting', array($pageId));
		$articles = array();

		foreach ($rows as $row) {
			$articles[] = array(
				'id' => (int) $row['id'],
				'title' => (string) $row['title'],
				'alias' => (string) $row['alias'],
				'inColumn' => (string) $row['inColumn'],
				'published' => (bool) $row['published'],
				'start' => RecordFormatter::formatDate($row['start'] ?? null),
				'stop' => RecordFormatter::formatDate($row['stop'] ?? null),
				'elements' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_content WHERE pid = ? AND ptable IN ('tl_article', '')", array((int) $row['id'])),
			);
		}

		return array(
			'page' => array('id' => $pageId, 'title' => $title),
			'articles' => $articles,
		);
	}
}
