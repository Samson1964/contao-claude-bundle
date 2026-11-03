<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * Sucht vor dem Löschen, was auf einen Datensatz verweist.
 *
 * Contao selbst löscht ohne Rückfrage nach Verweisen. Gefunden werden:
 *
 * - Insert-Tags in Texten ({{link_url::5}}, {{news::12}}, {{insert_article::3}} …)
 * - Weiterleitungsziele (jumpTo) auf eine Seite
 * - Inhaltselemente, die ein Element, einen Artikel, ein Modul oder ein
 *   Formular einbinden
 * - Kinddatensätze, die Contao beim Löschen mitnimmt (Artikel einer Seite,
 *   Nachrichten eines Archivs, Elemente einer Nachricht)
 *
 * Die Suche ist bewusst schlicht und vollständig nur für die Kerntabellen;
 * sie soll vor Überraschungen warnen, nicht beweisen, dass es keine gibt.
 */
class ReferenceFinder
{
	/**
	 * Insert-Tags je Tabelle, deren erster Parameter die ID ist.
	 */
	private const INSERT_TAGS = array(
		'tl_page' => array('link', 'link_open', 'link_url', 'link_title', 'link_name', 'link_target'),
		'tl_article' => array('article', 'article_open', 'article_url', 'article_title', 'insert_article'),
		'tl_news' => array('news', 'news_open', 'news_url', 'news_title', 'news_teaser'),
		'tl_calendar_events' => array('event', 'event_open', 'event_url', 'event_title', 'event_teaser'),
		'tl_faq' => array('faq', 'faq_open', 'faq_url', 'faq_title'),
		'tl_content' => array('insert_content'),
		'tl_module' => array('insert_module'),
		'tl_form' => array('insert_form'),
	);

	/**
	 * Spalten, in denen Insert-Tags stehen können.
	 */
	private const TEXT_COLUMNS = array(
		'tl_content' => array('text', 'html', 'url', 'headline'),
		'tl_article' => array('teaser'),
		'tl_news' => array('teaser', 'url'),
		'tl_calendar_events' => array('teaser', 'url'),
		'tl_page' => array('url'),
		'tl_module' => array('html'),
	);

	/**
	 * Inhaltselemente, die andere Datensätze einbinden: Tabelle => Typ und Spalte.
	 */
	private const EMBEDDING_ELEMENTS = array(
		'tl_content' => array('alias', 'cteAlias'),
		'tl_article' => array('article', 'articleAlias'),
		'tl_module' => array('module', 'module'),
		'tl_form' => array('form', 'form'),
	);

	/**
	 * Tabellen mit einer Weiterleitungsseite in jumpTo.
	 */
	private const JUMP_TO_TABLES = array('tl_page', 'tl_module', 'tl_news_archive', 'tl_calendar', 'tl_faq_category', 'tl_form', 'tl_newsletter_channel');

	/**
	 * @var array<string, list<string>> Spaltennamen je Tabelle, klein geschrieben
	 */
	private array $columns = array();

	/**
	 * @param Connection  $connection  Für die Suche
	 * @param TableAccess $tableAccess Lädt die DCA für die Kindtabellen
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly TableAccess $tableAccess,
	) {
	}

	/**
	 * Sucht alle Verweise auf einen Datensatz.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return list<string> Beschreibungen wie „tl_content 42: Insert-Tag {{news::7}}“
	 */
	public function find(string $table, int $id): array
	{
		return array_merge(
			$this->findChildren($table, $id),
			$this->findInsertTags($table, $id),
			$this->findEmbeddings($table, $id),
			$this->findJumpTo($table, $id),
		);
	}

	/**
	 * Zählt Kinddatensätze, die beim Löschen mitgelöscht würden.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return list<string> Je Kindtabelle eine Zeile mit der Anzahl
	 */
	private function findChildren(string $table, int $id): array
	{
		$dca = $this->tableAccess->loadDca($table) ?? array();
		$found = array();

		foreach ((array) ($dca['config']['ctable'] ?? array()) as $childTable) {
			if (!$this->tableExists($childTable)) {
				continue;
			}

			$childDca = $this->tableAccess->loadDca($childTable) ?? array();
			$sql = 'SELECT COUNT(*) FROM '.$childTable.' WHERE pid = ?';
			$params = array($id);

			if ($childDca['config']['dynamicPtable'] ?? false) {
				$sql .= " AND (ptable = ? OR (ptable = '' AND ? = 'tl_article'))";
				$params[] = $table;
				$params[] = $table;
			}

			$count = (int) $this->connection->fetchOne($sql, $params);

			if ($count > 0) {
				$found[] = sprintf('%d Datensätze in %s werden mitgelöscht', $count, $childTable);
			}
		}

		// Unterseiten im Seitenbaum hängen über pid an derselben Tabelle
		if ('tl_page' === $table) {
			$count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page WHERE pid = ?', array($id));

			if ($count > 0) {
				$found[] = sprintf('%d Unterseiten werden mitgelöscht', $count);
			}
		}

		return $found;
	}

