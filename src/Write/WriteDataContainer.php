<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\Database;
use Contao\DC_Table;
use Contao\StringUtil;
use Contao\System;
use Contao\Versions;
use Contao\Widget;

/**
 * Contaos DC_Table, ferngesteuert statt über das Backend-Formular.
 *
 * Alles, was beim Speichern im Backend geschieht, läuft hier über Contaos
 * eigenen Code: onload_callback (Rechteprüfung, auch die eigener
 * Erweiterungen), Prüfung jedes Wertes durch sein Widget (Pflichtfeld,
 * rgxp, Optionen, Länge), load_callback und save_callback, DC_Table::save()
 * mit Datumsumwandlung, unique und leeren Werten, onsubmit_callback,
 * Versionen und das Leeren der Cache-Tags. Anlegen und Löschen nutzen
 * create() und delete() unverändert, samt oncreate_callback, Position,
 * Vorgabewerten, tl_undo und ondelete_callback.
 *
 * Abweichend vom Backend sind nur zwei Dinge:
 *
 * - Weiterleitungen (redirect, reload) werden als CapturedRedirect geworfen
 *   statt als Antwort; switchToEdit() liefert statt einer Adresse die ID.
 * - edit() rendert ein Formular und liest alle Felder aus dem POST. Statt
 *   dessen setzt saveField() ein Feld nach dem anderen, mit derselben
 *   Abfolge wie edit() und DataContainer::row().
 *
 * Contao 4.13 schreibt jedes Feld sofort (save() führt das UPDATE aus),
 * Contao 5 sammelt die Werte und schreibt sie in submit(), nach der
 * Prüfung durch die DataContainer-Voter. finishEdit() trägt dem Rechnung.
 * Der Aufrufer (RecordWriter) hält alles in einer Datenbank-Transaktion,
 * damit ein Fehler im dritten Feld nicht die ersten zwei gespeichert lässt.
 *
 * Erzeugt wird die Klasse nur über RecordWriter, nachdem BackendEnvironment
 * die Anfrage wie eine Backend-Formularabsendung aussehen lässt.
 */
class WriteDataContainer extends DC_Table
{
	/**
	 * Vorsatz der Pseudo-Adresse aus switchToEdit().
	 */
	private const CREATED_PREFIX = 'claude-created:';

	/**
	 * @var Versions|null Versionen des bearbeiteten Datensatzes (beginEdit)
	 */
	private ?Versions $editVersions = null;

	/**
	 * Fängt Weiterleitungen ab.
	 *
	 * DC_Table ruft $this->redirect() auf; über die späte statische Bindung
	 * landet der Aufruf hier statt in Controller::redirect().
	 *
	 * @param string $strLocation Ziel der Weiterleitung
	 * @param int    $intStatus   HTTP-Status, unbeachtet
	 *
	 * @throws CapturedRedirect Immer
	 */
	public static function redirect($strLocation, $intStatus = 303): never
	{
		throw new CapturedRedirect((string) $strLocation);
	}

	/**
	 * Fängt das Neuladen der Seite ab.
	 *
	 * @throws CapturedRedirect Immer, mit leerer Adresse
	 */
	public static function reload(): never
	{
		throw new CapturedRedirect('');
	}

	/**
	 * Legt einen Datensatz über DC_Table::create() an.
	 *
	 * Position, Elterndatensatz und Vorgabewerte bestimmt create() selbst aus
	 * den GET-Parametern pid und mode, die BackendEnvironment gesetzt hat. Der
	 * neue Datensatz hat danach tstamp 0 wie im Backend vor dem ersten
	 * Speichern; RecordWriter speichert ihn gleich anschließend.
	 *
	 * @param array<string, mixed> $set Werte, die direkt beim Anlegen gesetzt
	 *                                  werden, ohne Prüfung durch ein Widget
	 *
	 * @return int Die ID des neuen Datensatzes
	 *
	 * @throws \RuntimeException Wenn Contao keinen Datensatz angelegt hat
	 */
	public function createRecord(array $set): int
	{
		try {
			$this->create($set);
		} catch (CapturedRedirect $redirect) {
			if (str_starts_with($redirect->location, self::CREATED_PREFIX)) {
				return (int) substr($redirect->location, \strlen(self::CREATED_PREFIX));
			}
		}

		throw new \RuntimeException('Contao hat keinen Datensatz angelegt.');
	}

