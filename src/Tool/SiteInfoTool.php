<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Composer\InstalledVersions;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Mcp\CallContext;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * site_info: Überblick über Installation, Benutzer und Rechte.
 *
 * Das erste Werkzeug, das Claude aufrufen sollte: Es zeigt, welche
 * Installation am anderen Ende hängt, mit welchem Backend-Zugang Claude
 * arbeitet und wo dessen Rechte beginnen (Wurzelseiten, Archive, Ordner).
 */
class SiteInfoTool extends AbstractReadTool
{
	/**
	 * @param Permissions  $permissions  Rechte des angemeldeten Benutzers
	 * @param TableAccess  $tableAccess  Sperrliste und Modulzuordnung
	 * @param Connection   $connection   Liest Gruppen, Wurzelseiten und Archive
	 * @param CallContext  $context      Der angemeldete Zugang
	 * @param RequestStack $requestStack Liefert Adresse und Host der Installation
	 */
	public function __construct(
		private readonly Permissions $permissions,
		private readonly TableAccess $tableAccess,
		private readonly Connection $connection,
		private readonly CallContext $context,
		private readonly RequestStack $requestStack,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'site_info';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Installation und Rechte';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Zeigt die Contao-Installation (Adresse, Contao- und PHP-Version), den angemeldeten Backend-Benutzer mit Gruppen und Modulrechten, die erreichbaren Wurzelseiten, Nachrichtenarchive und Dateiordner sowie die für Claude gesperrten Tabellen. Zu Beginn jeder Arbeit aufrufen.';
	}

	/**
	 * Liefert das JSON-Schema der Argumente. Die Schlüssel entsprechen den
	 * Parameternamen von __invoke(); das SDK ordnet die Werte danach zu.
	 *
	 * @return array<string, mixed> Das Schema
	 */
	public function getInputSchema(): array
	{
		return array('type' => 'object', 'properties' => new \stdClass());
	}

	/**
	 * Stellt den Überblick zusammen.
	 *
	 * @return array<string, mixed> Installation, Benutzer, Freigaben und Sperrliste
	 */
	public function __invoke(): array
	{
		$user = $this->permissions->getUser();
		$request = $this->requestStack->getCurrentRequest();
		$schema = $this->connection->createSchemaManager();

		$groups = array();
		$groupIds = array_map('intval', StringUtil::deserialize($user->groups, true));

		if (array() !== $groupIds) {
			$groups = $this->connection->fetchFirstColumn('SELECT name FROM tl_user_group WHERE id IN ('.implode(',', $groupIds).') ORDER BY name');
		}

		$modules = array();

		foreach ($GLOBALS['BE_MOD'] ?? array() as $group) {
			foreach (array_keys((array) $group) as $module) {
				if ($this->permissions->canAccessModule((string) $module)) {
					$modules[] = (string) $module;
				}
			}
		}

		$result = array(
			'installation' => array(
				'url' => null === $request ? null : $request->getSchemeAndHttpHost(),
				'host' => null === $request ? null : $request->getHost(),
				'contao' => self::packageVersion('contao/core-bundle'),
				'php' => \PHP_VERSION,
				'bundle' => self::packageVersion('schachbulle/contao-claude-bundle'),
			),
			'user' => array(
				'id' => (int) $user->id,
				'username' => (string) $user->username,
				'name' => (string) $user->name,
				'email' => (string) $user->email,
				'admin' => (bool) $user->isAdmin,
				'language' => (string) $user->language,
				'groups' => $groups,
				'modules' => $modules,
			),
			'access' => array(
				'client' => $this->context->getClient()->clientName,
				'scopes' => $this->context->getClient()->scopes,
			),
			'root_pages' => $this->getRootPages(),
			'news_archives' => $schema->tablesExist(array('tl_news_archive')) ? $this->getNewsArchives() : null,
			'file_mounts' => $this->permissions->getFileMounts() ?? array('(alle Ordner)'),
			'blocked_tables' => array_merge(TableAccess::ALWAYS_BLOCKED, $this->tableAccessExtra()),
		);

		return $result;
	}

