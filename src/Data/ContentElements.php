<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Data;

use Doctrine\DBAL\Connection;

/**
 * Liest die Inhaltselemente eines Elternelements.
 *
 * Wird von content_list und news_get gemeinsam genutzt. Die Rechte auf das
 * Elternelement prüft der Aufrufer; hier wird nur noch tl_content selbst
 * geprüft.
 */
class ContentElements
{
	/**
	 * @param TableAccess     $tableAccess Prüft tl_content und liefert die lesbaren Felder
	 * @param RecordFormatter $formatter   Bereitet die Werte auf
	 * @param Connection      $connection  Liest die Elemente
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly RecordFormatter $formatter,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Liefert die Elemente eines Elternelements in ihrer Reihenfolge.
	 *
	 * Die Kurzfassung enthält ID, Typ, versteckt, Überschrift, einen
	 * Textauszug aus text, html oder code und das Bild (singleSRC). Die
	 * Vollfassung enthält die Felder der Palette des jeweiligen Elementtyps,
	 * ohne leere Werte. Ein leeres ptable bei tl_article stammt aus Contao 3
	 * und wird mitgelesen.
	 *
	 * @param string $parentTable Tabelle des Elternelements
	 * @param int    $parentId    ID des Elternelements
	 * @param bool   $full        Felder der Palette statt der Kurzfassung
	 *
	 * @return array<int, array<string, mixed>> Die Elemente
	 */
	public function listFor(string $parentTable, int $parentId, bool $full = false): array
	{
		$dca = $this->tableAccess->assertReadable('tl_content');
		$fields = $this->tableAccess->getReadableFields('tl_content', $dca);

		$ptables = 'tl_article' === $parentTable ? array('tl_article', '') : array($parentTable);
		$rows = $this->connection->fetchAllAssociative(
			'SELECT * FROM tl_content WHERE pid = ? AND ptable IN (?'.str_repeat(', ?', \count($ptables) - 1).') ORDER BY sorting',
			array_merge(array($parentId), $ptables)
		);

		$elements = array();

		foreach ($rows as $row) {
			if ($full) {
				$elements[] = $this->formatter->format($row, $this->tableAccess->getVisibleFields('tl_content', $dca, $row), true);
				continue;
			}

			$element = array(
				'id' => (int) $row['id'],
				'type' => (string) $row['type'],
				'invisible' => (bool) ($row['invisible'] ?? false),
			);

			if (isset($fields['headline'])) {
				$headline = $this->formatter->formatValue('headline', $row['headline'] ?? null, $fields['headline']);

				if (\is_array($headline) && '' !== (string) ($headline['value'] ?? '')) {
					$element['headline'] = $headline['value'];
					$element['headline_unit'] = $headline['unit'] ?? null;
				}
			}

			foreach (array('text', 'html', 'code') as $field) {
				if (isset($fields[$field]) && '' !== trim((string) ($row[$field] ?? ''))) {
					$element['excerpt'] = RecordFormatter::excerpt($row[$field]);
					break;
				}
			}

			if (isset($fields['singleSRC']) && '' !== (string) ($row['singleSRC'] ?? '')) {
				$element['singleSRC'] = $this->formatter->formatValue('singleSRC', $row['singleSRC'], $fields['singleSRC']);
			}

			$elements[] = $element;
		}

		return $elements;
	}
}
