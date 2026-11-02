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
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * news_search: Nachrichten nach Archiv, Zeitraum, Text und Status suchen.
 */
class NewsSearchTool extends AbstractReadTool
{
	/**
	 * @param TableAccess $tableAccess Prüft Modul- und Archivrechte
	 * @param Connection  $connection  Sucht die Nachrichten
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
		return 'news_search';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Nachrichten suchen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Sucht Nachrichten, neueste zuerst. Filter: Archive, Zeitraum des Nachrichtendatums, Suchtext (Überschrift, Unterüberschrift, Teaser und Text der Inhaltselemente) und Status. Liefert je Treffer ID, Überschrift, Alias, Datum, Archiv, Autor, Veröffentlichung, Sichtbarkeit und einen Teaser-Auszug; den vollständigen Inhalt liefert news_get.';
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
				'archiveIds' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Nur diese Nachrichtenarchive (IDs aus site_info). Leer: alle freigegebenen.'),
				'query' => array('type' => 'string', 'description' => 'Suchtext; Groß- und Kleinschreibung egal.'),
				'from' => array('type' => 'string', 'description' => 'Frühestes Nachrichtendatum, JJJJ-MM-TT.'),
				'to' => array('type' => 'string', 'description' => 'Spätestes Nachrichtendatum, JJJJ-MM-TT.'),
				'status' => array('type' => 'string', 'enum' => array('all', 'published', 'unpublished'), 'description' => 'Vorgabe all.'),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Treffer je Seite, Vorgabe 20.'),
				'offset' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Überspringt so viele Treffer (zum Blättern).'),
			),
		);
	}

	/**
	 * Sucht die Nachrichten.
	 *
	 * @param array<int> $archiveIds Nachrichtenarchive, leer für alle freigegebenen
	 * @param string     $query      Suchtext
	 * @param string     $from       Frühestes Datum, JJJJ-MM-TT
	 * @param string     $to         Spätestes Datum, JJJJ-MM-TT
	 * @param string     $status     all, published oder unpublished
	 * @param int        $limit      Treffer je Seite
	 * @param int        $offset     Zu überspringende Treffer
	 *
	 * @return array<string, mixed> total, offset, items
	 */
	public function __invoke(array $archiveIds = array(), string $query = '', string $from = '', string $to = '', string $status = 'all', int $limit = 20, int $offset = 0): array
	{
		$this->tableAccess->assertReadable('tl_news');

		$where = array();
		$params = array();
		$condition = $this->tableAccess->getRowCondition('tl_news');

		if (null !== $condition) {
			$where[] = '('.$condition[0].')';
			$params = array_merge($params, $condition[1]);
		}

		$archiveIds = array_values(array_filter(array_map('intval', $archiveIds)));

		if (array() !== $archiveIds) {
			$where[] = 'pid IN ('.implode(',', $archiveIds).')';
		}

		if (null !== ($start = self::parseDate($from))) {
			$where[] = 'date >= ?';
			$params[] = $start;
		}

		if (null !== ($end = self::parseDate($to, true))) {
			$where[] = 'date <= ?';
			$params[] = $end;
		}

		if ('' !== trim($query)) {
			$like = '%'.addcslashes(trim($query), '%_\\').'%';
			$where[] = "(headline LIKE ? OR subheadline LIKE ? OR teaser LIKE ? OR id IN (SELECT pid FROM tl_content WHERE ptable = 'tl_news' AND (headline LIKE ? OR text LIKE ?)))";
			array_push($params, $like, $like, $like, $like, $like);
		}

		if ('published' === $status) {
			$where[] = "published = '1'";
		} elseif ('unpublished' === $status) {
			$where[] = "published <> '1'";
		}

		$sqlWhere = array() === $where ? '' : ' WHERE '.implode(' AND ', $where);
		$limit = self::clampLimit($limit);
		$offset = max(0, $offset);

		$total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_news'.$sqlWhere, $params);
		$rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_news'.$sqlWhere.' ORDER BY date DESC, id DESC LIMIT '.$limit.' OFFSET '.$offset, $params);

		$archives = $this->fetchNames('SELECT id, title AS name FROM tl_news_archive');
		$authors = $this->fetchNames('SELECT id, name FROM tl_user');
		$items = array();

		foreach ($rows as $row) {
			$items[] = array(
				'id' => (int) $row['id'],
				'headline' => RecordFormatter::excerpt($row['headline'], 300),
				'alias' => (string) $row['alias'],
				'date' => RecordFormatter::formatDate($row['date']),
				'archive' => array('id' => (int) $row['pid'], 'title' => $archives[(int) $row['pid']] ?? null),
				'author' => $authors[(int) $row['author']] ?? null,
				'published' => (bool) $row['published'],
				'visible' => self::isVisible($row),
				'featured' => (bool) ($row['featured'] ?? false),
				'teaser' => RecordFormatter::excerpt($row['teaser'] ?? ''),
			);
		}

		return array(
			'total' => $total,
			'offset' => $offset,
			'items' => $items,
		);
	}

	/**
	 * Liest eine Zuordnung ID → Name.
	 *
	 * Aus tl_user werden dabei nur ID und Name gelesen, so wie das Backend
	 * den Autor einer Nachricht anzeigt; die Tabelle selbst bleibt gesperrt.
	 *
	 * @param string $sql Abfrage mit den Spalten id und name
	 *
	 * @return array<int, string> ID => Name
	 */
	private function fetchNames(string $sql): array
	{
		$names = array();

		foreach ($this->connection->fetchAllAssociative($sql) as $row) {
			$names[(int) $row['id']] = (string) $row['name'];
		}

		return $names;
	}
}
