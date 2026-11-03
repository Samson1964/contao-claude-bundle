<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Data;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DC_File;
use Contao\System;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Entscheidet, welche Tabellen, Datensätze und Felder Claude lesen darf.
 *
 * Drei Stufen, jede für sich ausreichend, um abzuweisen:
 *
 * 1. Sperrliste – gilt auch für Administratoren.
 * 2. Modulrecht – die Tabelle muss zu einem Backend-Modul gehören, das der
 *    Benutzer öffnen darf ($GLOBALS['BE_MOD'][…]['tables']).
 * 3. Datensatzrecht – für die Kerntabellen mit Freigaben (Seiten, Artikel,
 *    Inhaltselemente, Nachrichten, Termine, FAQ, Newsletter, Formulare,
 *    Dateien) eine SQL-Bedingung, die nur freigegebene Datensätze durchlässt.
 *
 * Tabellen eigener Erweiterungen kennen hier nur die Stufen 1 und 2. Eigene
 * Rechte solcher Erweiterungen (etwa „nur Archiv A“) prüft Contao im Backend
 * in deren onload_callback; die laufen hier nicht. Wer das braucht, setzt
 * die Tabelle auf die Sperrliste.
 */
class TableAccess
{
	/**
	 * Tabellen, die Claude nie zu sehen bekommt. Ein Stern am Ende steht für
	 * jede Fortsetzung.
	 */
	public const ALWAYS_BLOCKED = array(
		'tl_user',
		'tl_user_group',
		'tl_member',
		'tl_settings',
		'tl_version',
		'tl_undo',
		'tl_log',
		'tl_cron_job',
		'tl_opt_in',
		'tl_opt_in_related',
		'tl_remember_me',
		'tl_trusted_device',
		'tl_webauthn_credential',
		'tl_newsletter_recipients',
		'tl_newsletter_deny_list',
		'tl_search*',
		'tl_crawl_queue',
		'tl_message_queue',
		'tl_lead*',
		'tl_claude_*',
		'tl_mcp_*',
	);

	/**
	 * Archivtabellen und ihre Kindtabellen mit dem Rechtefeld am Benutzer
	 * und dem Backend-Modul, das zusätzlich offen sein muss.
	 */
	private const ARCHIVES = array(
		'tl_news_archive' => array('news', 'news', 'id'),
		'tl_news' => array('news', 'news', 'pid'),
		'tl_calendar' => array('calendars', 'calendar', 'id'),
		'tl_calendar_events' => array('calendars', 'calendar', 'pid'),
		'tl_faq_category' => array('faqs', 'faq', 'id'),
		'tl_faq' => array('faqs', 'faq', 'pid'),
		'tl_newsletter_channel' => array('newsletters', 'newsletter', 'id'),
		'tl_newsletter' => array('newsletters', 'newsletter', 'pid'),
		'tl_form' => array('forms', 'form', 'id'),
		'tl_form_field' => array('forms', 'form', 'pid'),
		// Meisterlisten (schachbulle/contao-championslists-bundle): freigegebene Listen der Gruppe
		'tl_championslists' => array('championslists', 'championslists', 'id'),
		'tl_championslists_items' => array('championslists', 'championslists', 'pid'),
	);

	/**
	 * Module, die eine Tabelle zeigen, ohne sie unter „tables“ zu führen.
	 * Das Artikelmodul zeigt den Seitenbaum, um die Artikel darunter zu
	 * ordnen; wer nur Artikel pflegen darf, sieht die Seiten trotzdem.
	 */
	private const EXTRA_MODULES = array(
		'tl_page' => array('article'),
	);

	/**
	 * Spalten, die jede Contao-Tabelle haben kann, ohne dass sie im DCA als
	 * Feld beschrieben sind.
	 */
	public const SYSTEM_COLUMNS = array('id', 'pid', 'ptable', 'sorting', 'tstamp');

	/**
	 * Felder, die nie ausgegeben und nie geschrieben werden, egal in welcher
	 * Tabelle. IBAN und BIC (etwa in tl_adressen) sind Bankdaten, die Claude
	 * für keine Aufgabe braucht.
	 */
	private const SECRET_FIELDS = array('password', 'secret', 'backupCodes', 'session', 'loginAttempts', 'trustedTokenVersion', 'privateKey', 'apiKey', 'token', 'iban', 'bic');

