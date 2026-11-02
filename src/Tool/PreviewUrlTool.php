<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\ContentUrls;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * preview_url: Live- und Vorschauadresse einer Seite, eines Artikels, einer
 * Nachricht oder eines Termins.
 */
class PreviewUrlTool extends AbstractReadTool
{
	/**
	 * @param TableAccess $tableAccess Prüft Tabellen- und Datensatzrecht
	 * @param ContentUrls $urls        Erzeugt die Adressen
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly ContentUrls $urls,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'preview_url';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Adresse und Vorschau';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Liefert die öffentliche Adresse und die Vorschauadresse einer Seite, eines Artikels, einer Nachricht oder eines Termins, dazu ob der Datensatz gerade sichtbar ist. Die Vorschau zeigt Unveröffentlichtes nur in einem Browser, der im Contao-Backend angemeldet ist.';
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
				'table' => array('type' => 'string', 'enum' => array_keys(ContentUrls::TABLES), 'description' => 'Tabelle des Datensatzes.'),
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Datensatzes.'),
			),
			'required' => array('table', 'id'),
		);
	}

	/**
	 * Liefert die Adressen.
	 *
	 * @param string $table Eine Tabelle aus ContentUrls::TABLES
	 * @param int    $id    ID des Datensatzes
	 *
	 * @return array<string, mixed> url, preview_url, published, visible
	 */
	public function __invoke(string $table, int $id): array
	{
		if (!isset(ContentUrls::TABLES[$table])) {
			throw new ToolCallException(sprintf('Für %s gibt es keine Adressen. Möglich sind: %s.', $table, implode(', ', array_keys(ContentUrls::TABLES))));
		}

		$this->tableAccess->assertReadable($table);

		if (!$this->tableAccess->canReadRow($table, $id)) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht oder er ist für dich nicht freigegeben.', $id, $table));
		}

		$model = $this->urls->findModel($table, $id);

		if (null === $model) {
			throw new ToolCallException(sprintf('Den Datensatz %d in %s gibt es nicht.', $id, $table));
		}

		try {
			$url = $this->urls->getLiveUrl($model);
		} catch (\Throwable $exception) {
			throw new ToolCallException(sprintf('Contao kann für %s.%d keine Adresse bilden: %s', $table, $id, $exception->getMessage()));
		}

		$row = $model->row();

		return array(
			'table' => $table,
			'id' => $id,
			'url' => $url,
			'preview_url' => $this->urls->getPreviewUrl($url),
			'published' => (bool) ($row['published'] ?? false),
			'visible' => self::isVisible($row),
		);
	}
}
