<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

use Contao\BackendUser;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Database;
use Contao\StringUtil;
use Contao\Validator;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Rechte des angemeldeten Backend-Benutzers, so wie sie im Backend gelten.
 *
 * Für die Rechteprüfung genügen die Attribute „contao_user.…“: Contaos
 * BackendAccessVoter beantwortet sie in 4.13 und 5.7 gleich (über
 * BackendUser::hasAccess()) und lässt Administratoren immer durch. Eine
 * Weiche nach Contao-Fassung ist für das Lesen deshalb nicht nötig.
 *
 * Seiten- und Dateifreigaben und die Archivrechte (news, calendars …) liegen
 * nach dem Laden des Benutzers schon fertig zusammengeführt mit den Rechten
 * seiner Gruppen am Benutzerobjekt; für SQL-Bedingungen werden sie von dort
 * gelesen.
 */
class Permissions
{
	/**
	 * @var array<int>|null Zwischenspeicher für getPageIds() innerhalb einer Anfrage
	 */
	private ?array $pageIds = null;

	/**
	 * @param TokenStorageInterface         $tokenStorage         Enthält das Token aus BackendUserLogin
	 * @param AuthorizationCheckerInterface $authorizationChecker Befragt Contaos Voter
	 * @param ContaoFramework               $framework            Für den Adapter auf Database
	 */
	public function __construct(
		private readonly TokenStorageInterface $tokenStorage,
		private readonly AuthorizationCheckerInterface $authorizationChecker,
		private readonly ContaoFramework $framework,
	) {
	}

	/**
	 * Liefert den angemeldeten Backend-Benutzer.
	 *
	 * @return BackendUser Der Benutzer aus dem Token
	 *
	 * @throws ToolCallException Wenn kein Benutzer angemeldet ist; das
	 *                           kommt nur bei einem Programmierfehler vor
	 */
	public function getUser(): BackendUser
	{
		$user = $this->tokenStorage->getToken()?->getUser();

		if (!$user instanceof BackendUser) {
			throw new ToolCallException('Kein Backend-Benutzer angemeldet.');
		}

		return $user;
	}

	/**
	 * Gibt an, ob der Benutzer Administrator ist.
	 *
	 * @return bool true für Benutzer mit dem Haken „Administrator“
	 */
	public function isAdmin(): bool
	{
		return (bool) $this->getUser()->isAdmin;
	}

