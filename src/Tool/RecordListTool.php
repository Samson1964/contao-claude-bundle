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
 * record_list: Datensätze einer beliebigen Tabelle filtern und blättern.
 *
 * Feldnamen aus den Argumenten landen nur dann im SQL, wenn sie unter den
 * lesbaren Feldern der Tabelle stehen; Werte werden immer gebunden.
 */
class RecordListTool extends AbstractReadTool
{
	/**
	 * Felder, die ohne Angabe von „fields“ zusätzlich ausgegeben werden,
	 * wenn die Tabelle sie hat.
	 */
	private const DEFAULT_EXTRA_FIELDS = array('pid', 'ptable', 'type', 'title', 'name', 'headline', 'alias', 'published', 'invisible', 'disable', 'start', 'stop', 'tstamp');

	/**
	 * @param TableAccess     $tableAccess Prüft Rechte und liefert die lesbaren Felder
	 * @param RecordFormatter $formatter   Bereitet die Werte auf
	 * @param Connection      $connection  Liest die Datensätze
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
		return 'record_list';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Datensätze auflisten';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Listet Datensätze einer Contao-Tabelle, auch von Erweiterungen, mit Filter auf Feldwerte, Suchtext, Sortierung und Blättern. Ohne „fields“ kommen ID und die wichtigsten Kennfelder (Titel, Name, Überschrift, Alias, Status). Zuerst mit dca_describe die Feldnamen nachsehen.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname, etwa „tl_calendar_events“.'),
				'filter' => array('type' => 'object', 'additionalProperties' => array('type' => array('string', 'integer', 'boolean')), 'description' => 'Gleichheitsfilter Feld → Wert, etwa {"pid": 3, "published": "1"}.'),
				'search' => array('type' => 'string', 'description' => 'Suchtext; gesucht wird in den Feldern, die das DCA als durchsuchbar markiert, sonst in den Listenfeldern.'),
				'fields' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Diese Felder ausgeben statt der Vorgabe.'),
				'order' => array('type' => 'string', 'description' => 'Sortierung, etwa „tstamp desc“ oder „title“.'),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Datensätze je Seite, Vorgabe 20.'),
				'offset' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Überspringt so viele Datensätze (zum Blättern).'),
			),
			'required' => array('table'),
		);
	}

	/**
	 * Listet die Datensätze.
	 *
	 * @param string              $table  Tabellenname
	 * @param array<string,mixed> $filter Feld => Wert, jeweils auf Gleichheit
	 * @param string              $search Suchtext
	 * @param array<string>       $fields Auszugebende Felder
	 * @param string              $order  „feld“ oder „feld desc“
	 * @param int                 $limit  Datensätze je Seite
	 * @param int                 $offset Zu überspringende Datensätze
	 *
	 * @return array<string, mixed> table, total, offset, records
	 *
	 * @throws ToolCallException Bei unbekannten oder nicht sichtbaren Feldern
	 *                           in filter, fields oder order
	 */
	public function __invoke(string $table, array $filter = array(), string $search = '', array $fields = array(), string $order = '', int $limit = 20, int $offset = 0): array
	{
		$dca = $this->tableAccess->assertReadable($table);
		$readable = $this->tableAccess->getReadableFields($table, $dca);

		$where = array();
		$params = array();
		$condition = $this->tableAccess->getRowCondition($table);

		if (null !== $condition) {
			$where[] = '('.$condition[0].')';
			$params = array_merge($params, $condition[1]);
		}

		foreach ($filter as $field => $value) {
			$field = (string) $field;
			$this->assertField($table, $field, $readable);

			$where[] = $field.' = ?';
			$params[] = \is_bool($value) ? ($value ? '1' : '') : (string) $value;
		}

		if ('' !== trim($search)) {
			$searchFields = $this->getSearchFields($dca, $readable);

			if (array() === $searchFields) {
				throw new ToolCallException(sprintf('Die Tabelle %s hat keine durchsuchbaren Felder. Nutze „filter“.', $table));
			}

			$like = '%'.addcslashes(trim($search), '%_\\').'%';
			$where[] = '('.implode(' OR ', array_map(static fn (string $field): string => $field.' LIKE ?', $searchFields)).')';
			$params = array_merge($params, array_fill(0, \count($searchFields), $like));
		}

		$output = $this->getOutputFields($table, $dca, $readable, $fields);
		$sqlWhere = array() === $where ? '' : ' WHERE '.implode(' AND ', $where);
		$limit = self::clampLimit($limit);
		$offset = max(0, $offset);

		$total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.$sqlWhere, $params);
		$rows = $this->connection->fetchAllAssociative('SELECT * FROM '.$table.$sqlWhere.' ORDER BY '.$this->getOrder($table, $dca, $readable, $order).' LIMIT '.$limit.' OFFSET '.$offset, $params);

