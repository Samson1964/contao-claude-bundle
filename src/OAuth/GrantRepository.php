<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

use Doctrine\DBAL\Connection;

/**
 * Liest und schreibt die Verbindungen in tl_claude_token.
 *
 * Reine Datenbankzugriffe ohne Protokollregeln; die stehen in
 * AuthorizationServer. Verwendet nur Aufrufe, die Doctrine DBAL 3
 * (Contao 4.13) und 4 (Contao 5.7) gleich anbieten.
 */
class GrantRepository
{
	private const TABLE = 'tl_claude_token';

	/**
	 * @param Connection $connection Die Datenbankverbindung
	 */
	public function __construct(private readonly Connection $connection)
	{
	}

	/**
	 * Legt eine Verbindung mit einem Autorisierungscode an.
	 *
	 * @param array<string, int|string> $data Spalten der neuen Zeile
	 *
	 * @return int Die ID der Zeile
	 */
	public function insert(array $data): int
	{
		$this->connection->insert(self::TABLE, $data + array('tstamp' => time()));

		return (int) $this->connection->lastInsertId();
	}

	/**
	 * Ändert Spalten einer Verbindung.
	 *
	 * @param int                       $id   ID der Verbindung
	 * @param array<string, int|string> $data Neue Werte
	 */
	public function update(int $id, array $data): void
	{
		$this->connection->update(self::TABLE, $data + array('tstamp' => time()), array('id' => $id));
	}

	/**
	 * Sucht eine Verbindung über den Hash eines ihrer Schlüssel.
	 *
	 * @param string $column Spalte: codeHash, accessHash oder refreshHash
	 * @param string $hash   Der SHA-256-Hash
	 *
	 * @return array<string, mixed>|null Die Zeile, oder null, wenn es keine gibt
	 */
	public function findByHash(string $column, string $hash): ?array
	{
		if ('' === $hash || !\in_array($column, array('codeHash', 'accessHash', 'refreshHash'), true)) {
			return null;
		}

		$row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE '.$column.' = ?', array($hash));

		return false === $row ? null : $row;
	}

	/**
	 * Sucht eine Verbindung über ihre ID.
	 *
	 * @param int $id ID der Verbindung
	 *
	 * @return array<string, mixed>|null Die Zeile, oder null
	 */
	public function find(int $id): ?array
	{
		$row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', array($id));

		return false === $row ? null : $row;
	}

	/**
	 * Widerruft eine Verbindung.
	 *
	 * Die Hashes werden geleert, damit auch eine wiederhergestellte Zeile
	 * keinen Schlüssel mehr gültig machen könnte.
	 *
	 * @param int $id ID der Verbindung
	 */
	public function revoke(int $id): void
	{
		$this->update($id, array('revoked' => '1', 'codeHash' => '', 'accessHash' => '', 'refreshHash' => '', 'accessExpires' => 0, 'refreshExpires' => 0));
	}

	/**
	 * Vermerkt die letzte Benutzung, höchstens einmal je Minute.
	 *
	 * So schreibt nicht jede MCP-Anfrage in die Datenbank.
	 *
	 * @param int $id  ID der Verbindung
	 * @param int $now Aktuelle Zeit
	 */
	public function touch(int $id, int $now): void
	{
		$this->connection->executeStatement('UPDATE '.self::TABLE.' SET lastUsed = ? WHERE id = ? AND lastUsed < ?', array($now, $id, $now - 60));
	}

	/**
	 * Liefert die Verbindungen für die Übersicht im Backend.
	 *
	 * Nicht eingelöste Codes erscheinen nicht; widerrufene und abgelaufene
	 * Verbindungen schon, bis die tägliche Bereinigung sie entfernt.
	 *
	 * @param int|null $userId Nur die Verbindungen dieses Benutzers, null für alle
	 *
	 * @return list<array<string, mixed>> Zeilen mit Benutzername und Name, neueste zuerst
	 */
	public function listForBackend(?int $userId): array
	{
		$sql = 'SELECT t.*, u.name AS userName, u.username AS userUsername FROM '.self::TABLE.' t LEFT JOIN tl_user u ON u.id = t.userId'
			." WHERE t.codeUsed = '1'";
		$params = array();

		if (null !== $userId) {
			$sql .= ' AND t.userId = ?';
			$params[] = $userId;
		}

		return array_values($this->connection->fetchAllAssociative($sql.' ORDER BY t.created DESC', $params));
	}

	/**
	 * Entfernt, was nicht mehr gebraucht wird.
	 *
	 * Gelöscht werden nicht eingelöste Codes älter als ein Tag, widerrufene
	 * oder abgelaufene Verbindungen älter als eine Woche und Verbindungen
	 * gelöschter Benutzer.
	 *
	 * @param int $now Aktuelle Zeit
	 *
	 * @return int Anzahl gelöschter Zeilen
	 */
	public function cleanup(int $now): int
	{
		$deleted = $this->connection->executeStatement('DELETE FROM '.self::TABLE." WHERE codeUsed <> '1' AND codeExpires < ?", array($now - 86400));
		$deleted += $this->connection->executeStatement('DELETE FROM '.self::TABLE." WHERE codeUsed = '1' AND (revoked = '1' OR refreshExpires < ?) AND tstamp < ?", array($now, $now - 7 * 86400));
		$deleted += $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE userId NOT IN (SELECT id FROM tl_user)');

		return (int) $deleted;
	}
}
