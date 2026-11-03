<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Legt Datensätze an, ändert, versteckt, löscht und stellt sie wieder her.
 *
 * Jeder Vorgang läuft in drei Schichten:
 *
 * 1. Eigene Prüfungen vorab, mit verständlichen Meldungen für Claude:
 *    Sperrliste und Modulrecht (TableAccess), Datensatzfreigabe
 *    (Seiten, Archive), Feldfreigabe (alexf), Palette, die Regel
 *    „Neues immer unveröffentlicht, Veröffentlichen nur mit publish_toggle“.
 * 2. Contaos eigener Ablauf über WriteDataContainer, also dieselben
 *    Callbacks, Widgets, Voter, Versionen und Cache-Tags wie im Backend.
 * 3. Protokoll in tl_claude_log, auch für abgewiesene Versuche.
 *
 * Alles zwischen Beginn und Abschluss liegt in einer Transaktion. Scheitert
 * ein Feld, bleibt der Datensatz, wie er war; ein neuer Datensatz
 * verschwindet wieder.
 */
class RecordWriter
{
	/**
	 * Spalten, die Contao selbst verwaltet und die Claude nie setzt.
	 */
	public const SYSTEM_FIELDS = array('id', 'pid', 'ptable', 'sorting', 'tstamp');

	/**
	 * Felder, die über die Sichtbarkeit entscheiden, und ob sie umgekehrt
	 * gemeint sind (invisible = 1 heißt versteckt).
	 */
	public const PUBLISH_FIELDS = array('published' => false, 'invisible' => true);

	/**
	 * Tabellen, die eigene Werkzeuge haben und über die allgemeinen nicht
	 * geschrieben werden.
	 */
	private const OWN_TOOLS = array('tl_files' => 'file_upload und file_meta_update');

	/**
	 * Sortiermodus „Elternansicht“ (DataContainer::MODE_PARENT).
	 */
	private const MODE_PARENT = 4;

	/**
	 * Sortiermodi der Baumansichten (MODE_TREE, MODE_TREE_EXTENDED).
	 */
	private const TREE_MODES = array(5, 6);

