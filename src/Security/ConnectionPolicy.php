<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Entscheidet, ob ein Backend-Benutzer Claude über OAuth verbinden darf.
 *
 * Geprüft wird bei der Bestätigung, bei jedem Schlüsseltausch und bei jeder
 * Anfrage an /mcp – so wirkt eine geänderte Einstellung oder Gruppe sofort
 * auch auf bestehende Verbindungen. Die Regel liest tl_user direkt und
 * braucht deshalb keinen angemeldeten Benutzer.
 */
class ConnectionPolicy
{
	/**
	 * @param Connection $connection Liest den Benutzer
	 * @param Settings   $settings   Schalter für Administratoren und erlaubte Gruppen
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Settings $settings,
	) {
	}

	/**
	 * Prüft einen Benutzer.
	 *
	 * Reihenfolge: Den Benutzer gibt es und er ist aktiv (deaktiviert,
	 * start/stop, unter 4.13 auch nach Fehlversuchen gesperrt);
	 * Administratoren nur mit Schalter; alle anderen nur als Mitglied einer
	 * erlaubten Gruppe, sofern Gruppen gewählt sind.
	 *
	 * Gelesen wird die ganze Zeile: Die Spalte „locked“ gibt es nur in
	 * Contao 4.13; Contao 5 drosselt Fehlversuche über Symfony und hat sie
	 * aus tl_user entfernt. Eine Abfrage, die sie nennt, scheiterte dort.
	 *
	 * @param int $userId ID aus tl_user
	 *
	 * @return string|null null, wenn der Benutzer sich verbinden darf, sonst
	 *                     die Begründung für ihn
	 */
	public function check(int $userId): ?string
	{
		$user = $this->connection->fetchAssociative('SELECT * FROM tl_user WHERE id = ?', array($userId));

		if (false === $user) {
			return 'Diesen Backend-Zugang gibt es nicht mehr.';
		}

		$now = time();

		if ($user['disable'] || ('' !== (string) $user['start'] && (int) $user['start'] > $now) || ((int) $user['stop'] > 0 && (int) $user['stop'] <= $now) || (int) ($user['locked'] ?? 0) > $now) {
			return 'Dein Backend-Zugang ist deaktiviert oder gesperrt.';
		}

		if ($user['admin']) {
			return $this->settings->allowsAdmins()
				? null
				: 'Administratoren dürfen sich in dieser Installation nicht mit Claude verbinden. Melde dich mit einem eigenen Zugang mit eingeschränkter Gruppe an.';
		}

		$allowed = $this->settings->getAllowedGroups();

		if (array() === $allowed) {
			return null;
		}

		$groups = array_map('intval', StringUtil::deserialize($user['groups'], true));

		return array() === array_intersect($allowed, $groups)
			? 'Dein Backend-Zugang gehört zu keiner Gruppe, die Claude verbinden darf.'
			: null;
	}
}
