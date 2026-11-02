<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;

/**
 * dca_describe: Aufbau einer Tabelle laut DCA.
 *
 * Damit kann Claude auch Tabellen eigener Erweiterungen verstehen, für die
 * es kein eigenes Werkzeug gibt. Optionen aus options_callback werden
 * bewusst nicht ausgeführt: Rückrufe können Seiteneffekte haben und
 * erwarten oft einen vollständigen Backend-Kontext.
 */
class DcaDescribeTool extends AbstractReadTool
{
	/**
	 * Schlüssel aus „eval“, die für Claude beim Lesen und späteren Schreiben
	 * von Belang sind.
	 */
	private const EVAL_KEYS = array('mandatory', 'rgxp', 'maxlength', 'minlength', 'minval', 'maxval', 'multiple', 'fieldType', 'unique', 'allowHtml', 'rte', 'isAssociative', 'includeBlankOption', 'nullIfEmpty', 'extensions', 'filesOnly', 'size');

	/**
	 * @param TableAccess $tableAccess Prüft die Rechte und liefert die lesbaren Felder
	 * @param Permissions $permissions Sagt, welche Felder der Benutzer bearbeiten darf
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly Permissions $permissions,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'dca_describe';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Tabellenaufbau';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Beschreibt eine Contao-Tabelle laut DCA: Eltern- und Kindtabellen, alle für dich sichtbaren Felder mit Beschriftung, Eingabetyp, Pflichtangabe, Prüfregel (rgxp), Optionen, Fremdschlüssel und ob du das Feld bearbeiten darf (editable), dazu die Paletten (welche Felder je Typ gelten). Hilft vor record_list und record_get bei unbekannten Tabellen.';
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
				'table' => array('type' => 'string', 'description' => 'Tabellenname, etwa „tl_news“ oder „tl_content“.'),
			),
			'required' => array('table'),
		);
	}

	/**
	 * Beschreibt die Tabelle.
	 *
	 * @param string $table Tabellenname
	 *
	 * @return array<string, mixed> table, label, config, fields, palettes, subpalettes
	 */
	public function __invoke(string $table): array
	{
		$dca = $this->tableAccess->assertReadable($table);
		$fields = $this->tableAccess->getReadableFields($table, $dca);
		$described = array();

		foreach ($fields as $name => $config) {
			$described[$name] = $this->describeField($table, $name, $config);
		}

		$palettes = array();

		foreach ((array) ($dca['palettes'] ?? array()) as $palette => $definition) {
			if ('__selector__' !== $palette && \is_string($definition)) {
				$palettes[$palette] = self::paletteFields($definition, $fields);
			}
		}

		$subpalettes = array();

		foreach ((array) ($dca['subpalettes'] ?? array()) as $subpalette => $definition) {
			if (\is_string($definition)) {
				$subpalettes[$subpalette] = self::paletteFields($definition, $fields);
			}
		}

		return array(
			'table' => $table,
			'modules' => $this->tableAccess->getModulesForTable($table),
			'config' => array(
				'ptable' => $dca['config']['ptable'] ?? null,
				'ctable' => $dca['config']['ctable'] ?? null,
				'dynamicPtable' => (bool) ($dca['config']['dynamicPtable'] ?? false),
			),
			'selectors' => array_values((array) ($dca['palettes']['__selector__'] ?? array())),
			'fields' => $described,
			'palettes' => $palettes,
			'subpalettes' => $subpalettes,
		);
	}

	/**
	 * Beschreibt ein Feld.
	 *
	 * @param string               $table  Tabellenname, für die Sprachdatei
	 * @param string               $name   Feldname
	 * @param array<string, mixed> $config DCA-Definition des Feldes
	 *
	 * @return array<string, mixed> label, description, inputType, eval, options …
	 */
	private function describeField(string $table, string $name, array $config): array
	{
		$label = $config['label'] ?? ($GLOBALS['TL_LANG'][$table][$name] ?? null);
		$field = array(
			'label' => \is_array($label) ? (string) ($label[0] ?? '') : (\is_string($label) ? $label : null),
			'description' => \is_array($label) ? ($label[1] ?? null) : null,
			'inputType' => $config['inputType'] ?? null,
		);

		$eval = array_intersect_key((array) ($config['eval'] ?? array()), array_flip(self::EVAL_KEYS));

		if (array() !== $eval) {
			$field['eval'] = $eval;
		}

		if (isset($config['options']) && \is_array($config['options'])) {
			$field['options'] = self::describeOptions($config['options'], (array) ($config['reference'] ?? array()));
		} elseif (isset($config['options_callback'])) {
			$field['options'] = '(dynamisch, aus einem Rückruf)';
		}

		if (isset($config['foreignKey'])) {
			$field['foreignKey'] = $config['foreignKey'];
		}

		if (\array_key_exists('default', $config) && !\is_object($config['default'])) {
			$field['default'] = $config['default'];
		}

		// Lesen darf Claude jedes ausgegebene Feld; bearbeiten nur freigegebene
		$field['editable'] = $this->permissions->canEditField($table, $name, $config);

		return array_filter($field, static fn ($value): bool => null !== $value && '' !== $value);
	}

	/**
	 * Bereitet feste Optionen samt Beschriftungen auf.
	 *
	 * @param array<mixed>        $options   Die Optionen aus dem DCA (Liste oder Schlüssel => Wert)
	 * @param array<string,mixed> $reference Beschriftungen aus „reference“
	 *
	 * @return array<string, string|array<string, string>> Gespeicherter Wert => Beschriftung,
	 *                                                     Gruppen verschachtelt
	 */
	private static function describeOptions(array $options, array $reference): array
	{
		$isList = array_is_list($options);
		$result = array();

		foreach ($options as $key => $value) {
			if (\is_array($value)) {
				$result[(string) $key] = self::describeOptions($value, $reference);
				continue;
			}

			// Bei einer Liste speichert Contao den Wert selbst, sonst den Schlüssel
			$stored = (string) ($isList ? $value : $key);
			$label = $reference[$stored] ?? $value;

			$result[$stored] = \is_array($label) ? (string) ($label[0] ?? $stored) : (string) $label;
		}

		return $result;
	}

	/**
	 * Zieht die Feldnamen aus einer Palettendefinition.
	 *
	 * Legenden ({title_legend}, {expert_legend:hide}) und Felder, die der
	 * Benutzer nicht sehen darf, fallen heraus.
	 *
	 * @param string                              $definition Etwa „{title_legend},headline,type;{text_legend},text“
	 * @param array<string, array<string, mixed>> $fields     Die lesbaren Felder
	 *
	 * @return list<string> Die Feldnamen in Palettenreihenfolge
	 */
	private static function paletteFields(string $definition, array $fields): array
	{
		$names = array();

		foreach (preg_split('/[,;]/', $definition) ?: array() as $part) {
			$part = trim($part);

			if ('' !== $part && '{' !== $part[0] && isset($fields[$part])) {
				$names[] = $part;
			}
		}

		return $names;
	}
}
