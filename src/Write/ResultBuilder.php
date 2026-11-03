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
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Baut die Antwort der schreibenden Werkzeuge.
 *
 * Claude bekommt den Datensatz nach dem Speichern so zurück, wie record_get
 * ihn liefert, damit es das Ergebnis prüfen kann, ohne noch einmal zu lesen.
 * Dazu kommt ein Link ins Backend, über den ein Mensch nachsehen kann.
 */
class ResultBuilder
{
	/**
	 * @param TableAccess           $tableAccess Felder der Palette
	 * @param RecordFormatter       $formatter   Werte wie bei record_get
	 * @param Connection            $connection  Liest den Datensatz
	 * @param Permissions           $permissions Modulrechte für den Link
	 * @param UrlGeneratorInterface $router      Baut den Backend-Link
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly RecordFormatter $formatter,
		private readonly Connection $connection,
		private readonly Permissions $permissions,
		private readonly UrlGeneratorInterface $router,
	) {
	}

	/**
	 * Beschreibt einen Datensatz nach dem Schreiben.
	 *
	 * @param string               $table  Tabelle
	 * @param int                  $id     ID
	 * @param string               $action „created“, „updated“, „published“ …
	 * @param array<string, mixed> $extra  Weitere Angaben für Claude
	 *
	 * @return array<string, mixed> table, id, action, record, backendUrl und $extra
	 */
	public function build(string $table, int $id, string $action, array $extra = array()): array
	{
		$result = array('table' => $table, 'id' => $id, 'action' => $action);
		$row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id));
		$dca = $this->tableAccess->loadDca($table);

		if (false !== $row && null !== $dca) {
			$result['record'] = $this->formatter->format($row, $this->tableAccess->getVisibleFields($table, $dca, $row), true);
			$result['backendUrl'] = $this->backendUrl($table, $id, $dca, $row);
		}

		return array_merge($result, $extra);
	}

	/**
	 * Baut den Link zum Bearbeiten im Backend.
	 *
	 * Contao 4.13 verlangt beim Öffnen noch das Request-Token und fragt
	 * deshalb einmal nach, bevor die Maske erscheint; Contao 5 öffnet sie direkt.
	 *
	 * @param string               $table Tabelle
	 * @param int                  $id    ID
	 * @param array<string, mixed> $dca   DCA der Tabelle
	 * @param array<string, mixed> $row   Der Datensatz
	 *
	 * @return string|null Der Link, oder null ohne passendes Modul
	 */
	private function backendUrl(string $table, int $id, array $dca, array $row): ?string
	{
		$parentTable = null;

		if ($dca['config']['dynamicPtable'] ?? false) {
			$parentTable = '' === (string) ($row['ptable'] ?? '') ? 'tl_article' : (string) $row['ptable'];
		}

		foreach ($GLOBALS['BE_MOD'] ?? array() as $group) {
			foreach ((array) $group as $name => $config) {
				$tables = (array) ($config['tables'] ?? array());

				if (!\in_array($table, $tables, true) || (null !== $parentTable && !\in_array($parentTable, $tables, true))) {
					continue;
				}

				if (!$this->permissions->canAccessModule((string) $name)) {
					continue;
				}

				$query = array('do' => $name, 'act' => 'edit', 'id' => $id);

				if ($tables[0] !== $table) {
					$query['table'] = $table;
				}

				return $this->router->generate('contao_backend', $query, UrlGeneratorInterface::ABSOLUTE_URL);
			}
		}

		return null;
	}
}