	/**
	 * Sucht Insert-Tags mit der ID in Textspalten.
	 *
	 * Erst grob per LIKE auf „::ID}}“ und „::ID|“, dann genau per Muster.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return list<string> Fundstellen
	 */
	private function findInsertTags(string $table, int $id): array
	{
		$tags = self::INSERT_TAGS[$table] ?? array();

		if (array() === $tags) {
			return array();
		}

		$pattern = '/\{\{('.implode('|', array_map('preg_quote', $tags)).')::'.$id.'(\|[^}]*)?\}\}/';
		$found = array();

		foreach (self::TEXT_COLUMNS as $source => $columns) {
			$columns = array_values(array_filter($columns, fn (string $column): bool => $this->columnExists($source, $column)));

			if (array() === $columns) {
				continue;
			}

			$where = array();
			$params = array();

			foreach ($columns as $column) {
				$where[] = $column.' LIKE ? OR '.$column.' LIKE ?';
				$params[] = '%::'.$id.'}}%';
				$params[] = '%::'.$id.'|%';
			}

			$rows = $this->connection->fetchAllAssociative('SELECT id, '.implode(', ', $columns).' FROM '.$source.' WHERE '.implode(' OR ', $where), $params);

			foreach ($rows as $row) {
				foreach ($columns as $column) {
					if (preg_match($pattern, (string) $row[$column], $match)) {
						$found[] = sprintf('%s %d: Insert-Tag %s', $source, $row['id'], $match[0]);
						break;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Sucht Inhaltselemente, die den Datensatz einbinden.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return list<string> Fundstellen
	 */
	private function findEmbeddings(string $table, int $id): array
	{
		if (!isset(self::EMBEDDING_ELEMENTS[$table])) {
			return array();
		}

		[$type, $column] = self::EMBEDDING_ELEMENTS[$table];

		if (!$this->columnExists('tl_content', $column)) {
			return array();
		}

		$ids = $this->connection->fetchFirstColumn('SELECT id FROM tl_content WHERE type = ? AND '.$column.' = ?', array($type, $id));

		return array_map(static fn ($contentId): string => sprintf('tl_content %d: Element „%s“ bindet den Datensatz ein', $contentId, $type), $ids);
	}

	/**
	 * Sucht Weiterleitungen auf eine Seite.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return list<string> Fundstellen
	 */
	private function findJumpTo(string $table, int $id): array
	{
		if ('tl_page' !== $table) {
			return array();
		}

		$found = array();

		foreach (self::JUMP_TO_TABLES as $source) {
			if (!$this->columnExists($source, 'jumpTo')) {
				continue;
			}

			foreach ($this->connection->fetchFirstColumn('SELECT id FROM '.$source.' WHERE jumpTo = ?', array($id)) as $sourceId) {
				$found[] = sprintf('%s %d: leitet auf diese Seite weiter (jumpTo)', $source, $sourceId);
			}
		}

		return $found;
	}

	/**
	 * Prüft, ob es eine Tabelle gibt.
	 *
	 * @param string $table Tabelle
	 *
	 * @return bool true, wenn sie existiert
	 */
	private function tableExists(string $table): bool
	{
		return $this->connection->createSchemaManager()->tablesExist(array($table));
	}

	/**
	 * Prüft, ob eine Tabelle eine Spalte hat.
	 *
	 * @param string $table  Tabelle
	 * @param string $column Spalte
	 *
	 * @return bool true, wenn Tabelle und Spalte existieren
	 */
	private function columnExists(string $table, string $column): bool
	{
		if (!isset($this->columns[$table])) {
			$this->columns[$table] = $this->tableExists($table)
				? array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableColumns($table)))
				: array();
		}

		return \in_array(strtolower($column), $this->columns[$table], true);
	}
}