	/**
	 * Prüft, ob der Benutzer ein Backend-Modul öffnen darf.
	 *
	 * @param string $module Schlüssel aus $GLOBALS['BE_MOD'], etwa „news“
	 *
	 * @return bool true, wenn das Modul freigegeben ist
	 */
	public function canAccessModule(string $module): bool
	{
		return $this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module);
	}

	/**
	 * Prüft, ob der Benutzer ein Feld bearbeiten darf.
	 *
	 * Ausgeschlossene Felder („exclude“) brauchen die Freigabe „erlaubte
	 * Felder“ (alexf) der Benutzergruppe. Was ohne Angabe gilt, hängt von
	 * der Fassung ab: In Contao 4.13 ist ein Feld ohne „exclude“ frei, in
	 * Contao 5 gesperrt (TableAccessVoter liest „exclude ?? true“); frei ist
	 * dort nur, was ausdrücklich „exclude => false“ trägt. Fürs Lesen gilt
	 * das nicht (siehe TableAccess::getReadableFields()); gebraucht wird die
	 * Prüfung von den schreibenden Werkzeugen und von dca_describe.
	 *
	 * @param string               $table  Tabellenname
	 * @param string               $field  Feldname
	 * @param array<string, mixed> $config Die DCA-Definition des Feldes
	 *
	 * @return bool true, wenn das Feld bearbeitet werden darf
	 */
	public function canEditField(string $table, string $field, array $config): bool
	{
		if (!($config['exclude'] ?? self::isContao5())) {
			return true;
		}

		return $this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, $table.'::'.$field);
	}

	/**
	 * Prüft die Tabellenrechte (Anlegen, Bearbeiten, Löschen) der Gruppe.
	 *
	 * Die gibt es erst in Contao 5 (Gruppenfeld „cud“, im Backend
	 * „Tabellenrechte“). Sie gelten nur für Tabellen, deren DCA die
	 * Operation unter config.permissions führt; Contao trägt dort für jede
	 * DC_Table mit bearbeitbaren Feldern alle drei ein. Unter 4.13 ist jede
	 * Operation erlaubt, die übrigen Rechte prüft Contao dort selbst.
	 *
	 * @param string $table     Tabellenname, DCA muss geladen sein
	 * @param string $operation „create“, „update“ oder „delete“
	 *
	 * @return bool true, wenn die Operation erlaubt ist
	 */
	public function canOperateOnTable(string $table, string $operation): bool
	{
		$constant = ContaoCorePermissions::class.'::USER_CAN_OPERATE_ON_TABLE';

		if (!\defined($constant)) {
			return true;
		}

		$permissions = $GLOBALS['TL_DCA'][$table]['config']['permissions'] ?? null;

		if (!\is_array($permissions) || !\in_array($operation, $permissions, true)) {
			return true;
		}

		return $this->authorizationChecker->isGranted(\constant($constant), $table.'::'.$operation);
	}

	/**
	 * Gibt an, ob Contao 5 läuft.
	 *
	 * @return bool true ab Contao 5.0
	 */
	public static function isContao5(): bool
	{
		return version_compare(ContaoCoreBundle::getVersion(), '5.0', '>=');
	}

	/**
	 * Liefert die Seiten, die der Benutzer sehen darf.
	 *
	 * Das sind seine Seitenfreigaben (pagemounts) samt aller Unterseiten,
	 * genau wie BackendUser::hasAccess($id, 'pagemounts') rechnet.
	 *
	 * @return array<int>|null Seiten-IDs, oder null für „alle“ (Administrator)
	 */
	public function getPageIds(): ?array
	{
		if ($this->isAdmin()) {
			return null;
		}

		if (null === $this->pageIds) {
			$mounts = array_map('intval', StringUtil::deserialize($this->getUser()->pagemounts, true));
			$children = array() === $mounts ? array() : $this->framework->getAdapter(Database::class)->getInstance()->getChildRecords($mounts, 'tl_page');

			$this->pageIds = array_values(array_unique(array_merge($mounts, array_map('intval', $children))));
		}

		return $this->pageIds;
	}

	/**
	 * Liefert die Archive einer Art, die der Benutzer sehen darf.
	 *
	 * @param string $userField Feld am Benutzer: „news“, „calendars“, „faqs“,
	 *                          „newsletters“ oder „forms“
	 *
	 * @return array<int>|null Archiv-IDs, oder null für „alle“ (Administrator)
	 */
	public function getArchiveIds(string $userField): ?array
	{
		if ($this->isAdmin()) {
			return null;
		}

		return array_values(array_map('intval', StringUtil::deserialize($this->getUser()->{$userField}, true)));
	}

	/**
	 * Liefert die Ordner, die der Benutzer in der Dateiverwaltung sieht.
	 *
	 * BackendUser hat die Freigaben beim Laden schon von UUIDs in Pfade
	 * umgewandelt (in 4.13 wie in 5.7). Freigaben auf gelöschte Ordner
	 * bleiben dabei als binäre UUID stehen; sie fallen hier heraus.
	 *
	 * @return list<string>|null Pfade wie „files/presse“, oder null für „alle“
	 */
	public function getFileMounts(): ?array
	{
		if ($this->isAdmin()) {
			return null;
		}

		$mounts = array();

		foreach (StringUtil::deserialize($this->getUser()->filemounts, true) as $path) {
			if (\is_string($path) && '' !== $path && !Validator::isBinaryUuid($path)) {
				$mounts[] = rtrim($path, '/');
			}
		}

		return $mounts;
	}

	/**
	 * Prüft, ob ein Pfad in einer Dateifreigabe liegt.
	 *
	 * @param string $path Pfad relativ zum Projektverzeichnis, etwa „files/a/b.jpg“
	 *
	 * @return bool true, wenn der Pfad selbst oder ein übergeordneter Ordner freigegeben ist
	 */
	public function canReadPath(string $path): bool
	{
		$mounts = $this->getFileMounts();

		if (null === $mounts) {
			return true;
		}

		$path = rtrim($path, '/');

		foreach ($mounts as $mount) {
			if ($path === $mount || str_starts_with($path, $mount.'/')) {
				return true;
			}
		}

		return false;
	}
}
