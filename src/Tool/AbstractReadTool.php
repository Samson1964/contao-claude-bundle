<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;

/**
 * Gemeinsame Grundlage der lesenden Werkzeuge.
 *
 * Lesende Werkzeuge ändern nichts, liefern bei gleichem Aufruf dasselbe und
 * greifen nur auf diese Contao-Installation zu – entsprechend die Hinweise
 * readOnly, idempotent und nicht „open world“.
 */
abstract class AbstractReadTool implements ToolInterface
{
	/**
	 * Höchstzahl an Einträgen, die eine Liste auf einmal liefern darf.
	 */
	protected const MAX_LIMIT = 100;

	/**
	 * Liefert die Hinweise für ein lesendes Werkzeug.
	 *
	 * @return ToolAnnotations readOnlyHint, idempotentHint, kein destructiveHint
	 */
	public function getAnnotations(): ToolAnnotations
	{
		return new ToolAnnotations($this->getTitle(), true, false, true, false);
	}

	/**
	 * Lesende Werkzeuge brauchen nur den Scope „contao:read“.
	 *
	 * @return string Der Scope
	 */
	public function getRequiredScope(): string
	{
		return AuthenticatedClient::SCOPE_READ;
	}

	/**
	 * Begrenzt eine Seitengröße auf einen sinnvollen Bereich.
	 *
	 * @param int $limit Der gewünschte Wert
	 *
	 * @return int Ein Wert zwischen 1 und MAX_LIMIT
	 */
	protected static function clampLimit(int $limit): int
	{
		return max(1, min(static::MAX_LIMIT, $limit));
	}

	/**
	 * Wandelt eine Datumsangabe aus einem Argument in einen Zeitstempel.
	 *
	 * @param string|null $date  „JJJJ-MM-TT“ oder leer
	 * @param bool        $endOfDay true: 23:59:59 des Tages statt 0:00 Uhr
	 *
	 * @return int|null Der Zeitstempel, oder null bei leerer Angabe
	 *
	 * @throws ToolCallException Bei einem Datum in anderer Schreibweise
	 */
	protected static function parseDate(?string $date, bool $endOfDay = false): ?int
	{
		if (null === $date || '' === trim($date)) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

		if (false === $parsed) {
			throw new ToolCallException(sprintf('„%s“ ist kein Datum in der Form JJJJ-MM-TT.', $date));
		}

		return $endOfDay ? $parsed->setTime(23, 59, 59)->getTimestamp() : $parsed->getTimestamp();
	}

	/**
	 * Prüft, ob ein Datensatz mit published/start/stop gerade sichtbar ist.
	 *
	 * Dieselbe Regel wie im Frontend: veröffentlicht, Start erreicht, Ende
	 * nicht überschritten. Leere Start- und Endwerte zählen als „offen“.
	 *
	 * @param array<string, mixed> $row  Zeile mit published, start, stop
	 * @param string               $flag Name des Veröffentlichungsfeldes
	 *
	 * @return bool true, wenn der Datensatz für Besucher sichtbar ist
	 */
	protected static function isVisible(array $row, string $flag = 'published'): bool
	{
		$now = time();

		if (!(bool) ($row[$flag] ?? false)) {
			return false;
		}

		if ('' !== (string) ($row['start'] ?? '') && (int) $row['start'] > $now) {
			return false;
		}

		return !('' !== (string) ($row['stop'] ?? '') && (int) $row['stop'] > 0 && (int) $row['stop'] <= $now);
	}
}