	/**
	 * Liefert die Wurzelseiten, unter denen der Benutzer Seiten sehen darf.
	 *
	 * Für Benutzer mit Seitenfreigaben sind das die Wurzeln der Bäume, in
	 * denen ihre Freigaben liegen; die freigegebenen Einstiegsseiten selbst
	 * stehen unter „mounts“.
	 *
	 * @return array<int, array<string, mixed>> Je Wurzelseite id, title, dns, language …
	 */
	private function getRootPages(): array
	{
		$pageIds = $this->permissions->getPageIds();
		$roots = $this->connection->fetchAllAssociative("SELECT id, title, dns, language, fallback, published FROM tl_page WHERE type = 'root' ORDER BY sorting");
		$result = array();

		foreach ($roots as $root) {
			$entry = array(
				'id' => (int) $root['id'],
				'title' => (string) $root['title'],
				'dns' => (string) $root['dns'],
				'language' => (string) $root['language'],
				'fallback' => (bool) $root['fallback'],
				'published' => (bool) $root['published'],
			);

			if (null !== $pageIds) {
				$mounts = $this->getMountsBelow((int) $root['id'], $pageIds);

				if (array() === $mounts) {
					continue;
				}

				$entry['mounts'] = $mounts;
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Sucht die freigegebenen Einstiegsseiten unterhalb einer Wurzelseite.
	 *
	 * Eine Einstiegsseite ist eine freigegebene Seite, deren Elternseite
	 * nicht freigegeben ist.
	 *
	 * @param int        $rootId  ID der Wurzelseite
	 * @param array<int> $pageIds Alle freigegebenen Seiten
	 *
	 * @return array<int, array{id: int, title: string}> Die Einstiegsseiten
	 */
	private function getMountsBelow(int $rootId, array $pageIds): array
	{
		if (array() === $pageIds) {
			return array();
		}

		$rows = $this->connection->fetchAllAssociative('SELECT id, pid, title FROM tl_page WHERE id IN ('.implode(',', $pageIds).')');
		$allowed = array_flip($pageIds);
		$mounts = array();

		foreach ($rows as $row) {
			if (isset($allowed[(int) $row['pid']])) {
				continue;
			}

			// Bis zur Wurzel hochlaufen, um die Zugehörigkeit zu prüfen
			$id = (int) $row['id'];
			$pid = (int) $row['pid'];

			while ($pid > 0) {
				$id = $pid;
				$pid = (int) $this->connection->fetchOne('SELECT pid FROM tl_page WHERE id = ?', array($pid));
			}

			if ($id === $rootId) {
				$mounts[] = array('id' => (int) $row['id'], 'title' => (string) $row['title']);
			}
		}

		return $mounts;
	}

	/**
	 * Liefert die Nachrichtenarchive, die der Benutzer sehen darf.
	 *
	 * @return array<int, array{id: int, title: string}>|null Die Archive,
	 *                                                        oder null ohne Modulrecht
	 */
	private function getNewsArchives(): ?array
	{
		if (!$this->permissions->canAccessModule('news')) {
			return null;
		}

		$ids = $this->permissions->getArchiveIds('news');
		$sql = 'SELECT id, title FROM tl_news_archive';

		if (null !== $ids) {
			$sql .= ' WHERE '.(array() === $ids ? '1 = 0' : 'id IN ('.implode(',', $ids).')');
		}

		return array_map(
			static fn (array $row): array => array('id' => (int) $row['id'], 'title' => (string) $row['title']),
			$this->connection->fetchAllAssociative($sql.' ORDER BY title')
		);
	}

	/**
	 * Liefert die in den Einstellungen zusätzlich gesperrten Tabellen.
	 *
	 * @return list<string> Die Einträge der Sperrliste aus dem Backend
	 */
	private function tableAccessExtra(): array
	{
		$extra = array();

		foreach ($this->tableAccess->getExtraBlockedTables() as $table) {
			if (!\in_array($table, TableAccess::ALWAYS_BLOCKED, true)) {
				$extra[] = $table;
			}
		}

		return $extra;
	}

	/**
	 * Liefert die installierte Fassung eines Composer-Pakets.
	 *
	 * InstalledVersions gibt es seit Composer 2 und ist damit in jeder
	 * Contao-4.13- und 5-Installation vorhanden.
	 *
	 * @param string $package Paketname
	 *
	 * @return string|null Die Fassung, oder null, wenn das Paket fehlt
	 */
	private static function packageVersion(string $package): ?string
	{
		return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
	}
}
