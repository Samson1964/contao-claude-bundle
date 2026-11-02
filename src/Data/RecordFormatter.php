<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Data;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\Validator;

/**
 * Bereitet Datenbankwerte so auf, dass Claude sie lesen kann.
 *
 * - Binäre UUIDs (Dateifelder) werden zu {uuid, path}.
 * - Serialisierte Felder werden zu Listen bzw. Objekten.
 * - Zeitstempel in Datumsfeldern werden zu ISO-8601-Zeichenketten.
 * - Contaos Ersatzschreibweisen für Entities ([nbsp], [lt], [&] …) werden
 *   zurückgewandelt; im Backend sieht der Redakteur dieselben Zeichen.
 */
class RecordFormatter
{
	/**
	 * @var array<string, array{uuid: string, path: string|null}> Zwischenspeicher je UUID
	 */
	private array $files = array();

	/**
	 * @param ContaoFramework $framework Für den Adapter auf FilesModel
	 */
	public function __construct(private readonly ContaoFramework $framework)
	{
	}

	/**
	 * Bereitet einen Datensatz auf.
	 *
	 * @param array<string, mixed>                $row       Die Zeile aus der Datenbank
	 * @param array<string, array<string, mixed>> $fields    Erlaubte Felder mit DCA-Definition
	 *                                                       (aus TableAccess::getReadableFields())
	 * @param bool                                $skipEmpty Leere Werte (null, „“, leere Liste) weglassen
	 *
	 * @return array<string, mixed> Feldname => aufbereiteter Wert, in der Reihenfolge von $fields
	 */
	public function format(array $row, array $fields, bool $skipEmpty = false): array
	{
		$result = array();

		foreach ($fields as $name => $config) {
			if (!\array_key_exists($name, $row)) {
				continue;
			}

			$value = $this->formatValue($name, $row[$name], $config);

			if ($skipEmpty && (null === $value || '' === $value || array() === $value)) {
				continue;
			}

			$result[$name] = $value;
		}

		return $result;
	}

	/**
	 * Bereitet einen einzelnen Wert auf.
	 *
	 * @param string               $name   Feldname; id, pid und sorting werden zu Zahlen
	 * @param mixed                $value  Der Wert aus der Datenbank
	 * @param array<string, mixed> $config Die DCA-Definition des Feldes, leer bei Systemspalten
	 *
	 * @return mixed Der aufbereitete Wert
	 */
	public function formatValue(string $name, $value, array $config = array())
	{
		if (null === $value) {
			return null;
		}

		// tl_files führt pid als binäre UUID des Elternordners
		if (\is_string($value) && Validator::isBinaryUuid($value)) {
			return $this->describeFile($value);
		}

		if (\in_array($name, array('id', 'pid', 'sorting'), true) && is_numeric($value)) {
			return (int) $value;
		}

		$rgxp = (string) ($config['eval']['rgxp'] ?? '');
		$inputType = (string) ($config['inputType'] ?? '');

		if ('tstamp' === $name || \in_array($rgxp, array('date', 'datim', 'time'), true)) {
			return self::formatDate($value, 'tstamp' === $name ? 'datim' : $rgxp);
		}

		// Einzelne Checkbox: Contao speichert „1“ bzw. „“ (4.13) oder 1/0 (5.x)
		if ('checkbox' === $inputType && empty($config['eval']['multiple']) && !\is_array($value) && !str_starts_with((string) $value, 'a:')) {
			return (bool) $value;
		}

		if (!\is_string($value)) {
			return $value;
		}

		if (str_starts_with($value, 'a:') && str_ends_with($value, '}')) {
			$array = StringUtil::deserialize($value);

			// Überschrift ohne Text (inputUnit): für Claude ein leeres Feld
			if (\is_array($array) && 'inputUnit' === $inputType && '' === (string) ($array['value'] ?? '')) {
				return '';
			}

			if (\is_array($array)) {
				return $this->formatArray($array);
			}
		}

		if (!mb_check_encoding($value, 'UTF-8')) {
			return sprintf('(Binärdaten, %d Byte)', \strlen($value));
		}

		return StringUtil::restoreBasicEntities($value);
	}

	/**
	 * Liefert UUID und Pfad einer Datei aus der Dateiverwaltung.
	 *
	 * @param string $uuid Die UUID, binär (16 Byte) oder lesbar (36 Zeichen)
	 *
	 * @return array{uuid: string, path: string|null} path ist null, wenn die
	 *                                                Datei nicht mehr in tl_files steht
	 */
	public function describeFile(string $uuid): array
	{
		if (Validator::isBinaryUuid($uuid)) {
			$uuid = StringUtil::binToUuid($uuid);
		}

		if (!isset($this->files[$uuid])) {
			$this->framework->initialize();
			$model = $this->framework->getAdapter(FilesModel::class)->findByUuid($uuid);

			$this->files[$uuid] = array('uuid' => $uuid, 'path' => null === $model ? null : (string) $model->path);
		}

		return $this->files[$uuid];
	}

	/**
	 * Kürzt HTML auf einen lesbaren Textauszug.
	 *
	 * @param mixed $html   Der HTML-Text, etwa ein Teaser
	 * @param int   $length Höchstlänge in Zeichen
	 *
	 * @return string Reiner Text ohne Tags, mit „…“ gekürzt
	 */
	public static function excerpt($html, int $length = 200): string
	{
		$text = html_entity_decode(strip_tags(StringUtil::restoreBasicEntities((string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string) preg_replace('/\s+/u', ' ', $text));

		return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
	}

	/**
	 * Wandelt einen Contao-Zeitstempel in ISO 8601.
	 *
	 * Contao speichert Datum, Datum mit Zeit und reine Uhrzeit gleichermaßen
	 * als Unix-Zeitstempel. Leere Felder stehen als „“ oder 0 in der
	 * Datenbank.
	 *
	 * @param mixed  $value Der gespeicherte Wert
	 * @param string $rgxp  „date“, „datim“ oder „time“
	 *
	 * @return string|null „2026-11-02“, „2026-11-02T14:30:00+01:00“ bzw. „14:30“,
	 *                     oder null bei leerem Feld
	 */
	public static function formatDate($value, string $rgxp = 'datim'): ?string
	{
		if (!is_numeric($value) || 0 === (int) $value) {
			return null;
		}

		$timestamp = (int) $value;

		if ('date' === $rgxp) {
			return date('Y-m-d', $timestamp);
		}

		if ('time' === $rgxp) {
			return date('H:i', $timestamp);
		}

		return date('c', $timestamp);
	}

	/**
	 * Bereitet eine deserialisierte Liste auf.
	 *
	 * Binäre UUIDs in Listen (multiSRC, orderSRC) werden wie Einzelwerte zu
	 * {uuid, path}; Zeichenketten bekommen ihre Entities zurück.
	 *
	 * @param array<mixed> $array Die Liste
	 *
	 * @return array<mixed> Die aufbereitete Liste
	 */
	private function formatArray(array $array): array
	{
		foreach ($array as $key => $item) {
			if (\is_array($item)) {
				$array[$key] = $this->formatArray($item);
			} elseif (\is_string($item) && Validator::isBinaryUuid($item)) {
				$array[$key] = $this->describeFile($item);
			} elseif (\is_string($item)) {
				$array[$key] = mb_check_encoding($item, 'UTF-8') ? StringUtil::restoreBasicEntities($item) : sprintf('(Binärdaten, %d Byte)', \strlen($item));
			}
		}

		return $array;
	}
}