	/**
	 * @param ContaoFramework    $framework   Startet Contao
	 * @param Connection         $connection  Transaktion und Abfragen
	 * @param TableAccess        $tableAccess Sperrliste, Modul- und Datensatzrecht
	 * @param Permissions        $permissions Feldrechte und Benutzer
	 * @param Settings           $settings    Schalter für Schreiben, Veröffentlichen, Löschen
	 * @param ValueConverter     $converter   Werte wie aus dem Backend-Formular
	 * @param BackendEnvironment $environment Anfrage wie im Backend
	 * @param AuditLog           $log         Protokoll
	 * @param ReferenceFinder    $references  Verweise vor dem Löschen
	 * @param LoggerInterface    $logger      Für unerwartete Fehler
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Connection $connection,
		private readonly TableAccess $tableAccess,
		private readonly Permissions $permissions,
		private readonly Settings $settings,
		private readonly ValueConverter $converter,
		private readonly BackendEnvironment $environment,
		private readonly AuditLog $log,
		private readonly ReferenceFinder $references,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Legt einen Datensatz an und setzt seine Felder.
	 *
	 * Der Datensatz entsteht immer unveröffentlicht: published leer bzw.
	 * invisible gesetzt, auch wenn das Veröffentlichen erlaubt ist.
	 * Veröffentlicht wird ausdrücklich mit publish_toggle.
	 *
	 * @param string               $tool        Werkzeugname für das Protokoll
	 * @param string               $table       Tabelle
	 * @param array<string, mixed> $values      Feldwerte in der Form von record_get
	 * @param int|null             $parentId    Elterndatensatz (pid), Pflicht bei Kindtabellen
	 * @param string|null          $parentTable Elterntabelle, nur bei Tabellen mit
	 *                                          wechselnder Elterntabelle (tl_content)
	 * @param string               $position    „end“ (Vorgabe), „start“ oder „after“
	 * @param int|null             $afterId     Bei „after“: Datensatz, nach dem eingefügt wird
	 *
	 * @return int Die ID des neuen Datensatzes
	 *
	 * @throws ToolCallException Mit dem Grund, wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function create(string $tool, string $table, array $values, ?int $parentId = null, ?string $parentTable = null, string $position = 'end', ?int $afterId = null): int
	{
		$fields = array_keys($values);
		$newId = 0;

		return $this->guard($tool, $table, static function () use (&$newId): int {
			return $newId;
		}, $fields, function () use ($table, $values, $parentId, $parentTable, $position, $afterId, &$newId): int {
			$dca = $this->assertWritable($table, 'create');

			if (($dca['config']['closed'] ?? false) || ($dca['config']['notCreatable'] ?? false)) {
				throw new ToolCallException(sprintf('In %s lassen sich keine Datensätze anlegen.', $table));
			}

			$this->assertFieldsWritable($table, $dca, $values, false);

			[$parentTable, $parentId] = $this->resolveParent($table, $dca, $parentId, $parentTable);
			[$mode, $pid] = $this->resolvePosition($table, $dca, $parentTable, $parentId, $position, $afterId);

			$set = array();

			foreach (self::PUBLISH_FIELDS as $field => $inverted) {
				if (isset($dca['fields'][$field])) {
					// Leerer Wert passend zur Spalte: '' bei char(1) in 4.13, 0 bei boolean in Contao 5
					$off = Widget::getEmptyValueByFieldType($dca['fields'][$field]['sql'] ?? array());
					$set[$field] = $inverted && !$this->isParentHidden($table, $dca, $parentTable, $parentId) ? '1' : $off;
				}
			}

			$module = $this->findModule($table, $parentTable);
			$get = array('do' => $module[0], 'table' => $table, 'act' => 'create', 'mode' => $mode, 'pid' => $pid);

			$newId = $this->withParentTable($table, $dca, $parentTable, fn (): int => $this->environment->run(
				$get,
				array(),
				$table,
				$parentId,
				fn (): int => (new WriteDataContainer($table, $module[1]))->createRecord($set),
			));

			$this->saveValues($table, $newId, $values, $parentTable, $parentId, true);

			return $newId;
		});
	}

	/**
	 * Ändert Felder eines Datensatzes.
	 *
	 * @param string               $tool   Werkzeugname für das Protokoll
	 * @param string               $table  Tabelle
	 * @param int                  $id     ID des Datensatzes
	 * @param array<string, mixed> $values Feldwerte in der Form von record_get
	 *
	 * @throws ToolCallException Mit dem Grund, wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function update(string $tool, string $table, int $id, array $values): void
	{
		if (array() === $values) {
			throw new ToolCallException('Es wurden keine Felder zum Ändern übergeben.');
		}

		$this->guard($tool, $table, static fn (): int => $id, array_keys($values), function () use ($table, $id, $values): void {
			$dca = $this->assertWritable($table, 'update');
			$row = $this->loadRow($table, $id);
			$this->assertFieldsWritable($table, $dca, $values, false);

			$parentTable = $this->getRowParentTable($dca, $row);
			$this->saveValues($table, $id, $values, $parentTable, (int) ($row['pid'] ?? 0), false);
		});
	}

	/**
	 * Veröffentlicht oder versteckt einen Datensatz.
	 *
	 * Gesetzt wird published bzw. invisible über denselben Weg wie jedes
	 * andere Feld, also mit Feldrecht (alexf), save_callback und Version.
	 *
	 * @param string $tool      Werkzeugname für das Protokoll
	 * @param string $table     Tabelle
	 * @param int    $id        ID des Datensatzes
	 * @param bool   $published true: sichtbar machen, false: verstecken
	 *
	 * @return string Der Name des gesetzten Feldes
	 *
	 * @throws ToolCallException Wenn die Tabelle kein solches Feld hat oder es nicht erlaubt ist
	 */
	public function setPublished(string $tool, string $table, int $id, bool $published): string
	{
		$field = '';

		$this->guard($tool, $table, static fn (): int => $id, array('published'), function () use ($table, $id, $published, &$field): void {
			if (!$this->settings->isPublishEnabled()) {
				throw new ToolCallException('Veröffentlichen ist in dieser Installation für Claude nicht freigegeben.');
			}

			$dca = $this->assertWritable($table, 'update');
			$row = $this->loadRow($table, $id);

			foreach (self::PUBLISH_FIELDS as $name => $inverted) {
				if (isset($dca['fields'][$name])) {
					$field = $name;
					$value = $inverted ? !$published : $published;
					break;
				}
			}

			if ('' === $field) {
				throw new ToolCallException(sprintf('%s hat kein Feld zum Veröffentlichen.', $table));
			}

			$values = array($field => $value);
			$this->assertFieldsWritable($table, $dca, $values, true);
			$this->saveValues($table, $id, $values, $this->getRowParentTable($dca, $row), (int) ($row['pid'] ?? 0), false);
		});

		return $field;
	}

