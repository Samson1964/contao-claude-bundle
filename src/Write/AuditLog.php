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
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoClaudeBundle\Mcp\CallContext;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;

/**
 * Schreibt das Protokoll der schreibenden Werkzeuge (tl_claude_log).
 *
 * Protokolliert werden Zeit, Benutzer, Verbindung, Werkzeug, Tabelle, ID,
 * die Namen der übergebenen Felder und das Ergebnis, nie Inhalte. Ein
 * fehlgeschlagener Eintrag darf den Schreibvorgang nicht scheitern lassen;
 * er landet dann im Symfony-Protokoll.
 */
class AuditLog
{
	/**
	 * Wie lange Einträge aufbewahrt werden, in Sekunden.
	 */
	public const RETENTION = 180 * 86400;

	/**
	 * @param Connection      $connection  Schreibt die Zeile
	 * @param Permissions     $permissions Liefert den Benutzer
	 * @param CallContext     $context     Liefert die Verbindung
	 * @param LoggerInterface $logger      Für Fehler beim Protokollieren
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Permissions $permissions,
		private readonly CallContext $context,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Legt einen Protokolleintrag an.
	 *
	 * Muss außerhalb der Transaktion des Schreibvorgangs aufgerufen werden,
	 * sonst verschwände der Eintrag eines abgewiesenen Versuchs mit dem
	 * Zurückrollen.
	 *
	 * @param string       $tool     Werkzeugname, etwa „content_update“
	 * @param string       $table    Tabelle
	 * @param int          $recordId ID des Datensatzes, 0 wenn keiner entstand
	 * @param list<string> $fields   Namen der übergebenen Felder
	 * @param bool         $success  Ob der Vorgang gelungen ist
	 * @param string       $message  Kurze Meldung, bei Fehlern der Grund
	 */
	public function add(string $tool, string $table, int $recordId, array $fields, bool $success, string $message = ''): void
	{
		try {
			$user = $this->permissions->getUser();
			$client = $this->context->hasClient() ? $this->context->getClient()->clientName : '';

			$this->connection->insert('tl_claude_log', array(
				'tstamp' => time(),
				'userId' => (int) $user->id,
				'username' => mb_substr((string) $user->username, 0, 64),
				'clientName' => mb_substr($client, 0, 64),
				'tool' => mb_substr($tool, 0, 64),
				'tableName' => mb_substr($table, 0, 64),
				'recordId' => $recordId,
				'fields' => implode(', ', $fields),
				'result' => $success ? 'ok' : 'abgewiesen',
				'message' => mb_substr($message, 0, 255),
			));
		} catch (\Throwable $exception) {
			$this->logger->error('Claude-Protokoll konnte nicht geschrieben werden: '.$exception->getMessage(), array('exception' => $exception));
		}
	}

	/**
	 * Löscht Einträge, die älter als die Aufbewahrungsfrist sind.
	 *
	 * @param int $now Aktueller Zeitstempel
	 *
	 * @return int Anzahl gelöschter Einträge
	 */
	public function cleanup(int $now): int
	{
		return (int) $this->connection->executeStatement('DELETE FROM tl_claude_log WHERE tstamp < ?', array($now - self::RETENTION));
	}
}