	/**
	 * @param ContaoFramework $framework   Lädt DCA- und Sprachdateien
	 * @param Connection      $connection  Prüft Tabellen und Datensätze
	 * @param Permissions     $permissions Rechte des angemeldeten Benutzers
	 * @param Settings        $settings    Liefert die zusätzlich gesperrten Tabellen
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Connection $connection,
		private readonly Permissions $permissions,
		private readonly Settings $settings,
	) {
	}

	/**
	 * Prüft, ob eine Tabelle auf der Sperrliste steht.
	 *
	 * @param string $table Tabellenname
	 *
	 * @return bool true, wenn die Tabelle fest oder in den Einstellungen gesperrt ist
	 */
	public function isBlocked(string $table): bool
	{
		$table = strtolower($table);

		foreach (array_merge(self::ALWAYS_BLOCKED, $this->settings->getExtraBlockedTables()) as $pattern) {
			if (str_ends_with($pattern, '*') ? str_starts_with($table, substr($pattern, 0, -1)) : $table === $pattern) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Liefert die im Backend zusätzlich gesperrten Tabellen.
	 *
	 * @return list<string> Einträge der Sperrliste aus den Einstellungen
	 */
	public function getExtraBlockedTables(): array
	{
		return $this->settings->getExtraBlockedTables();
	}

	/**
	 * Lädt die DCA-Definition einer Tabelle, wenn der Benutzer sie lesen darf.
	 *
	 * Die Sprachdatei der Tabelle wird gleich mitgeladen, in der Sprache des
	 * Benutzers (Contao lädt sonst die Sprache der Anfrage, und die ist bei
	 * /mcp meist Englisch).
	 *
	 * @param string $table Tabellenname, etwa „tl_news“
	 *
	 * @return array<string, mixed> Die DCA-Definition aus $GLOBALS['TL_DCA']
	 *
	 * @throws ToolCallException Mit einer Meldung für Claude, wenn die Tabelle
	 *                           gesperrt, unbekannt oder nicht freigegeben ist
	 */
	public function assertReadable(string $table): array
	{
		if (!preg_match('/^[a-z0-9_]+$/', $table)) {
			throw new ToolCallException(sprintf('„%s“ ist kein gültiger Tabellenname.', $table));
		}

		if ($this->isBlocked($table)) {
			throw new ToolCallException(sprintf('Die Tabelle %s ist für Claude gesperrt.', $table));
		}

		if (!$this->connection->createSchemaManager()->tablesExist(array($table))) {
			throw new ToolCallException(sprintf('Die Tabelle %s gibt es in dieser Installation nicht.', $table));
		}

		$dca = $this->loadDca($table);

		if (null === $dca) {
			throw new ToolCallException(sprintf('Zur Tabelle %s gibt es keine DCA-Definition.', $table));
		}

		$dataContainer = (string) ($dca['config']['dataContainer'] ?? '');

		if (DC_File::class === $dataContainer || 'File' === $dataContainer) {
			throw new ToolCallException(sprintf('%s ist keine Datenbanktabelle, sondern eine Einstellungsmaske.', $table));
		}

		if (!$this->permissions->isAdmin()) {
			$allowed = false;

			foreach ($this->getModulesForTable($table) as $module) {
				if ($this->permissions->canAccessModule($module)) {
					$allowed = true;
					break;
				}
			}

			if (!$allowed) {
				throw new ToolCallException(sprintf('Kein Zugriff auf %s: Die Tabelle gehört zu keinem Backend-Modul, das dein Backend-Benutzer öffnen darf.', $table));
			}
		}

		return $dca;
	}

	/**
	 * Lädt DCA und Sprachdatei einer Tabelle ohne Rechteprüfung.
	 *
	 * @param string $table Tabellenname
	 *
	 * @return array<string, mixed>|null Die DCA-Definition, oder null, wenn
	 *                                   keine Erweiterung die Tabelle beschreibt
	 */
	public function loadDca(string $table): ?array
	{
		$this->framework->initialize();
		$this->framework->getAdapter(Controller::class)->loadDataContainer($table);
		$this->framework->getAdapter(System::class)->loadLanguageFile($table, $this->getLanguage());

		$dca = $GLOBALS['TL_DCA'][$table] ?? null;

		return \is_array($dca) && isset($dca['fields']) ? $dca : null;
	}

	/**
	 * Liefert die Sprache für Beschriftungen.
	 *
	 * @return string Die Backend-Sprache des Benutzers, ersatzweise „de“
	 */
	public function getLanguage(): string
	{
		$language = (string) $this->permissions->getUser()->language;

		return '' === $language ? 'de' : str_replace('_', '-', $language);
	}

	/**
	 * Sucht die Backend-Module, in denen eine Tabelle bearbeitet wird.
	 *
	 * @param string $table Tabellenname
	 *
	 * @return list<string> Modulschlüssel aus $GLOBALS['BE_MOD']
	 */
	public function getModulesForTable(string $table): array
	{
		$modules = self::EXTRA_MODULES[$table] ?? array();

		foreach ($GLOBALS['BE_MOD'] ?? array() as $group) {
			foreach ((array) $group as $name => $config) {
				if (\in_array($table, (array) ($config['tables'] ?? array()), true)) {
					$modules[] = (string) $name;
				}
			}
		}

		return $modules;
	}

	/**
	 * Liefert die Felder einer Tabelle, die Claude sehen darf.
	 *
	 * Maßstab ist Contaos eigene Detailansicht (act=show, DC_Table::show()):
	 * Sie zeigt in 4.13 wie in 5.7 jedem, der den Datensatz öffnen darf, alle
	 * Felder außer Kennwortfeldern und Feldern mit doNotShow oder hideInput.
	 * Die Feldfreigaben der Benutzergruppe (alexf, „exclude“) regeln nur das
	 * Bearbeiten und greifen hier deshalb nicht. Zusätzlich fallen bekannte
	 * Geheimnisse heraus, auch wenn ein DCA sie nicht als Kennwort markiert.
	 *
	 * @param string               $table Tabellenname
	 * @param array<string, mixed> $dca   Die DCA-Definition aus assertReadable()
	 *
	 * @return array<string, array<string, mixed>> Feldname => DCA-Definition
	 *                                             (leeres Feld bei Systemspalten)
	 */
	public function getReadableFields(string $table, array $dca): array
	{
		$fields = array();

		foreach (self::SYSTEM_COLUMNS as $column) {
			if (isset($dca['fields'][$column]) || 'id' === $column || 'tstamp' === $column) {
				$fields[$column] = (array) ($dca['fields'][$column] ?? array());
			}
		}

		foreach ($dca['fields'] as $name => $config) {
			if (isset($fields[$name]) || !\is_array($config) || !isset($config['sql'])) {
				continue;
			}

			if ('password' === ($config['inputType'] ?? '') || !empty($config['eval']['doNotShow']) || !empty($config['eval']['hideInput'])) {
				continue;
			}

			if (\in_array($name, self::SECRET_FIELDS, true) || !empty($config['eval']['encrypt'])) {
				continue;
			}

			$fields[(string) $name] = $config;
		}

		return $fields;
	}

	/**
	 * Liefert die lesbaren Felder, die das Backend für einen Datensatz zeigt.
	 *
	 * Das sind die Systemspalten und die Felder der Palette, die für den
	 * Datensatz gilt (PaletteResolver). Hat die Tabelle keine Paletten,
	 * kommen alle lesbaren Felder zurück.
	 *
	 * @param string               $table Tabellenname
	 * @param array<string, mixed> $dca   Die DCA-Definition aus assertReadable()
	 * @param array<string, mixed> $row   Der Datensatz
	 *
	 * @return array<string, array<string, mixed>> Feldname => DCA-Definition
	 */
	public function getVisibleFields(string $table, array $dca, array $row): array
	{
		$readable = $this->getReadableFields($table, $dca);
		$palette = PaletteResolver::getFields($dca, $row);

		if (array() === $palette) {
			return $readable;
		}

		$visible = array();

		foreach (array_merge(self::SYSTEM_COLUMNS, $palette) as $name) {
			if (isset($readable[$name])) {
				$visible[$name] = $readable[$name];
			}
		}

		return $visible;
	}

	/**
	 * Baut die SQL-Bedingung für die Datensatzrechte einer Tabelle.
	 *
	 * IDs werden als Zahlen direkt eingesetzt statt gebunden: Listenparameter
	 * heißen in Doctrine DBAL 3 (Contao 4.13) und 4 (Contao 5.7) verschieden.
	 *
	 * @param string $table Tabellenname
	 *
	 * @return array{0: string, 1: list<string>}|null Bedingung und gebundene
	 *                                                Werte, oder null ohne Einschränkung
	 */
	public function getRowCondition(string $table): ?array
	{
		if ($this->permissions->isAdmin()) {
			return null;
		}

		if ('tl_page' === $table) {
			return array(self::inList('id', $this->permissions->getPageIds()), array());
		}

		if ('tl_article' === $table) {
			return array(self::inList('pid', $this->permissions->getPageIds()), array());
		}

		if ('tl_files' === $table) {
			return $this->getPathCondition('path');
		}

		if ('tl_content' === $table) {
			return array($this->getContentCondition(), array());
		}

		if (isset(self::ARCHIVES[$table])) {
			[$userField, $module, $column] = self::ARCHIVES[$table];

			if (!$this->permissions->canAccessModule($module)) {
				return array('1 = 0', array());
			}

			return array(self::inList($column, $this->permissions->getArchiveIds($userField)), array());
		}

		return null;
	}

	/**
	 * Prüft, ob ein bestimmter Datensatz lesbar ist.
	 *
	 * @param string $table Tabellenname (schon mit assertReadable() geprüft)
	 * @param int    $id    ID des Datensatzes
	 *
	 * @return bool true, wenn der Datensatz existiert und freigegeben ist
	 */
	public function canReadRow(string $table, int $id): bool
	{
		$condition = $this->getRowCondition($table);
		$sql = 'SELECT 1 FROM '.$table.' WHERE id = ?';
		$params = array($id);

		if (null !== $condition) {
			$sql .= ' AND ('.$condition[0].')';
			$params = array_merge($params, $condition[1]);
		}

		return false !== $this->connection->fetchOne($sql, $params);
	}

	/**
	 * Bedingung für Inhaltselemente: je Elterntabelle die passende Freigabe.
	 *
	 * Ein leeres ptable stammt aus Contao 3 und meint tl_article. Für
	 * Elterntabellen eigener Erweiterungen genügt das Modulrecht.
	 *
	 * @return string Die SQL-Bedingung für tl_content
	 */
	private function getContentCondition(): string
	{
		$parts = array();

		if ($this->permissions->canAccessModule('article')) {
			$parts[] = "(ptable IN ('tl_article', '') AND pid IN (SELECT id FROM tl_article WHERE ".self::inList('pid', $this->permissions->getPageIds()).'))';
		}

		$known = array('tl_article', '');
		$schemaManager = $this->connection->createSchemaManager();

		foreach (array('tl_news', 'tl_calendar_events', 'tl_faq', 'tl_newsletter') as $parentTable) {
			$known[] = $parentTable;

			// Das zugehörige Bundle ist nicht installiert
			if (!$schemaManager->tablesExist(array($parentTable))) {
				continue;
			}

			$parent = $this->getRowCondition($parentTable);

			if (null !== $parent && '1 = 0' !== $parent[0]) {
				$parts[] = '(ptable = '.$this->connection->quote($parentTable).' AND pid IN (SELECT id FROM '.$parentTable.' WHERE '.$parent[0].'))';
			}
		}

		$others = array();

		foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT ptable FROM tl_content') as $parentTable) {
			$parentTable = (string) $parentTable;

			if (\in_array($parentTable, $known, true) || $this->isBlocked($parentTable)) {
				continue;
			}

			foreach ($this->getModulesForTable($parentTable) as $module) {
				if ($this->permissions->canAccessModule($module)) {
					$others[] = $this->connection->quote($parentTable);
					break;
				}
			}
		}

		if (array() !== $others) {
			$parts[] = 'ptable IN ('.implode(', ', $others).')';
		}

		return array() === $parts ? '1 = 0' : implode(' OR ', $parts);
	}

	/**
	 * Bedingung für Pfade innerhalb der Dateifreigaben.
	 *
	 * @param string $column Spalte mit dem Pfad
	 *
	 * @return array{0: string, 1: list<string>} Bedingung und gebundene Werte
	 */
	private function getPathCondition(string $column): array
	{
		$parts = array();
		$params = array();

		foreach ($this->permissions->getFileMounts() ?? array() as $mount) {
			$parts[] = '('.$column.' = ? OR '.$column.' LIKE ?)';
			$params[] = $mount;
			$params[] = addcslashes($mount, '%_\\').'/%';
		}

		return array() === $parts ? array('1 = 0', array()) : array(implode(' OR ', $parts), $params);
	}

	/**
	 * Baut „spalte IN (1, 2, 3)“ aus einer Liste von IDs.
	 *
	 * @param string          $column Spaltenname
	 * @param array<int>|null $ids    Die IDs; null steht für „keine Einschränkung“
	 *
	 * @return string Die Bedingung; „1 = 0“ bei leerer Liste
	 */
	private static function inList(string $column, ?array $ids): string
	{
		if (null === $ids) {
			return '1 = 1';
		}

		$ids = array_values(array_unique(array_map('intval', $ids)));

		return array() === $ids ? '1 = 0' : $column.' IN ('.implode(', ', $ids).')';
	}
}
