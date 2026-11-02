<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * record_history: die Versionen eines Datensatzes aus tl_version.
 *
 * tl_version selbst steht auf der Sperrliste; dieses Werkzeug liest es nur
 * für Datensätze, die der Benutzer ohnehin sehen darf, und filtert die
 * Felder der gespeicherten Fassung genauso wie record_get.
 */
class RecordHistoryTool extends AbstractReadTool
{
	/**
	 * @param TableAccess     $tableAccess Prüft Tabellen- und Datensatzrecht
	 * @param RecordFormatter $formatter   Bereitet die Felder einer Fassung auf
	 * @param Connection      $connection  Liest tl_version
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
		return 'record_history';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Versionen eines Datensatzes';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Listet die gespeicherten Versionen eines Datensatzes (Nummer, Zeitpunkt, Benutzer, aktive Fassung), neueste zuerst. Mit version wird der Inhalt genau dieser Fassung geliefert, etwa um Änderungen nachzuvollziehen.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname, etwa „tl_news“.'),
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Datensatzes.'),
				'version' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Nummer der Version, deren Inhalt geliefert werden soll.'),
			),
			'required' => array('table', 'id'),
		);
	}

	/**
	 * Liefert die Versionsliste oder eine einzelne Fassung.
	 *
	 * @param string $table   Tabellenname
	 * @param int    $id      ID des Datensatzes
	 * @param int    $version Nummer der Version, 0 für die Liste
	 *
	 * @return array<string, mixed> versions, oder version samt data
	 */
	public function __invoke(string $table, int $id, int $version = 0): array
	{
		$dca = $this->tableAccess->assertReadable($table);

		if (!$this->tableAccess->canReadRow($table, $id)) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $id, $table));
		}

		if ($version > 0) {
			$row = $this->connection->fetchAssociative('SELECT * FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?', array($table, $id, $version));

			if (false === $row) {
				throw new ToolCallException(sprintf('Version %d von %s.%d gibt es nicht.', $version, $table, $id));
			}

			$data = self::decodeData($row['data'] ?? null);

			return array(
				'table' => $table,
				'id' => $id,
				'version' => self::describe($row),
				'data' => $this->formatter->format($data, $this->tableAccess->getVisibleFields($table, $dca, $data), true),
			);
		}

		$rows = $this->connection->fetchAllAssociative('SELECT id, pid, tstamp, version, username, userid, description, active FROM tl_version WHERE fromTable = ? AND pid = ? ORDER BY version DESC', array($table, $id));

		return array(
			'table' => $table,
			'id' => $id,
			'versions' => array_map(static fn (array $row): array => self::describe($row), $rows),
		);
	}

	/**
	 * Beschreibt eine Version ohne ihren Inhalt.
	 *
	 * @param array<string, mixed> $row Zeile aus tl_version
	 *
	 * @return array<string, mixed> version, date, username, description, active
	 */
	private static function describe(array $row): array
	{
		return array(
			'version' => (int) $row['version'],
			'date' => RecordFormatter::formatDate($row['tstamp']),
			'username' => (string) $row['username'],
			'description' => '' === (string) ($row['description'] ?? '') ? null : (string) $row['description'],
			'active' => (bool) $row['active'],
		);
	}

	/**
	 * Packt den gespeicherten Inhalt einer Version aus.
	 *
	 * Contao speichert ihn serialisiert; vorsorglich wird auch JSON erkannt.
	 *
	 * @param mixed $data Die Spalte data aus tl_version
	 *
	 * @return array<string, mixed> Die Zeile, wie sie zum Zeitpunkt der Version aussah
	 */
	private static function decodeData($data): array
	{
		$decoded = StringUtil::deserialize($data);

		if (\is_string($decoded) && str_starts_with(ltrim($decoded), '{')) {
			$decoded = json_decode($decoded, true);
		}

		return \is_array($decoded) ? $decoded : array();
	}
}