	/**
	 * Löscht einen Datensatz samt Kindern, wiederherstellbar über tl_undo.
	 *
	 * Vorher wird nach Verweisen gesucht (Insert-Tags, Weiterleitungsziele,
	 * Aliase, Kinddatensätze). Gibt es welche, wird nur mit
	 * $ignoreReferences gelöscht.
	 *
	 * @param string $tool             Werkzeugname für das Protokoll
	 * @param string $table            Tabelle
	 * @param int    $id               ID des Datensatzes
	 * @param bool   $ignoreReferences Trotz gefundener Verweise löschen
	 *
	 * @return list<string> Die Verweise, die beim Löschen bestanden
	 *
	 * @throws ToolCallException Wenn Löschen nicht erlaubt ist oder Verweise bestehen
	 */
	public function delete(string $tool, string $table, int $id, bool $ignoreReferences): array
	{
		$found = array();

		$this->guard($tool, $table, static fn (): int => $id, array(), function () use ($table, $id, $ignoreReferences, &$found): void {
			if (!$this->settings->isDeleteEnabled()) {
				throw new ToolCallException('Löschen ist in dieser Installation für Claude nicht freigegeben.');
			}

			$dca = $this->assertWritable($table, 'delete');

			if ($dca['config']['notDeletable'] ?? false) {
				throw new ToolCallException(sprintf('In %s lassen sich keine Datensätze löschen.', $table));
			}

			$row = $this->loadRow($table, $id);
			$found = $this->references->find($table, $id);

			if (array() !== $found && !$ignoreReferences) {
				throw new ToolCallException("Nicht gelöscht, weil es Verweise gibt. Frag nach, ob trotzdem gelöscht werden soll (ignoreReferences: true):\n- ".implode("\n- ", $found));
			}

			$parentTable = $this->getRowParentTable($dca, $row);
			$module = $this->findModule($table, $parentTable);
			$get = array('do' => $module[0], 'table' => $table, 'act' => 'delete', 'id' => $id);

			$this->withParentTable($table, $dca, $parentTable, fn () => $this->environment->run(
				$get,
				array(),
				$table,
				(int) ($row['pid'] ?? 0),
				static fn () => (new WriteDataContainer($table, $module[1]))->deleteRecord($id),
			));

			if (false !== $this->connection->fetchOne('SELECT 1 FROM '.$table.' WHERE id = ?', array($id))) {
				throw new ToolCallException('Contao hat den Datensatz nicht gelöscht.');
			}
		});

		return $found;
	}

	/**
	 * Stellt eine gespeicherte Version eines Datensatzes wieder her.
	 *
	 * Ohne Freigabe zum Veröffentlichen wird keine Version wiederhergestellt,
	 * die den Datensatz sichtbar oder unsichtbar machen würde.
	 *
	 * @param string $tool    Werkzeugname für das Protokoll
	 * @param string $table   Tabelle
	 * @param int    $id      ID des Datensatzes
	 * @param int    $version Versionsnummer aus record_history
	 *
	 * @throws ToolCallException Wenn es die Version nicht gibt oder sie nicht erlaubt ist
	 */
	public function restore(string $tool, string $table, int $id, int $version): void
	{
		$this->guard($tool, $table, static fn (): int => $id, array('version '.$version), function () use ($table, $id, $version): void {
			$dca = $this->assertWritable($table, 'update');
			$row = $this->loadRow($table, $id);

			if (!($dca['config']['enableVersioning'] ?? false)) {
				throw new ToolCallException(sprintf('%s führt keine Versionen.', $table));
			}

			$data = $this->connection->fetchOne('SELECT data FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?', array($table, $id, $version));

			if (false === $data) {
				throw new ToolCallException(sprintf('Version %d von %s.%d gibt es nicht; record_history listet die vorhandenen.', $version, $table, $id));
			}

			if (!$this->settings->isPublishEnabled()) {
				$data = StringUtil::deserialize($data, true);

				foreach (array_keys(self::PUBLISH_FIELDS) as $field) {
					if (\array_key_exists($field, $data) && (bool) $data[$field] !== (bool) ($row[$field] ?? false)) {
						throw new ToolCallException(sprintf('Version %d würde die Sichtbarkeit ändern (%s); Veröffentlichen ist für Claude nicht freigegeben.', $version, $field));
					}
				}
			}

			$parentTable = $this->getRowParentTable($dca, $row);
			$module = $this->findModule($table, $parentTable);
			$get = array('do' => $module[0], 'table' => $table, 'act' => 'edit', 'id' => $id);

			$this->withParentTable($table, $dca, $parentTable, fn () => $this->environment->run(
				$get,
				array(),
				$table,
				(int) ($row['pid'] ?? 0),
				static fn () => (new WriteDataContainer($table, $module[1]))->restoreVersion($id, $version),
			));
		});
	}

