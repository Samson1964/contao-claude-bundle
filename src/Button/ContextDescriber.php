<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Button;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Symfony\Component\HttpFoundation\Request;

/**
 * Beschreibt in Worten, wo im Backend sich der Benutzer gerade befindet.
 *
 * Aus der Adresse (do, table, id, act, pid) wird ein Text wie
 * „Modul Nachrichten, Nachricht 123 ‚Titel‘ (tl_news) in ‚Aktuelles‘
 * (tl_news_archive 3), wird gerade bearbeitet“. Claude bekommt damit die
 * Tabellen und IDs, die es für seine Werkzeuge braucht, und der Mensch
 * sieht im Eingabefeld, worauf sich seine Anfrage bezieht.
 *
 * Namen von Datensätzen erscheinen nur, wenn der Benutzer sie auch über die
 * Werkzeuge lesen dürfte (TableAccess); sonst bleibt es bei Tabelle und ID.
 */
class ContextDescriber
{
	/**
	 * Felder, die einen Datensatz benennen, in dieser Reihenfolge versucht.
	 */
	private const NAME_FIELDS = array('headline', 'title', 'name', 'question', 'subject', 'firstname');

	/**
	 * Aktionen, bei denen „id“ den Datensatz selbst meint; sonst den Elterndatensatz.
	 */
	private const RECORD_ACTIONS = array('edit', 'show', 'copy', 'cut', 'delete');

	/**
	 * @param TableAccess $tableAccess Rechte und DCA
	 * @param Connection  $connection  Liest Namen und Eltern
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Beschreibt die aktuelle Backend-Seite.
	 *
	 * @param Request $request Die Backend-Anfrage
	 *
	 * @return list<string> Zeilen der Beschreibung, leer auf der Startseite
	 */
	public function describeBackend(Request $request): array
	{
		$do = (string) $request->query->get('do', '');

		if ('' === $do) {
			return array();
		}

		$lines = array();
		$module = $this->findModule($do);
		$label = $GLOBALS['TL_LANG']['MOD'][$do][0] ?? $GLOBALS['TL_LANG']['MOD'][$do] ?? $do;
		$lines[] = sprintf('Backend-Modul: %s (do=%s)', \is_string($label) ? $label : $do, $do);

		$tables = (array) ($module['tables'] ?? array());
		$table = (string) $request->query->get('table', $tables[0] ?? '');
		$id = (int) $request->query->get('id', 0);
		$act = (string) $request->query->get('act', '');

		if ('' === $table || $id < 1) {
			return $lines;
		}

		if (\in_array($act, self::RECORD_ACTIONS, true)) {
			$record = $this->describeRecord($table, $id);

			if (null !== $record) {
				$lines[] = ('edit' === $act ? 'Wird gerade bearbeitet: ' : 'Datensatz: ').$record;
			}

			return $lines;
		}

		// Listenansicht einer Kindtabelle: id ist der Elterndatensatz
		$parentTable = $this->getParentTable($table, $tables, $request);

		if (null !== $parentTable && null !== ($parent = $this->describeRecord($parentTable, $id))) {
			$lines[] = sprintf('Liste von %s unter: %s', $table, $parent);
		}

		return $lines;
	}

	/**
	 * Beschreibt einen Datensatz mit Name und Elterndatensatz.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return string|null Etwa „tl_news 123 ‚Titel‘ in tl_news_archive 3 ‚Aktuelles‘“,
	 *                     oder null, wenn der Datensatz nicht lesbar ist
	 */
	public function describeRecord(string $table, int $id): ?string
	{
		try {
			$dca = $this->tableAccess->assertReadable($table);
		} catch (ToolCallException) {
			return null;
		}

		if (!$this->tableAccess->canReadRow($table, $id)) {
			return null;
		}

		$row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id));

		if (false === $row) {
			return null;
		}

		$text = sprintf('%s %d', $table, $id).$this->nameOf($row);

		if (!empty($row['type'])) {
			$text .= sprintf(' (Typ %s)', $row['type']);
		}

		$parentTable = $dca['config']['ptable'] ?? null;

		if ($dca['config']['dynamicPtable'] ?? false) {
			$parentTable = '' === (string) ($row['ptable'] ?? '') ? 'tl_article' : (string) $row['ptable'];
		}

		if (\is_string($parentTable) && '' !== $parentTable && (int) ($row['pid'] ?? 0) > 0) {
			$parent = $this->connection->fetchAssociative('SELECT * FROM '.$parentTable.' WHERE id = ?', array((int) $row['pid']));

			if (false !== $parent) {
				$text .= sprintf(' in %s %d', $parentTable, $row['pid']).$this->nameOf($parent);
			}
		}

		return $text;
	}

	/**
	 * Liefert den Namen eines Datensatzes in Anführungszeichen.
	 *
	 * @param array<string, mixed> $row Der Datensatz
	 *
	 * @return string „ ‚Name‘“ oder leer
	 */
	private function nameOf(array $row): string
	{
		foreach (self::NAME_FIELDS as $field) {
			$value = $row[$field] ?? null;

			if (null === $value || '' === $value) {
				continue;
			}

			// Überschriften der Inhaltselemente sind serialisiert (inputUnit)
			$deserialized = StringUtil::deserialize($value);

			if (\is_array($deserialized)) {
				$value = (string) ($deserialized['value'] ?? '');
			}

			$value = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

			if ('' !== $value) {
				return ' „'.mb_strimwidth($value, 0, 80, '…').'“';
			}
		}

		return '';
	}

	/**
	 * Ermittelt die Elterntabelle einer Listenansicht.
	 *
	 * @param string       $table   Angezeigte Tabelle
	 * @param list<string> $tables  Tabellen des Moduls
	 * @param Request      $request Die Anfrage (ptable bei wechselnder Elterntabelle)
	 *
	 * @return string|null Die Elterntabelle, oder null ohne
	 */
	private function getParentTable(string $table, array $tables, Request $request): ?string
	{
		$dca = $this->tableAccess->loadDca($table) ?? array();

		if ($dca['config']['dynamicPtable'] ?? false) {
			$position = array_search($table, $tables, true);

			return (string) ($request->query->get('ptable') ?: (false !== $position && $position > 0 ? $tables[$position - 1] : 'tl_article'));
		}

		$parent = $dca['config']['ptable'] ?? null;

		return \is_string($parent) && '' !== $parent ? $parent : null;
	}

	/**
	 * Sucht die Konfiguration eines Backend-Moduls.
	 *
	 * @param string $do Modulschlüssel
	 *
	 * @return array<string, mixed> Die Konfiguration, leer bei unbekanntem Modul
	 */
	private function findModule(string $do): array
	{
		foreach ($GLOBALS['BE_MOD'] ?? array() as $group) {
			if (isset($group[$do])) {
				return (array) $group[$do];
			}
		}

		return array();
	}
}