		return array(
			'table' => $table,
			'total' => $total,
			'offset' => $offset,
			'records' => array_map(fn (array $row): array => $this->formatter->format($row, $output), $rows),
		);
	}

	/**
	 * Prüft, ob ein Feldname aus den Argumenten verwendet werden darf.
	 *
	 * @param string                              $table    Tabellenname für die Meldung
	 * @param string                              $field    Der Feldname
	 * @param array<string, array<string, mixed>> $readable Die lesbaren Felder
	 *
	 * @throws ToolCallException Wenn das Feld unbekannt oder nicht freigegeben ist
	 */
	private function assertField(string $table, string $field, array $readable): void
	{
		if (!isset($readable[$field])) {
			throw new ToolCallException(sprintf('Das Feld „%s“ gibt es in %s nicht, oder es ist für dich nicht sichtbar.', $field, $table));
		}
	}

	/**
	 * Bestimmt die Felder für die Textsuche.
	 *
	 * @param array<string, mixed>                $dca      DCA der Tabelle
	 * @param array<string, array<string, mixed>> $readable Die lesbaren Felder
	 *
	 * @return list<string> Durchsuchbare Felder; ersatzweise die Listenfelder
	 */
	private function getSearchFields(array $dca, array $readable): array
	{
		$fields = array();

		foreach ($readable as $name => $config) {
			if (!empty($config['search'])) {
				$fields[] = $name;
			}
		}

		if (array() === $fields) {
			foreach ((array) ($dca['list']['label']['fields'] ?? array()) as $name) {
				if (isset($readable[$name]) && !\in_array($name, TableAccess::SYSTEM_COLUMNS, true)) {
					$fields[] = (string) $name;
				}
			}
		}

		return $fields;
	}

	/**
	 * Bestimmt die auszugebenden Felder.
	 *
	 * @param string                              $table     Tabellenname für die Meldung
	 * @param array<string, mixed>                $dca       DCA der Tabelle
	 * @param array<string, array<string, mixed>> $readable  Die lesbaren Felder
	 * @param array<string>                       $requested Felder aus dem Argument
	 *
	 * @return array<string, array<string, mixed>> Feldname => DCA-Definition
	 */
	private function getOutputFields(string $table, array $dca, array $readable, array $requested): array
	{
		$names = array('id');

		if (array() !== $requested) {
			foreach ($requested as $field) {
				$this->assertField($table, (string) $field, $readable);
				$names[] = (string) $field;
			}
		} else {
			$names = array_merge($names, (array) ($dca['list']['label']['fields'] ?? array()), self::DEFAULT_EXTRA_FIELDS);
		}

		$output = array();

		foreach ($names as $name) {
			if (isset($readable[$name])) {
				$output[$name] = $readable[$name];
			}
		}

		return $output;
	}

	/**
	 * Baut die ORDER-BY-Klausel.
	 *
	 * Ohne Angabe gilt die erste Sortierung des DCA (list.sorting.fields),
	 * sonst die ID.
	 *
	 * @param string                              $table    Tabellenname für die Meldung
	 * @param array<string, mixed>                $dca      DCA der Tabelle
	 * @param array<string, array<string, mixed>> $readable Die lesbaren Felder
	 * @param string                              $order    „feld“ oder „feld desc“
	 *
	 * @return string Die Klausel ohne „ORDER BY“
	 */
	private function getOrder(string $table, array $dca, array $readable, string $order): string
	{
		$order = trim($order);

		if ('' === $order) {
			foreach ((array) ($dca['list']['sorting']['fields'] ?? array()) as $candidate) {
				// Einträge wie „date DESC“ oder „sorting“
				[$field] = explode(' ', trim((string) $candidate)) + array('');

				if (isset($readable[$field])) {
					return trim((string) $candidate).', id';
				}
			}

			return 'id';
		}

		if (!preg_match('/^([a-zA-Z0-9_]+)(?:\s+(asc|desc))?$/i', $order, $match)) {
			throw new ToolCallException('„order“ hat die Form „feld“ oder „feld desc“.');
		}

		$this->assertField($table, $match[1], $readable);

		return $match[1].' '.strtoupper($match[2] ?? 'asc').', id';
	}
}
