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
 * championslist_search: Meisterlisten und ihre Einträge durchsuchen.
 *
 * Für schachbulle/contao-championslists-bundle (tl_championslists mit den
 * Einträgen in tl_championslists_items). Ohne das Bundle erscheint das
 * Werkzeug nicht. Es gelten Modulrecht und die freigegebenen Listen der
 * Benutzergruppe (TableAccess, Feld „championslists“), wie im Backend.
 */
class ChampionslistSearchTool extends AbstractReadTool
{
	/**
	 * Spalten eines Eintrags, die in der Trefferliste erscheinen, sofern vorhanden.
	 */
	private const ITEM_COLUMNS = array('id', 'pid', 'year', 'number', 'failed', 'place', 'numberParticipants', 'name', 'age', 'verein', 'rating', 'cowinner', 'info', 'published');

	/**
	 * @param TableAccess     $tableAccess Rechte
	 * @param RecordFormatter $formatter   Werte wie bei record_get
	 * @param Connection      $connection  Abfragen
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
		return 'championslist_search';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Meisterlisten durchsuchen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Durchsucht die Meisterlisten (Sieger und Platzierte von Meisterschaften nach Jahr). Ohne Angaben kommen alle Listen mit Zahl der Einträge und letztem Jahr. Mit listId, query (Name, Verein oder Ort, Teilstück genügt) oder year kommen die passenden Einträge, neueste zuerst. Beantwortet Fragen wie „Wer war 1987 Meister?“ oder „Wie oft hat X gewonnen?“. Neue Einträge legt championslist_add an.';
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
				'listId' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Nur Einträge dieser Liste (tl_championslists).'),
				'query' => array('type' => 'string', 'description' => 'Suchtext in Name, Verein und Ort.'),
				'year' => array('type' => 'string', 'description' => 'Nur dieses Jahr, etwa „1987“.'),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Höchstzahl der Einträge, Vorgabe 50.'),
			),
		);
	}

	/**
	 * Erscheint nur, wenn das Meisterlisten-Bundle installiert ist.
	 *
	 * @return bool true, wenn es die Tabellen gibt
	 */
	public function isAvailable(): bool
	{
		return $this->connection->createSchemaManager()->tablesExist(array('tl_championslists', 'tl_championslists_items'));
	}

	/**
	 * Liefert Listen oder Einträge.
	 *
	 * @param int|null    $listId Liste
	 * @param string|null $query  Suchtext
	 * @param string|null $year   Jahr
	 * @param int         $limit  Höchstzahl
	 *
	 * @return array<string, mixed> lists oder entries
	 */
	public function __invoke(?int $listId = null, ?string $query = null, ?string $year = null, int $limit = 50): array
	{
		$this->tableAccess->assertReadable('tl_championslists');
		$itemsDca = $this->tableAccess->assertReadable('tl_championslists_items');
		$query = null === $query ? '' : trim($query);
		$year = null === $year ? '' : trim($year);

		if (null === $listId && '' === $query && '' === $year) {
			return array('lists' => $this->listLists());
		}

		if (null !== $listId && !$this->tableAccess->canReadRow('tl_championslists', $listId)) {
			throw new ToolCallException(sprintf('Die Meisterliste %d gibt es nicht oder sie ist für dich nicht freigegeben.', $listId));
		}

		$available = array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableColumns('tl_championslists_items')));
		$columns = array_values(array_filter(self::ITEM_COLUMNS, static fn (string $column): bool => \in_array(strtolower($column), $available, true)));
		$where = array('1 = 1');
		$params = array();

		if (null !== ($condition = $this->tableAccess->getRowCondition('tl_championslists_items'))) {
			$where[] = '('.str_replace(array('pid', 'id IN'), array('i.pid', 'i.id IN'), $condition[0]).')';
			$params = array_merge($params, $condition[1]);
		}

		if (null !== $listId) {
			$where[] = 'i.pid = ?';
			$params[] = $listId;
		}

		if ('' !== $year) {
			$where[] = 'i.year = ?';
			$params[] = $year;
		}

		if ('' !== $query) {
			$like = array();

			foreach (array('name', 'verein', 'place') as $column) {
				if (\in_array($column, $columns, true)) {
					$like[] = 'i.'.$column.' LIKE ?';
					$params[] = '%'.addcslashes($query, '%_\\').'%';
				}
			}

			$where[] = '('.implode(' OR ', $like).')';
		}

		$rows = $this->connection->fetchAllAssociative(
			'SELECT '.implode(', ', array_map(static fn (string $column): string => 'i.'.$column, $columns)).', l.title AS listTitle FROM tl_championslists_items i JOIN tl_championslists l ON l.id = i.pid WHERE '.implode(' AND ', $where).' ORDER BY i.year DESC, i.number ASC LIMIT '.self::clampLimit($limit),
			$params
		);

		$entries = array();

		foreach ($rows as $row) {
			$entry = array('list' => self::plain((string) $row['listTitle']));

			foreach ($columns as $column) {
				$value = $this->formatter->formatValue($column, $row[$column], (array) ($itemsDca['fields'][$column] ?? array()));

				if (null !== $value && '' !== $value && false !== $value) {
					$entry[$column] = $value;
				}
			}

			$entries[] = $entry;
		}

		return array('count' => \count($entries), 'entries' => $entries);
	}

	/**
	 * Listet die lesbaren Meisterlisten.
	 *
	 * @return list<array<string, mixed>> Je Liste id, title, typ, published, entries, lastYear
	 */
	private function listLists(): array
	{
		$sql = 'SELECT l.id, l.title, l.typ, l.published, COUNT(i.id) AS entries, MAX(i.year) AS lastYear FROM tl_championslists l LEFT JOIN tl_championslists_items i ON i.pid = l.id';
		$params = array();

		if (null !== ($condition = $this->tableAccess->getRowCondition('tl_championslists'))) {
			$sql .= ' WHERE '.str_replace('id IN', 'l.id IN', $condition[0]);
			$params = $condition[1];
		}

		$lists = array();

		foreach ($this->connection->fetchAllAssociative($sql.' GROUP BY l.id, l.title, l.typ, l.published ORDER BY l.title', $params) as $row) {
			$lists[] = array(
				'id' => (int) $row['id'],
				'title' => self::plain((string) $row['title']),
				'typ' => $row['typ'],
				'published' => (bool) $row['published'],
				'entries' => (int) $row['entries'],
				'lastYear' => $row['lastYear'],
			);
		}

		return $lists;
	}

	/**
	 * Wandelt Entities wie „&#40;“ in Zeichen.
	 *
	 * @param string $text Text aus der Datenbank
	 *
	 * @return string Der lesbare Text
	 */
	private static function plain(string $text): string
	{
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