	/**
	 * Bereitet das Bearbeiten eines Datensatzes vor, wie der Anfang von edit().
	 *
	 * Unter Contao 5 prüft der DataContainer-Voter hier schon, ob der
	 * Datensatz überhaupt bearbeitet werden darf.
	 *
	 * @param int $id ID des Datensatzes
	 *
	 * @throws \RuntimeException Wenn es den Datensatz nicht gibt
	 */
	public function beginEdit(int $id): void
	{
		$this->intId = $id;

		if (self::isContao5()) {
			$currentRecord = $this->getCurrentRecord();

			if (null === $currentRecord) {
				throw new \RuntimeException(sprintf('Den Datensatz %d in %s gibt es nicht.', $id, $this->strTable));
			}

			$this->denyAccessUnlessGranted(ContaoCorePermissions::DC_PREFIX.$this->strTable, new UpdateAction($this->strTable, $currentRecord));
			$this->objActiveRecord = (object) $currentRecord;
			$this->arrSubmit = array();
		} else {
			$row = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')->limit(1)->execute($id);

			if ($row->numRows < 1) {
				throw new \RuntimeException(sprintf('Den Datensatz %d in %s gibt es nicht.', $id, $this->strTable));
			}

			$this->objActiveRecord = $row;
		}

		$this->values = array($id);
		$this->procedure = array('id=?');
		$this->blnCreateNewVersion = false;
		$this->editVersions = new Versions($this->strTable, $id);
		$this->editVersions->initialize();
	}

	/**
	 * Liefert die Felder der Palette, die für den Datensatz gerade gilt.
	 *
	 * Unter Contao 5 berücksichtigt die Palette schon die gesetzten, noch
	 * nicht gespeicherten Werte (etwa einen neuen Elementtyp), unter 4.13
	 * stehen sie nach saveField() ohnehin im aktiven Datensatz.
	 *
	 * @return list<string> Feldnamen ohne Legenden und Unterpaletten-Marken
	 */
	public function getPaletteFields(): array
	{
		$fields = array();

		foreach (StringUtil::trimsplit('[,;]', (string) $this->getPalette()) as $entry) {
			if ('' !== $entry && !preg_match('/^[{\[]/', $entry)) {
				$fields[] = $entry;
			}
		}

		return $fields;
	}

	/**
	 * Setzt ein Feld wie beim Absenden des Backend-Formulars.
	 *
	 * Abfolge wie in edit() und DataContainer::row(): bisherigen Wert laden
	 * (load_callback), Widget aus dem DCA bauen, Wert prüfen lassen, Ergebnis
	 * an save() geben (Datum, unique, save_callback, leere Werte). Der Wert
	 * muss vorher mit Input::setPost() unter dem Feldnamen abgelegt sein.
	 *
	 * @param string $field Feldname aus dem DCA
	 *
	 * @return string|null Eine Fehlermeldung des Widgets oder eines
	 *                     save_callback, null bei Erfolg
	 *
	 * @throws ResponseException Wenn ein Callback eine Antwort erzwingt
	 */
	public function saveField(string $field): ?string
	{
		$config = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field] ?? null;

		if (!\is_array($config)) {
			return 'Das Feld gibt es in dieser Tabelle nicht.';
		}

		$this->strField = $field;
		$this->strInputName = $field;
		$this->varValue = $this->getCurrentValue($field);

		// Felder mit CSV-Speicherung wie in edit() als Liste übergeben
		if (($config['eval']['multiple'] ?? null) && isset($config['eval']['csv'])) {
			$this->varValue = StringUtil::trimsplit($config['eval']['csv'], (string) $this->varValue);
		}

		foreach ((array) ($config['load_callback'] ?? array()) as $callback) {
			if (\is_array($callback)) {
				$this->varValue = System::importStatic($callback[0])->{$callback[1]}($this->varValue, $this);
			} elseif (\is_callable($callback)) {
				$this->varValue = $callback($this->varValue, $this);
			}
		}

		$this->objActiveRecord->{$field} = $this->varValue;

		$class = $GLOBALS['BE_FFL'][$config['inputType'] ?? ''] ?? null;

		if (null === $class || !class_exists($class)) {
			return 'Das Feld hat kein Eingabefeld im Backend und lässt sich deshalb nicht setzen.';
		}

		$data = $config;
		$data['eval']['required'] = false;

		if ($data['eval']['mandatory'] ?? null) {
			$data['eval']['required'] = \is_array($this->varValue) ? empty($this->varValue) : '' === (string) $this->varValue;
		}

		// Insert-Tags in src-Attributen wie in row() (#5965)
		if (isset($data['eval']['rte']) && str_starts_with((string) $data['eval']['rte'], 'tiny') && \is_string($this->varValue)) {
			$this->varValue = StringUtil::insertTagToSrc($this->varValue);
		}

		$useRawGlobally = true === ($GLOBALS['TL_DCA'][$this->strTable]['config']['useRawRequestData'] ?? null);

		if ($useRawGlobally && false !== ($data['eval']['useRawRequestData'] ?? null)) {
			$data['eval']['useRawRequestData'] = true;
		}

		/** @var Widget $widget */
		$widget = new $class($class::getAttributesFromDca($data, $field, $this->varValue, $field, $this->strTable, $this));
		$widget->currentRecord = $this->intId;

		if (!$widget->submitInput()) {
			return 'Das Feld ist schreibgeschützt.';
		}

