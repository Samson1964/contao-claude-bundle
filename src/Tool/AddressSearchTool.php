<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * address_search: die Adressverwaltung durchsuchen.
 *
 * Für schachbulle/contao-adressen-bundle (tl_adressen). Ohne das Bundle
 * erscheint das Werkzeug nicht. Claude sieht, was der Backend-Zugang in der
 * Detailansicht sieht (mit Frank so entschieden), außer IBAN und BIC, die
 * TableAccess immer zurückhält. Die Funktionen kommen mit ihren Namen statt
 * als Nummern. Ändern geht über record_save mit den Rechten des Backends.
 */
class AddressSearchTool extends AbstractReadTool
{
	/**
	 * Spalten, in denen der Suchtext gesucht wird, sofern vorhanden.
	 */
	private const SEARCH_COLUMNS = array('nachname', 'vorname', 'firma', 'club', 'ort', 'searchstring');

	/**
	 * Interne Spalten, die nicht in die Ausgabe gehören.
	 */
	private const HIDDEN_COLUMNS = array('searchstring', 'source', 'tstamp');

	/**
	 * @param TableAccess     $tableAccess Rechte und lesbare Felder
	 * @param RecordFormatter $formatter   Werte wie bei record_get
	 * @param Connection      $connection  Abfragen
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly RecordFormatter $formatter,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'address_search';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Adressen durchsuchen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Durchsucht die Adressverwaltung (Funktionäre, Ansprechpartner, Vereine) nach Name, Verein, Firma oder Ort und nach Funktion (etwa „Präsident“, Teilstück genügt). Liefert je Treffer die gefüllten Felder mit Funktionen, Telefon, E-Mail, Anschrift und Sichtbarkeitsangaben (*_view: was davon öffentlich gezeigt wird). Standardmäßig nur aktive Adressen. Ändern mit record_save (table „tl_adressen“, id). Persönliche Daten nur für die Aufgabe verwenden, um die gebeten wurde.';
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
				'query' => array('type' => 'string', 'description' => 'Suchtext in Name, Verein, Firma und Ort.'),
				'function' => array('type' => 'string', 'description' => 'Name einer Funktion oder ein Teil davon, etwa „Referent“.'),
				'onlyActive' => array('type' => 'boolean', 'description' => 'false: auch inaktive Adressen. Vorgabe true.'),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Höchstzahl der Treffer, Vorgabe 25.'),
			),
		);
	}

	/**
	 * Erscheint nur, wenn das Adressen-Bundle installiert ist.
	 *
	 * @return bool true, wenn es die Tabelle gibt
	 */
	public function isAvailable(): bool
	{
		return $this->connection->createSchemaManager()->tablesExist(array('tl_adressen'));
	}

	/**
	 * Sucht Adressen.
	 *
	 * @param string|null $query      Suchtext
	 * @param string|null $function   Funktion
	 * @param bool        $onlyActive Nur aktive
	 * @param int         $limit      Höchstzahl
	 *
	 * @return array<string, mixed> count, functions (bei Funktionssuche) und addresses
	 */
	public function __invoke(?string $query = null, ?string $function = null, bool $onlyActive = true, int $limit = 25): array
	{
		$dca = $this->tableAccess->assertReadable('tl_adressen');
		$readable = $this->tableAccess->getReadableFields('tl_adressen', $dca);
		$labels = $this->functionLabels($dca);
		$where = array('1 = 1');
		$params = array();
		$result = array();

		if ($onlyActive && isset($readable['aktiv'])) {
			$where[] = "aktiv = '1'";
		}

		$query = null === $query ? '' : trim($query);

		if ('' !== $query) {
			$like = array();

			foreach (self::SEARCH_COLUMNS as $column) {
				if (isset($dca['fields'][$column])) {
					$like[] = $column.' LIKE ?';
					$params[] = '%'.addcslashes($query, '%_\\').'%';
				}
			}

			$where[] = '('.implode(' OR ', $like).')';
		}

		$function = null === $function ? '' : trim($function);

		if ('' !== $function) {
			$ids = array_keys(array_filter($labels, static fn (string $label): bool => false !== mb_stripos($label, $function)));
			$result['functions'] = array_values(array_intersect_key($labels, array_flip($ids)));

			if (array() === $ids) {
				return $result + array('count' => 0, 'addresses' => array(), 'hint' => 'Keine Funktion passt zu „'.$function.'“.');
			}

			// funktionen ist eine serialisierte Liste von IDs als Text: s:2:"11";
			$where[] = '('.implode(' OR ', array_fill(0, \count($ids), 'funktionen LIKE ?')).')';

			foreach ($ids as $id) {
				$params[] = '%"'.$id.'";%';
			}
		}

		$rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_adressen WHERE '.implode(' AND ', $where).' ORDER BY nachname, vorname LIMIT '.self::clampLimit($limit), $params);
		$fields = array_diff_key($readable, array_flip(self::HIDDEN_COLUMNS));
		$addresses = array();

		foreach ($rows as $row) {
			$address = $this->formatter->format($row, $fields, true);

			if (isset($address['funktionen']) && \is_array($address['funktionen'])) {
				$address['funktionen'] = array_values(array_map(static fn ($id): string => $labels[(string) $id] ?? (string) $id, $address['funktionen']));
			}

			$addresses[] = $address;
		}

		return $result + array('count' => \count($addresses), 'addresses' => $addresses);
	}

	/**
	 * Liefert die Namen der Funktionen aus dem options_callback des Bundles.
	 *
	 * @param array<string, mixed> $dca DCA von tl_adressen
	 *
	 * @return array<string, string> ID => Name; leer, wenn der Callback fehlt oder scheitert
	 */
	private function functionLabels(array $dca): array
	{
		$callback = $dca['fields']['funktionen']['options_callback'] ?? null;
		$options = $dca['fields']['funktionen']['options'] ?? array();

		try {
			if (\is_array($callback)) {
				$options = System::importStatic($callback[0])->{$callback[1]}();
			} elseif (\is_callable($callback)) {
				$options = $callback();
			}
		} catch (\Throwable) {
			return array();
		}

		$labels = array();

		foreach ((array) $options as $key => $label) {
			if (\is_array($label)) {
				// Gruppierte Optionen
				foreach ($label as $subKey => $subLabel) {
					$labels[(string) $subKey] = html_entity_decode(StringUtil::decodeEntities((string) $subLabel), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				}
			} else {
				$labels[(string) $key] = html_entity_decode(StringUtil::decodeEntities((string) $label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}

		return $labels;
	}
}
