<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Date;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\Validator;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;

/**
 * Wandelt Werte von Claude in das, was das Backend-Formular senden würde.
 *
 * Claude bekommt Werte von record_get in lesbarer Form (ISO-Datum, Pfad
 * statt UUID, true/false) und schickt sie in derselben Form zurück. Das
 * Widget erwartet sie dagegen so, wie der Browser sie absendet: ein Datum
 * im Format der Installation, Dateien als UUID-Liste mit Kommas, eine
 * Checkbox als „1“ oder leer. Die eigentliche Prüfung macht danach das
 * Widget; hier wird nur die Schreibweise angeglichen.
 */
class ValueConverter
{
	/**
	 * Eingabearten, deren Wert eine Liste von Dateien ist.
	 */
	private const FILE_INPUTS = array('fileTree');

	/**
	 * Eingabearten, deren Wert eine Liste von Seiten ist.
	 */
	private const PAGE_INPUTS = array('pageTree');

	/**
	 * @param ContaoFramework $framework   Für Config und FilesModel
	 * @param Permissions     $permissions Prüft Dateifreigaben
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Permissions $permissions,
	) {
	}

	/**
	 * Wandelt einen Wert für ein Feld um.
	 *
	 * @param string               $field  Feldname, für Meldungen
	 * @param array<string, mixed> $config DCA-Definition des Feldes
	 * @param mixed                $value  Wert von Claude: Text, Zahl,
	 *                                     Wahrheitswert, Liste oder null
	 *
	 * @return string|array<mixed> Der POST-Wert
	 *
	 * @throws ToolCallException Bei einem Wert, der sich nicht zuordnen lässt,
	 *                           etwa einem unbekannten Dateipfad
	 */
	public function toPost(string $field, array $config, $value)
	{
		$this->framework->initialize();

		$inputType = (string) ($config['inputType'] ?? '');
		$eval = (array) ($config['eval'] ?? array());
		$multiple = !empty($eval['multiple']);

		if (null === $value) {
			return '';
		}

		if (\in_array($inputType, self::FILE_INPUTS, true)) {
			return implode(',', array_map(fn ($entry): string => $this->toUuid($field, $entry), self::toList($value)));
		}

		if (\in_array($inputType, self::PAGE_INPUTS, true)) {
			return implode(',', array_map('intval', self::toList($value)));
		}

		if ('checkbox' === $inputType && !$multiple) {
			return self::isTrue($value) ? '1' : '';
		}

		if (\in_array($inputType, array('checkbox', 'checkboxWizard'), true) || ('select' === $inputType && $multiple)) {
			return array_map('strval', self::toList($value));
		}

		if (\in_array($eval['rgxp'] ?? '', array('date', 'time', 'datim'), true)) {
			return $this->toDate($field, (string) $eval['rgxp'], $value);
		}

		if (\is_bool($value)) {
			return $value ? '1' : '';
		}

		if (\is_array($value)) {
			return $value;
		}

		return (string) $value;
	}

	/**
	 * Wandelt eine Datumsangabe in das Format der Installation.
	 *
	 * Angenommen werden ISO-Schreibweisen („2026-11-03“, „2026-11-03 18:30“,
	 * „2026-11-03T18:30:00“, „18:30“), Unix-Zeitstempel und Werte, die schon
	 * im Format der Installation stehen; diese gehen unverändert durch.
	 *
	 * @param string     $field Feldname, für Meldungen
	 * @param string     $rgxp  date, time oder datim
	 * @param int|string $value Die Angabe
	 *
	 * @return string Das Datum, wie das Backend-Formular es senden würde
	 */
	private function toDate(string $field, string $rgxp, $value): string
	{
		$value = trim((string) $value);

		if ('' === $value) {
			return '';
		}

		$timestamp = null;

		if (preg_match('/^\d{9,11}$/', $value)) {
			$timestamp = (int) $value;
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value) || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
			$parsed = strtotime(str_replace('T', ' ', $value));

			if (false === $parsed) {
				throw new ToolCallException(sprintf('„%s“ ist für das Feld %s kein gültiges Datum.', $value, $field));
			}

			$timestamp = $parsed;
		}

		if (null === $timestamp) {
			return $value;
		}

		$format = (string) $this->framework->getAdapter(Config::class)->get($rgxp.'Format');

		return $this->framework->getAdapter(Date::class)->parse($format, $timestamp);
	}

	/**
	 * Ermittelt die UUID einer Datei aus Pfad oder UUID.
	 *
	 * @param string $field Feldname, für Meldungen
	 * @param mixed  $entry Pfad wie „files/bilder/a.jpg“, UUID als Text oder
	 *                      ein Eintrag aus record_get mit „uuid“ oder „path“
	 *
	 * @return string Die UUID in Textform
	 *
	 * @throws ToolCallException Wenn die Datei unbekannt oder nicht freigegeben ist
	 */
	private function toUuid(string $field, $entry): string
	{
		if (\is_array($entry)) {
			$entry = $entry['uuid'] ?? $entry['path'] ?? '';
		}

		$entry = trim((string) $entry);
		$files = $this->framework->getAdapter(FilesModel::class);

		$model = Validator::isStringUuid($entry)
			? $files->findByUuid(StringUtil::uuidToBin($entry))
			: $files->findByPath(ltrim($entry, '/'));

		if (null === $model) {
			throw new ToolCallException(sprintf('Für das Feld %s gibt es die Datei „%s“ nicht in der Dateiverwaltung.', $field, $entry));
		}

		if (!$this->permissions->canReadPath((string) $model->path)) {
			throw new ToolCallException(sprintf('Die Datei %s liegt außerhalb deiner Dateifreigaben.', $model->path));
		}

		return StringUtil::binToUuid((string) $model->uuid);
	}

	/**
	 * Macht aus einer Angabe eine Liste.
	 *
	 * @param mixed $value Liste, Text mit Kommas oder Einzelwert
	 *
	 * @return list<mixed> Die Einträge ohne leere
	 */
	private static function toList($value): array
	{
		if (\is_array($value)) {
			return array_values(array_filter($value, static fn ($entry): bool => null !== $entry && '' !== $entry));
		}

		if (\is_bool($value)) {
			return $value ? array('1') : array();
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $entry): bool => '' !== $entry));
	}

	/**
	 * Deutet einen Wert als Ja oder Nein.
	 *
	 * @param mixed $value true/false, 1/0, „1“, „true“, „ja“ …
	 *
	 * @return bool true für einen gesetzten Haken
	 */
	private static function isTrue($value): bool
	{
		if (\is_bool($value)) {
			return $value;
		}

		return \in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'ja', 'on'), true);
	}
}