	/**
	 * Setzt die Felder eines Datensatzes über WriteDataContainer.
	 *
	 * Auswahlfelder (__selector__, etwa „type“ oder „addImage“) kommen zuerst,
	 * weil sie bestimmen, welche Felder zur Palette gehören. Danach muss jedes
	 * übrige Feld in der Palette stehen, die für den Datensatz gilt – wie im
	 * Backend, wo nur diese Felder im Formular erscheinen. Fehler aller
	 * Felder werden gesammelt und zusammen gemeldet.
	 *
	 * Anschließend wird wie im Backend geprüft, ob Pflichtfelder der Palette
	 * leer geblieben sind. Felder, die der Benutzer nicht bearbeiten darf,
	 * zählen dabei nicht (das Backend zeigt sie ihm gar nicht).
	 *
	 * @param string               $table       Tabelle
	 * @param int                  $id          ID des Datensatzes
	 * @param array<string, mixed> $values      Feldwerte in der Form von record_get
	 * @param string|null          $parentTable Elterntabelle bei wechselnder Elterntabelle
	 * @param int                  $parentId    Elterndatensatz, für CURRENT_ID unter 4.13
	 * @param bool                 $isNew       Ob der Datensatz gerade angelegt wurde
	 *
	 * @throws ToolCallException Mit allen Feldfehlern
	 */
	private function saveValues(string $table, int $id, array $values, ?string $parentTable, int $parentId, bool $isNew): void
	{
		$dca = $this->tableAccess->loadDca($table) ?? array();
		$post = array();

		foreach ($values as $field => $value) {
			$post[$field] = $this->converter->toPost($field, (array) $dca['fields'][$field], $value);
		}

		$module = $this->findModule($table, $parentTable);
		$get = array('do' => $module[0], 'table' => $table, 'act' => 'edit', 'id' => $id);

		$this->withParentTable($table, $dca, $parentTable, fn () => $this->environment->run($get, $post, $table, $parentId, function () use ($table, $id, $dca, $values, $module, $isNew): void {
			$dc = new WriteDataContainer($table, $module[1]);
			$dc->beginEdit($id);

			$selectors = array_values(array_intersect((array) ($dca['palettes']['__selector__'] ?? array()), array_keys($values)));
			$others = array_values(array_diff(array_keys($values), $selectors));
			$errors = array();

			foreach (array_merge($selectors, $others) as $field) {
				if (!\in_array($field, $dc->getPaletteFields(), true)) {
					$errors[] = sprintf('%s: gehört bei diesem Datensatz nicht zur Palette (dca_describe zeigt die Felder je Typ).', $field);
					continue;
				}

				$error = $dc->saveField($field);

				if (null !== $error) {
					$errors[] = $field.': '.$error;
				}
			}

			// Beim ersten Speichern schickt das Backend jedes Feld der Palette ab,
			// auch leere; erst dadurch laufen deren save_callback (etwa der, der
			// den Alias aus der Überschrift erzeugt)
			if ($isNew && array() === $errors) {
				foreach ($this->findEmptyCallbackFields($table, $dca, $dc, $id, array_keys($values)) as $field) {
					$this->environment->setPost($field, '');
					$error = $dc->saveField($field);

					if (null !== $error) {
						$errors[] = $field.': '.$error;
					}
				}
			}

			if (array() === $errors) {
				foreach ($this->findEmptyMandatory($table, $dca, $dc, $id, array_keys($values)) as $field) {
					$errors[] = sprintf('%s: Pflichtfeld ist leer.', $field);
				}
			}

			if (array() !== $errors) {
				throw new ToolCallException(($isNew ? 'Nicht angelegt' : 'Nicht gespeichert').", Contao hat Werte abgelehnt:\n- ".implode("\n- ", $errors));
			}

			$dc->finishEdit();
		}));

		// Ohne gesetzten Wert schreibt Contao 5 in submit() keinen tstamp; ein
		// Datensatz mit tstamp 0 gilt aber als nie gespeichert
		$this->connection->executeStatement('UPDATE '.$table.' SET tstamp = ? WHERE id = ? AND tstamp = 0', array(time(), $id));
	}