		$widget->validate();

		if ($widget->hasErrors()) {
			return self::plainText($widget->getErrorsAsString(' '));
		}

		// submitInput() kann sich durch validate() ändern (#2383)
		if (!$widget->submitInput()) {
			return null;
		}

		$value = $widget->value;

		if (\is_array($value)) {
			ksort($value);
			$value = serialize($value);
		}

		if ($value && isset($data['eval']['rte']) && str_starts_with((string) $data['eval']['rte'], 'tiny')) {
			$value = StringUtil::srcToInsertTag($value);
		}

		try {
			$this->save($value);
		} catch (ResponseException|CapturedRedirect $exception) {
			throw $exception;
		} catch (\Exception $exception) {
			return self::plainText($exception->getMessage());
		}

		return null;
	}

	/**
	 * Schließt das Bearbeiten ab, wie das Ende von edit().
	 *
	 * Contao 5: submit() prüft die neuen Werte mit dem DataContainer-Voter,
	 * schreibt sie, leert die Cache-Tags, ruft onsubmit_callback auf und
	 * legt die Version an. Contao 4.13 hat die Werte schon geschrieben; hier
	 * folgen onsubmit_callback, tstamp (und ptable), Version und Cache-Tags.
	 */
	public function finishEdit(): void
	{
		if (self::isContao5()) {
			$this->submit();

			return;
		}

		foreach ((array) ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] ?? array()) as $callback) {
			if (\is_array($callback)) {
				System::importStatic($callback[0])->{$callback[1]}($this);
			} elseif (\is_callable($callback)) {
				$callback($this);
			}
		}

		$database = Database::getInstance();

		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
			$database->prepare('UPDATE '.$this->strTable.' SET ptable=?, tstamp=? WHERE id=?')->execute($this->ptable, time(), $this->intId);
		} else {
			$database->prepare('UPDATE '.$this->strTable.' SET tstamp=? WHERE id=?')->execute(time(), $this->intId);
		}

		if ($this->blnCreateNewVersion) {
			$this->editVersions?->create();
		}

		$this->invalidateCacheTags();
	}

	/**
	 * Stellt eine gespeicherte Version wieder her, wie „Version wiederherstellen“.
	 *
	 * @param int $id      ID des Datensatzes
	 * @param int $version Versionsnummer aus tl_version
	 */
	public function restoreVersion(int $id, int $version): void
	{
		$this->beginEdit($id);

		(new Versions($this->strTable, $id))->restore($version);

		if (self::isContao5()) {
			self::clearCurrentRecordCache($id, $this->strTable);
		}

		$this->invalidateCacheTags();
	}

	/**
	 * Löscht einen Datensatz samt Kindern über DC_Table::delete().
	 *
	 * Contao legt dabei alles in tl_undo ab, ruft ondelete_callback auf und
	 * leert die Cache-Tags; unter Contao 5 prüft vorher der Voter.
	 *
	 * @param int $id ID des Datensatzes
	 */
	public function deleteRecord(int $id): void
	{
		$this->intId = $id;
		$this->delete(true);
	}

	/**
	 * Gibt die ID eines neuen Datensatzes statt einer Bearbeiten-Adresse zurück.
	 *
	 * create() leitet danach weiter; createRecord() liest die ID aus.
	 *
	 * @param int|string $id ID des neuen Datensatzes
	 *
	 * @return string Eine Pseudo-Adresse mit der ID
	 */
	protected function switchToEdit($id)
	{
		return self::CREATED_PREFIX.(int) $id;
	}

	/**
	 * Liefert den gespeicherten Wert eines Feldes samt bisher gesetzter Werte.
	 *
	 * @param string $field Feldname
	 *
	 * @return mixed Der Wert aus der Datenbank, unter Contao 5 überlagert von
	 *               den Werten, die saveField() schon gesammelt hat
	 */
	private function getCurrentValue(string $field)
	{
		if (self::isContao5()) {
			if (\array_key_exists($field, $this->arrSubmit)) {
				return $this->arrSubmit[$field];
			}

			return $this->getCurrentRecord()[$field] ?? null;
		}

		return $this->objActiveRecord->{$field};
	}

	/**
	 * Gibt an, ob Contao 5 läuft.
	 *
	 * Erkennungszeichen ist DC_Table::submit(), das es erst seit Contao 5
	 * gibt; mit ihm hat sich die Art des Speicherns geändert.
	 *
	 * @return bool true unter Contao 5
	 */
	public static function isContao5(): bool
	{
		return method_exists(DC_Table::class, 'submit');
	}

	/**
	 * Macht aus einer Contao-Meldung schlichten Text.
	 *
	 * @param string $message Meldung, oft mit Entities und HTML
	 *
	 * @return string Die Meldung ohne Auszeichnung
	 */
	private static function plainText(string $message): string
	{
		return trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}
}
