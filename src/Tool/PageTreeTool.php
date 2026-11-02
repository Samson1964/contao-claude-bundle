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
use Schachbulle\ContaoClaudeBundle\Security\Permissions;

/**
 * page_tree: der Seitenbaum ab einer Seite.
 */
class PageTreeTool extends AbstractReadTool
{
	/**
	 * Obergrenze an Seiten je Aufruf, damit große Bäume Claude nicht fluten.
	 */
	private const MAX_PAGES = 500;

	private int $count = 0;
	private bool $truncated = false;

	/**
	 * @param TableAccess $tableAccess Prüft das Recht auf tl_page
	 * @param Permissions $permissions Liefert die Seitenfreigaben
	 * @param Connection  $connection  Liest die Seiten
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly Permissions $permissions,
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
		return 'page_tree';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Seitenbaum';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Liefert den Seitenbaum ab einer Seite (oder ab den Wurzelseiten) mit ID, Titel, Alias, Seitentyp, Veröffentlichung, „im Menü verstecken“ und Anzeigezeitraum. Seiten unterhalb der gewünschten Tiefe erscheinen nur als Anzahl (child_count). Höchstens 500 Seiten je Aufruf.';
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
				'parentId' => array('type' => 'integer', 'minimum' => 0, 'description' => 'ID der Seite, deren Unterseiten geliefert werden. 0 oder weglassen: ab den Wurzelseiten bzw. den freigegebenen Einstiegsseiten.'),
				'depth' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Wie viele Ebenen tief, Vorgabe 3.'),
			),
		);
	}

	/**
	 * Liefert den Seitenbaum.
	 *
	 * @param int $parentId Ausgangsseite, 0 für die oberste erreichbare Ebene
	 * @param int $depth    Anzahl der Ebenen, 1 bis 10
	 *
	 * @return array<string, mixed> pages (verschachtelt) und truncated
	 */
	public function __invoke(int $parentId = 0, int $depth = 3): array
	{
		$this->tableAccess->assertReadable('tl_page');

		$depth = max(1, min(10, $depth));
		$allowed = $this->permissions->getPageIds();
		$this->count = 0;
		$this->truncated = false;

		if ($parentId > 0) {
			if (null !== $allowed && !\in_array($parentId, $allowed, true)) {
				throw new ToolCallException(sprintf('Die Seite %d gibt es nicht oder sie liegt außerhalb deiner Seitenfreigaben.', $parentId));
			}

			$pages = $this->loadChildren(array($parentId), $allowed);
		} elseif (null === $allowed) {
			$pages = $this->loadChildren(array(0), null);
		} else {
			// Einstiegsseiten: freigegeben, aber die Elternseite nicht
			$pages = array();

			if (array() !== $allowed) {
				$rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_page WHERE id IN ('.implode(',', $allowed).') ORDER BY sorting');
				$flip = array_flip($allowed);

				foreach ($rows as $row) {
					if (!isset($flip[(int) $row['pid']])) {
						$pages[] = $row;
					}
				}
			}
		}

		return array(
			'pages' => $this->buildLevel($pages, $depth, $allowed),
			'truncated' => $this->truncated,
		);
	}

	/**
	 * Baut eine Ebene des Baums und steigt rekursiv ab.
	 *
	 * @param array<int, array<string, mixed>> $rows    Die Seiten dieser Ebene
	 * @param int                              $depth   Verbleibende Ebenen inklusive dieser
	 * @param array<int>|null                  $allowed Freigegebene Seiten, null für alle
	 *
	 * @return array<int, array<string, mixed>> Die aufbereiteten Seiten
	 */
	private function buildLevel(array $rows, int $depth, ?array $allowed): array
	{
		$result = array();
		$children = array();

		if ($depth > 1 && array() !== $rows) {
			foreach ($this->loadChildren(array_map(static fn (array $row): int => (int) $row['id'], $rows), $allowed) as $child) {
				$children[(int) $child['pid']][] = $child;
			}
		}

		foreach ($rows as $row) {
			if ($this->count >= self::MAX_PAGES) {
				$this->truncated = true;
				break;
			}

			++$this->count;

			$page = array(
				'id' => (int) $row['id'],
				'title' => (string) $row['title'],
				'alias' => (string) $row['alias'],
				'type' => (string) $row['type'],
				'published' => (bool) $row['published'],
				'hide' => (bool) ($row['hide'] ?? false),
				'start' => RecordFormatter::formatDate($row['start'] ?? null),
				'stop' => RecordFormatter::formatDate($row['stop'] ?? null),
			);

			if ('root' === $row['type']) {
				$page['dns'] = (string) ($row['dns'] ?? '');
				$page['language'] = (string) ($row['language'] ?? '');
			}

			if ($depth > 1) {
				$page['children'] = $this->buildLevel($children[(int) $row['id']] ?? array(), $depth - 1, $allowed);
			} else {
				$page['child_count'] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page WHERE pid = ?', array((int) $row['id']));
			}

			$result[] = $page;
		}

		return $result;
	}

	/**
	 * Lädt die Unterseiten mehrerer Seiten in einer Abfrage.
	 *
	 * @param array<int>      $parentIds IDs der Elternseiten (0 für die Wurzelebene)
	 * @param array<int>|null $allowed   Freigegebene Seiten, null für alle
	 *
	 * @return array<int, array<string, mixed>> Die Seiten, nach sorting geordnet
	 */
	private function loadChildren(array $parentIds, ?array $allowed): array
	{
		$sql = 'SELECT * FROM tl_page WHERE pid IN ('.implode(',', array_map('intval', $parentIds)).')';

		if (null !== $allowed) {
			$sql .= ' AND '.(array() === $allowed ? '1 = 0' : 'id IN ('.implode(',', $allowed).')');
		}

		return $this->connection->fetchAllAssociative($sql.' ORDER BY sorting');
	}
}
