<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Data;

/**
 * Bestimmt, welche Felder das Backend für einen Datensatz anzeigt.
 *
 * Nachgebaut nach Contaos PaletteBuilder::getPalette() (5.7, in 4.13
 * gleichlautend in DC_Table::getPalette()): Aus den Werten der
 * Selektorfelder werden Palettennamen gebildet, die erste vorhandene Palette
 * gilt, aktive Subpaletten werden hinter ihrem Selektor eingefügt. Der
 * onpalette_callback läuft hier nicht; er erwartet einen vollständigen
 * DataContainer.
 *
 * So sieht Claude bei einem Textelement nur dessen Felder und nicht die
 * Vorgabewerte von hundert Feldern anderer Elementtypen.
 */
final class PaletteResolver
{
	/**
	 * Liefert die Felder der Palette, die für einen Datensatz gilt.
	 *
	 * @param array<string, mixed> $dca DCA der Tabelle
	 * @param array<string, mixed> $row Der Datensatz
	 *
	 * @return list<string> Feldnamen in Palettenreihenfolge, ohne Doppelte;
	 *                      leer, wenn die Tabelle keine Paletten hat
	 */
	public static function getFields(array $dca, array $row): array
	{
		return self::parseFields(self::getPalette($dca, $row));
	}

	/**
	 * Wählt die Palette und setzt die aktiven Subpaletten ein.
	 *
	 * @param array<string, mixed> $dca DCA der Tabelle
	 * @param array<string, mixed> $row Der Datensatz
	 *
	 * @return string Die Palettendefinition, etwa „{type_legend},type,headline;…“
	 */
	public static function getPalette(array $dca, array $row): string
	{
		$palettes = (array) ($dca['palettes'] ?? array());
		$palette = (string) ($palettes['default'] ?? '');
		$selectors = (array) ($palettes['__selector__'] ?? array());

		if (array() === $selectors) {
			return $palette;
		}

		$values = array();
		$subpalettes = array();

		foreach ($selectors as $name) {
			$trigger = $row[$name] ?? null;
			$field = (array) ($dca['fields'][$name] ?? array());

			if ('checkbox' === ($field['inputType'] ?? null) && empty($field['eval']['multiple'])) {
				if ($trigger) {
					$values[] = $name;

					if (isset($dca['subpalettes'][$name])) {
						$subpalettes[$name] = (string) $dca['subpalettes'][$name];
					}
				}

				continue;
			}

			// Zeichenkettenvergleich, damit auch „0“ als Wert zählt
			if ('' !== (string) $trigger) {
				$values[] = (string) $trigger;
			}

			if (isset($dca['subpalettes'][$name.'_'.$trigger])) {
				$subpalettes[$name] = (string) $dca['subpalettes'][$name.'_'.$trigger];
			}
		}

		if (array() === $values) {
			$names = array('default');
		} elseif (\count($values) > 1) {
			// Selektoren, die nur eine Subpalette auslösen, zählen nicht (Contao #3738)
			foreach ($values as $key => $value) {
				if (isset($dca['subpalettes'][$value])) {
					unset($values[$key]);
				}
			}

			$names = self::combiner($values);
		} else {
			$names = array($values[0]);
		}

		foreach ($names as $name) {
			if (isset($palettes[$name])) {
				$palette = (string) $palettes[$name];
				break;
			}
		}

		foreach ($subpalettes as $selector => $fields) {
			$palette = (string) preg_replace('/\b'.preg_quote($selector, '/').'\b/i', $selector.','.$fields, $palette);
		}

		return $palette;
	}

	/**
	 * Bildet alle Kombinationen der Selektorwerte.
	 *
	 * Zeichengleich zu Contaos combiner(): Aus array('a', 'b') wird
	 * array(1 => 'b', 2 => 'ab', 3 => 'a') – in genau der Reihenfolge, in der
	 * Contao nach einer vorhandenen Palette sucht.
	 *
	 * @param array<string> $names Die Selektorwerte
	 *
	 * @return array<int, string> Die möglichen Palettennamen
	 */
	public static function combiner(array $names): array
	{
		$return = array('');

		foreach (array_values($names) as $name) {
			$buffer = array();

			foreach ($return as $key => $value) {
				$buffer[] = 0 === $key % 2 ? $value : $value.$name;
				$buffer[] = 0 === $key % 2 ? $value.$name : $value;
			}

			$return = $buffer;
		}

		return array_filter($return);
	}

	/**
	 * Zieht die Feldnamen aus einer Palettendefinition.
	 *
	 * @param string $palette Etwa „{title_legend},headline,type;{text_legend},text“
	 *
	 * @return list<string> Die Feldnamen ohne Legenden und ohne Doppelte
	 */
	public static function parseFields(string $palette): array
	{
		$fields = array();

		foreach (preg_split('/[,;]/', $palette) ?: array() as $part) {
			$part = trim($part);

			if ('' !== $part && '{' !== $part[0] && '[' !== $part[0]) {
				$fields[$part] = $part;
			}
		}

		return array_values($fields);
	}
}
