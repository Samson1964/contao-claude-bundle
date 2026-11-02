<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Data;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Prüft Sperrliste, Feldauswahl, Modulzuordnung und Datensatzbedingungen.
 */
class TableAccessTest extends TestCase
{
	/**
	 * Setzt $GLOBALS['BE_MOD'] nach jedem Test zurück.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['BE_MOD']);
	}

	/**
	 * Die feste Sperrliste gilt mit und ohne Stern, ergänzt um die Einstellungen.
	 */
	public function testBlockedTables(): void
	{
		$access = $this->access(true, array('tl_eigene', 'tl_geheim_*'));

		foreach (array('tl_user', 'tl_member', 'tl_version', 'tl_lead', 'tl_lead_data', 'tl_claude_token', 'tl_search_index', 'TL_USER', 'tl_eigene', 'tl_geheim_daten') as $table) {
			$this->assertTrue($access->isBlocked($table), $table);
		}

		foreach (array('tl_news', 'tl_content', 'tl_user_x', 'tl_member_group', 'tl_eigene2') as $table) {
			$this->assertFalse($access->isBlocked($table), $table);
		}
	}

	/**
	 * Lesbar ist, was Contaos Detailansicht zeigt – ohne Kennwörter und Geheimnisse.
	 */
	public function testReadableFieldsFollowContaoShowView(): void
	{
		$dca = array('fields' => array(
			'id' => array('sql' => 'int'),
			'pid' => array('sql' => 'int'),
			'title' => array('inputType' => 'text', 'sql' => 'varchar'),
			'excluded' => array('inputType' => 'text', 'exclude' => true, 'sql' => 'varchar'),
			'password' => array('inputType' => 'password', 'sql' => 'varchar'),
			'hidden' => array('inputType' => 'text', 'eval' => array('doNotShow' => true), 'sql' => 'varchar'),
			'masked' => array('inputType' => 'text', 'eval' => array('hideInput' => true), 'sql' => 'varchar'),
			'secret' => array('inputType' => 'text', 'sql' => 'varchar'),
			'virtual' => array('inputType' => 'text'),
		));

		$this->assertSame(array('id', 'pid', 'tstamp', 'title', 'excluded'), array_keys($this->access(false)->getReadableFields('tl_x', $dca)));
	}

	/**
	 * Tabellen gehören zu den Modulen, die sie unter „tables“ führen.
	 */
	public function testModulesForTable(): void
	{
		$GLOBALS['BE_MOD'] = array(
			'content' => array(
				'article' => array('tables' => array('tl_article', 'tl_content')),
				'news' => array('tables' => array('tl_news_archive', 'tl_news', 'tl_content')),
			),
			'design' => array('page' => array('tables' => array('tl_page'))),
		);

		$access = $this->access(true);

		$this->assertSame(array('article', 'news'), $access->getModulesForTable('tl_content'));
		// Das Artikelmodul zeigt den Seitenbaum, ohne tl_page zu führen
		$this->assertSame(array('article', 'page'), $access->getModulesForTable('tl_page'));
		$this->assertSame(array(), $access->getModulesForTable('tl_unbekannt'));
	}

	/**
	 * Administratoren lesen alle Datensätze.
	 */
	public function testAdminHasNoRowCondition(): void
	{
		$this->assertNull($this->access(true)->getRowCondition('tl_page'));
		$this->assertNull($this->access(true)->getRowCondition('tl_news'));
	}

	/**
	 * Seiten und Artikel folgen den Seitenfreigaben, Nachrichten den Archiven.
	 */
	public function testRowConditionsForEditors(): void
	{
		$access = $this->access(false, array(), array(3, 5), array('news' => array(2)), array('news'));

		$this->assertSame(array('id IN (3, 5)', array()), $access->getRowCondition('tl_page'));
		$this->assertSame(array('pid IN (3, 5)', array()), $access->getRowCondition('tl_article'));
		$this->assertSame(array('pid IN (2)', array()), $access->getRowCondition('tl_news'));
		$this->assertSame(array('id IN (2)', array()), $access->getRowCondition('tl_news_archive'));
		// Ohne Modulrecht hilft auch eine Archivfreigabe nicht
		$this->assertSame(array('1 = 0', array()), $access->getRowCondition('tl_calendar_events'));
		// Tabellen ohne eigene Freigaben schränkt nur das Modulrecht ein
		$this->assertNull($access->getRowCondition('tl_layout'));
	}

	/**
	 * Ohne Seitenfreigaben sieht ein Redakteur keine Seite.
	 */
	public function testEditorWithoutPagemounts(): void
	{
		$this->assertSame(array('1 = 0', array()), $this->access(false, array(), array())->getRowCondition('tl_page'));
	}

	/**
	 * Dateien folgen den Dateifreigaben, mit maskierten LIKE-Platzhaltern.
	 */
	public function testFileCondition(): void
	{
		$permissions = $this->createMock(Permissions::class);
		$permissions->method('isAdmin')->willReturn(false);
		$permissions->method('getFileMounts')->willReturn(array('files/presse_2026'));

		$access = new TableAccess($this->createMock(ContaoFramework::class), $this->createMock(Connection::class), $permissions, $this->settings(array()));

		$this->assertSame(array('(path = ? OR path LIKE ?)', array('files/presse_2026', 'files/presse\_2026/%')), $access->getRowCondition('tl_files'));
	}

	/**
	 * Baut den Dienst mit vorgegebenen Rechten.
	 *
	 * @param bool                     $admin    Administrator?
	 * @param list<string>             $blocked  Zusätzlich gesperrte Tabellen
	 * @param array<int>|null          $pages    Freigegebene Seiten
	 * @param array<string,array<int>> $archives Archivfreigaben je Feld
	 * @param list<string>             $modules  Freigegebene Module
	 *
	 * @return TableAccess Der Dienst
	 */
	private function access(bool $admin, array $blocked = array(), ?array $pages = null, array $archives = array(), array $modules = array()): TableAccess
	{
		$permissions = $this->createMock(Permissions::class);
		$permissions->method('isAdmin')->willReturn($admin);
		$permissions->method('getPageIds')->willReturn($admin ? null : $pages);
		$permissions->method('getArchiveIds')->willReturnCallback(static fn (string $field): ?array => $admin ? null : ($archives[$field] ?? array()));
		$permissions->method('canAccessModule')->willReturnCallback(static fn (string $module): bool => $admin || \in_array($module, $modules, true));

		return new TableAccess($this->createMock(ContaoFramework::class), $this->createMock(Connection::class), $permissions, $this->settings($blocked));
	}

	/**
	 * Baut Einstellungen mit einer Sperrliste.
	 *
	 * @param list<string> $blocked Zusätzlich gesperrte Tabellen
	 *
	 * @return Settings Die Einstellungen als Attrappe
	 */
	private function settings(array $blocked): Settings
	{
		$settings = $this->createMock(Settings::class);
		$settings->method('getExtraBlockedTables')->willReturn($blocked);

		return $settings;
	}
}