	/**
	 * Sucht leere Felder der Palette mit save_callback, die nicht übergeben wurden.
	 *
	 * Nur Felder mit eigener Spalte (sql) und Bearbeitungsrecht; Felder ohne
	 * Spalte (etwa die Suchmaschinen-Vorschau) speichert auch das Backend nicht.
	 *
	 * @param string               $table     Tabelle
	 * @param array<string, mixed> $dca       DCA der Tabelle
	 * @param WriteDataContainer   $dc        Der bearbeitete Datensatz
	 * @param int                  $id        ID des Datensatzes
	 * @param list<string>         $submitted Gerade gesetzte Felder
	 *
	 * @return list<string> Die Feldnamen
	 */
	private function findEmptyCallbackFields(string $table, array $dca, WriteDataContainer $dc, int $id, array $submitted): array
	{
		$row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id)) ?: array();
		$fields = array();

		foreach ($dc->getPaletteFields() as $field) {
			$config = $dca['fields'][$field] ?? null;

			if (!\is_array($config) || empty($config['save_callback']) || !isset($config['sql']) || \in_array($field, $submitted, true) || \in_array($field, self::SYSTEM_FIELDS, true)) {
				continue;
			}

			if (!\array_key_exists($field, $row) || '' !== trim((string) $row[$field]) || !$this->permissions->canEditField($table, $field, $config)) {
				continue;
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Sucht Pflichtfelder der geltenden Palette, die leer sind.
	 *
	 * @param string               $table     Tabelle
	 * @param array<string, mixed> $dca       DCA der Tabelle
	 * @param WriteDataContainer   $dc        Der bearbeitete Datensatz
	 * @param int                  $id        ID des Datensatzes
	 * @param list<string>         $submitted Gerade gesetzte Felder (die hat das Widget geprüft)
	 *
	 * @return list<string> Namen der leeren Pflichtfelder
	 */
	private function findEmptyMandatory(string $table, array $dca, WriteDataContainer $dc, int $id, array $submitted): array
	{
		$row = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id)) ?: array();
		$empty = array();

		foreach ($dc->getPaletteFields() as $field) {
			$config = $dca['fields'][$field] ?? null;

			if (!\is_array($config) || empty($config['eval']['mandatory']) || \in_array($field, $submitted, true) || !\array_key_exists($field, $row)) {
				continue;
			}

			if (!$this->permissions->canEditField($table, $field, $config)) {
				continue;
			}

			$value = StringUtil::deserialize($row[$field]);

			if (\is_array($value) ? array() === array_filter($value, static fn ($entry): bool => '' !== $entry && null !== $entry) : '' === trim((string) $value)) {
				$empty[] = $field;
			}
		}

		return $empty;
	}

	/**
	 * Führt einen Vorgang in einer Transaktion aus und protokolliert ihn.
	 *
	 * Fehler aus Contao werden in Meldungen für Claude übersetzt:
	 * AccessDeniedException (onload_callback, Voter) als fehlendes Recht,
	 * eine erzwungene Weiterleitung als Abbruch durch Contao.
	 *
	 * @param string            $tool   Werkzeugname
	 * @param string            $table  Tabelle
	 * @param callable(): int   $id     Liefert die ID für das Protokoll (auch nach dem Anlegen)
	 * @param list<string>      $fields Feldnamen für das Protokoll
	 * @param callable(): mixed $action Der Vorgang
	 *
	 * @return mixed Das Ergebnis des Vorgangs
	 *
	 * @throws ToolCallException Bei jedem Fehler, nach dem Zurückrollen
	 */
	private function guard(string $tool, string $table, callable $id, array $fields, callable $action)
	{
		if (!$this->settings->isWriteEnabled()) {
			throw new ToolCallException('Schreiben ist in dieser Installation für Claude nicht freigegeben.');
		}

		$this->framework->initialize();

		// Die Fehlermeldungen der Widgets stehen in der allgemeinen Sprachdatei,
		// die das Backend beim Start lädt; ohne sie kämen sie leer an
		$this->framework->getAdapter(System::class)->loadLanguageFile('default', $this->tableAccess->getLanguage());

		$this->connection->beginTransaction();

		try {
			$result = $action();
			$this->connection->commit();
		} catch (\Throwable $exception) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			$message = $this->describeFailure($exception);
			$this->log->add($tool, $table, $id(), $fields, false, $message);

			throw new ToolCallException($message, 0, $exception);
		}

		$this->log->add($tool, $table, $id(), $fields, true);

		return $result;
	}

	/**
	 * Übersetzt einen Fehler in eine Meldung für Claude.
	 *
	 * @param \Throwable $exception Der Fehler
	 *
	 * @return string Die Meldung
	 */
	private function describeFailure(\Throwable $exception): string
	{
		if ($exception instanceof ToolCallException) {
			return $exception->getMessage();
		}

		if ($exception instanceof AccessDeniedException) {
			return 'Contao verweigert das deinem Backend-Zugang: '.$exception->getMessage();
		}

		if ($exception instanceof ResponseException || $exception instanceof CapturedRedirect) {
			return 'Contao hat den Vorgang abgebrochen (Weiterleitung statt Speichern). Meist fehlt ein Recht auf den Datensatz oder seinen Elterndatensatz.';
		}

		$this->logger->error('Schreibvorgang über Claude fehlgeschlagen: '.$exception->getMessage(), array('exception' => $exception));

		return sprintf('Interner Fehler beim Schreiben (%s): %s', (new \ReflectionClass($exception))->getShortName(), $exception->getMessage());
	}

	/**
	 * Prüft, ob in eine Tabelle über die allgemeinen Werkzeuge geschrieben werden darf.
	 *
	 * @param string $table     Tabelle
	 * @param string $operation „create“, „update“ oder „delete“ für die
	 *                          Tabellenrechte unter Contao 5
	 *
	 * @return array<string, mixed> Die DCA der Tabelle
	 *
	 * @throws ToolCallException Bei gesperrter, fremder oder schreibgeschützter
	 *                           Tabelle oder fehlendem Tabellenrecht
	 */
	private function assertWritable(string $table, string $operation): array
	{
		$dca = $this->tableAccess->assertReadable($table);

		if (isset(self::OWN_TOOLS[$table])) {
			throw new ToolCallException(sprintf('Für %s gibt es eigene Werkzeuge: %s.', $table, self::OWN_TOOLS[$table]));
		}

		$driver = (string) ($dca['config']['dataContainer'] ?? '');

		if (!\in_array($driver, array('Table', 'Contao\DC_Table'), true)) {
			throw new ToolCallException(sprintf('%s wird nicht mit DC_Table bearbeitet und lässt sich deshalb nicht über Claude schreiben.', $table));
		}

		if ($dca['config']['notEditable'] ?? false) {
			throw new ToolCallException(sprintf('%s ist im Backend nicht bearbeitbar.', $table));
		}

		if (!$this->permissions->canOperateOnTable($table, $operation)) {
			$labels = array('create' => 'Anlegen', 'update' => 'Bearbeiten', 'delete' => 'Löschen');

			throw new ToolCallException(sprintf('Deinem Backend-Zugang fehlt für %s das Tabellenrecht „%s“ (Benutzergruppe → Tabellenrechte).', $table, $labels[$operation] ?? $operation));
		}

		return $dca;
	}

	/**
	 * Prüft vorab, ob alle übergebenen Felder gesetzt werden dürfen.
	 *
	 * @param string               $table     Tabelle
	 * @param array<string, mixed> $dca       DCA der Tabelle
	 * @param array<string, mixed> $values    Die Feldwerte
	 * @param bool                 $isPublish true, wenn publish_toggle schreibt
	 *
	 * @throws ToolCallException Mit allen unzulässigen Feldern
	 */
	private function assertFieldsWritable(string $table, array $dca, array $values, bool $isPublish): void
	{
		$readable = $this->tableAccess->getReadableFields($table, $dca);
		$errors = array();

		foreach (array_keys($values) as $field) {
			$field = (string) $field;
			$config = $dca['fields'][$field] ?? null;

			if (\in_array($field, self::SYSTEM_FIELDS, true)) {
				$errors[] = sprintf('%s: verwaltet Contao selbst. Eltern und Position werden beim Anlegen angegeben.', $field);
			} elseif (!\is_array($config)) {
				$errors[] = sprintf('%s: gibt es in %s nicht.', $field, $table);
			} elseif (!isset($readable[$field])) {
				$errors[] = sprintf('%s: ist für Claude gesperrt.', $field);
			} elseif (isset(self::PUBLISH_FIELDS[$field]) && !$isPublish) {
				$errors[] = sprintf('%s: Sichtbarkeit wird nur mit publish_toggle geändert%s.', $field, $this->settings->isPublishEnabled() ? '' : ', und das ist für Claude nicht freigegeben');
			} elseif (!$this->permissions->canEditField($table, $field, $config)) {
				$errors[] = sprintf('%s: darf dein Backend-Zugang nicht bearbeiten (Feldfreigabe der Benutzergruppe).', $field);
			}
		}

		if (array() !== $errors) {
			throw new ToolCallException("Diese Felder lassen sich nicht setzen:\n- ".implode("\n- ", $errors));
		}
	}

	/**
	 * Lädt einen Datensatz und prüft die Datensatzfreigabe.
	 *
	 * @param string $table Tabelle
	 * @param int    $id    ID
	 *
	 * @return array<string, mixed> Die Zeile
	 *
	 * @throws ToolCallException Wenn es ihn nicht gibt oder er nicht freigegeben ist
	 */
	private function loadRow(string $table, int $id): array
	{
		$row = $this->tableAccess->canReadRow($table, $id)
			? $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', array($id))
			: false;

		if (false === $row) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $id, $table));
		}

		return $row;
	}

	/**
	 * Bestimmt Elterntabelle und Elterndatensatz für einen neuen Datensatz.
	 *
	 * @param string               $table       Tabelle
	 * @param array<string, mixed> $dca         DCA der Tabelle
	 * @param int|null             $parentId    Angegebener Elterndatensatz
	 * @param string|null          $parentTable Angegebene Elterntabelle
	 *
	 * @return array{0: string|null, 1: int} Elterntabelle (nur bei
	 *                                       wechselnder Elterntabelle) und pid
	 *
	 * @throws ToolCallException Wenn Angaben fehlen oder nicht freigegeben sind
	 */
	private function resolveParent(string $table, array $dca, ?int $parentId, ?string $parentTable): array
	{
		$staticParent = $dca['config']['ptable'] ?? null;
		$dynamic = (bool) ($dca['config']['dynamicPtable'] ?? false);
		$isTree = \in_array((int) ($dca['list']['sorting']['mode'] ?? 0), self::TREE_MODES, true);

		if ($dynamic) {
			$parentTable = $parentTable ?: (string) $staticParent;
		} elseif (null !== $parentTable && '' !== $parentTable && $parentTable !== $staticParent) {
			throw new ToolCallException(sprintf('%s gehört immer zu %s, nicht zu %s.', $table, $staticParent ?: 'keiner Elterntabelle', $parentTable));
		} else {
			$parentTable = $staticParent ?: null;
		}

		// Baum ohne Elterntabelle (tl_page): pid ist der übergeordnete Eintrag derselben Tabelle
		if (null === $parentTable || '' === $parentTable) {
			if ($isTree && null !== $parentId && $parentId > 0) {
				if (!$this->tableAccess->canReadRow($table, $parentId)) {
					throw new ToolCallException(sprintf('Den übergeordneten Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $parentId, $table));
				}

				return array(null, $parentId);
			}

			return array(null, 0);
		}

		if (null === $parentId || $parentId < 1) {
			throw new ToolCallException(sprintf('Für %s fehlt der Elterndatensatz (parentId in %s).', $table, $parentTable));
		}

		$this->tableAccess->assertReadable($parentTable);

		if (!$this->tableAccess->canReadRow($parentTable, $parentId)) {
			throw new ToolCallException(sprintf('Den Elterndatensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $parentId, $parentTable));
		}

		return array($dynamic ? $parentTable : null, $parentId);
	}

	/**
	 * Übersetzt die gewünschte Position in die GET-Parameter von create().
	 *
	 * DC_Table kennt zwei Modi: „einfügen nach“ (mode=1, pid = Datensatz,
	 * hinter dem eingefügt wird) und „einfügen in“ (mode=2, pid =
	 * Elterndatensatz, an den Anfang).
	 *
	 * @param string               $table       Tabelle
	 * @param array<string, mixed> $dca         DCA der Tabelle
	 * @param string|null          $parentTable Elterntabelle bei wechselnder Elterntabelle
	 * @param int                  $parentId    Elterndatensatz (0 ohne)
	 * @param string               $position    „end“, „start“ oder „after“
	 * @param int|null             $afterId     Bezugsdatensatz bei „after“
	 *
	 * @return array{0: int, 1: int} mode und pid für create()
	 *
	 * @throws ToolCallException Bei unbekannter Position oder fremdem Bezugsdatensatz
	 */
	private function resolvePosition(string $table, array $dca, ?string $parentTable, int $parentId, string $position, ?int $afterId): array
	{
		$hasPid = isset($dca['fields']['pid']) || null !== ($dca['config']['ptable'] ?? null);
		$sortable = isset($dca['fields']['sorting']);

		if (!$hasPid) {
			return array(1, 0);
		}

		$siblings = 'pid = ?';
		$params = array($parentId);

		if (null !== $parentTable) {
			$siblings .= " AND (ptable = ? OR (ptable = '' AND ? = 'tl_article'))";
			$params[] = $parentTable;
			$params[] = $parentTable;
		}

		switch ($position) {
			case 'start':
				return array(2, $parentId);

			case 'after':
				if (null === $afterId || false === $this->connection->fetchOne('SELECT 1 FROM '.$table.' WHERE id = ? AND '.$siblings, array_merge(array($afterId), $params))) {
					throw new ToolCallException(sprintf('Der Bezugsdatensatz %s liegt nicht unter demselben Elterndatensatz.', null === $afterId ? '(afterId fehlt)' : (string) $afterId));
				}

				return array(1, $afterId);

			case 'end':
			case '':
				$last = $sortable
					? $this->connection->fetchOne('SELECT id FROM '.$table.' WHERE '.$siblings.' ORDER BY sorting DESC, id DESC LIMIT 1', $params)
					: false;

				return false === $last ? array(2, $parentId) : array(1, (int) $last);
		}

		throw new ToolCallException(sprintf('Unbekannte Position „%s“; erlaubt sind end, start und after.', $position));
	}

	/**
	 * Prüft, ob der Elterndatensatz selbst unveröffentlicht ist.
	 *
	 * Ein Inhaltselement in einer unveröffentlichten Nachricht oder einem
	 * unveröffentlichten Artikel ist ohnehin unsichtbar. Es wird dann sichtbar
	 * angelegt, damit die Nachricht beim Veröffentlichen vollständig
	 * erscheint, statt dass jedes Element einzeln eingeblendet werden muss.
	 * Unter einem sichtbaren Elterndatensatz entsteht es versteckt.
	 *
	 * @param string               $table       Tabelle des neuen Datensatzes
	 * @param array<string, mixed> $dca         Deren DCA
	 * @param string|null          $parentTable Elterntabelle bei wechselnder Elterntabelle
	 * @param int                  $parentId    Elterndatensatz
	 *
	 * @return bool true, wenn der Elterndatensatz nicht veröffentlicht ist
	 */
	private function isParentHidden(string $table, array $dca, ?string $parentTable, int $parentId): bool
	{
		$parentTable ??= $dca['config']['ptable'] ?? null;

		if (null === $parentTable || '' === $parentTable || $parentId < 1 || $parentTable === $table) {
			return false;
		}

		$parentDca = $this->tableAccess->loadDca($parentTable);

		if (!isset($parentDca['fields']['published'])) {
			return false;
		}

		// Contao 5 speichert published als boolean (0/1), 4.13 als char(1) ('' oder '1')
		return !(bool) $this->connection->fetchOne('SELECT published FROM '.$parentTable.' WHERE id = ?', array($parentId));
	}

	/**
	 * Ermittelt die Elterntabelle eines vorhandenen Datensatzes.
	 *
	 * @param array<string, mixed> $dca DCA der Tabelle
	 * @param array<string, mixed> $row Der Datensatz
	 *
	 * @return string|null Die Elterntabelle bei wechselnder Elterntabelle,
	 *                     sonst null; ein leeres ptable steht für tl_article
	 */
	private function getRowParentTable(array $dca, array $row): ?string
	{
		if (!($dca['config']['dynamicPtable'] ?? false)) {
			return null;
		}

		return '' === (string) ($row['ptable'] ?? '') ? 'tl_article' : (string) $row['ptable'];
	}

	/**
	 * Setzt für die Dauer einer Aktion die Elterntabelle im DCA.
	 *
	 * Bei Tabellen mit wechselnder Elterntabelle (tl_content) setzt das
	 * Backend-Modul config.ptable passend zum Modul. Contao 4.13 schreibt
	 * beim Speichern ptable aus diesem Wert zurück; ohne ihn landete ein
	 * Inhaltselement einer Nachricht plötzlich unter tl_article.
	 *
	 * @param string               $table       Tabelle
	 * @param array<string, mixed> $dca         DCA der Tabelle
	 * @param string|null          $parentTable Elterntabelle oder null
	 * @param callable(): mixed    $action      Die Aktion
	 *
	 * @return mixed Das Ergebnis der Aktion
	 */
	private function withParentTable(string $table, array $dca, ?string $parentTable, callable $action)
	{
		if (null === $parentTable || !($dca['config']['dynamicPtable'] ?? false)) {
			return $action();
		}

		$previous = $GLOBALS['TL_DCA'][$table]['config']['ptable'] ?? null;
		$GLOBALS['TL_DCA'][$table]['config']['ptable'] = $parentTable;

		try {
			return $action();
		} finally {
			$GLOBALS['TL_DCA'][$table]['config']['ptable'] = $previous;
		}
	}

	/**
	 * Sucht das Backend-Modul, in dem die Tabelle bearbeitet wird.
	 *
	 * Bei wechselnder Elterntabelle das Modul, das Eltern- und Kindtabelle
	 * führt (Nachrichten für tl_content unter tl_news), sonst das erste, das
	 * der Benutzer öffnen darf.
	 *
	 * @param string      $table       Tabelle
	 * @param string|null $parentTable Elterntabelle oder null
	 *
	 * @return array{0: string, 1: array<string, mixed>} Modulschlüssel und Konfiguration
	 *
	 * @throws ToolCallException Wenn kein passendes Modul offen ist
	 */
	private function findModule(string $table, ?string $parentTable): array
	{
		$candidates = array();

		foreach ($GLOBALS['BE_MOD'] ?? array() as $group) {
			foreach ((array) $group as $name => $config) {
				$tables = (array) ($config['tables'] ?? array());

				if (!\in_array($table, $tables, true)) {
					continue;
				}

				if (null !== $parentTable && $parentTable !== $table && !\in_array($parentTable, $tables, true)) {
					continue;
				}

				$candidates[(string) $name] = (array) $config;
			}
		}

		foreach ($candidates as $name => $config) {
			if ($this->permissions->canAccessModule($name)) {
				return array($name, $config);
			}
		}

		throw new ToolCallException(sprintf('Kein Backend-Modul, das du öffnen darfst, bearbeitet %s%s.', $table, null === $parentTable ? '' : ' unter '.$parentTable));
	}

}
