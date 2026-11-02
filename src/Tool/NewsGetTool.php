<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\ContentElements;
use Schachbulle\ContaoClaudeBundle\Data\ContentUrls;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;

/**
 * news_get: eine Nachricht mit allen Feldern und Inhaltselementen.
 */
class NewsGetTool extends AbstractReadTool
{
	/**
	 * @param TableAccess     $tableAccess Prüft Modul- und Archivrechte
	 * @param RecordFormatter $formatter   Bereitet die Felder auf
	 * @param ContentElements $elements    Liest die Inhaltselemente
	 * @param ContentUrls     $urls        Erzeugt die Adresse der Nachricht
	 * @param Connection      $connection  Liest Nachricht, Archiv und Autor
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly RecordFormatter $formatter,
		private readonly ContentElements $elements,
		private readonly ContentUrls $urls,
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
		return 'news_get';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Nachricht lesen';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Liefert eine Nachricht vollständig: alle lesbaren Felder (Überschrift, Teaser, Bild, Datum, Veröffentlichung …), Archiv, Autor, öffentliche Adresse und alle Inhaltselemente mit ihren Feldern. Leere Felder werden weggelassen.';
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
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID der Nachricht, etwa aus news_search.'),
			),
			'required' => array('id'),
		);
	}

	/**
	 * Liefert die Nachricht.
	 *
	 * @param int $id ID der Nachricht
	 *
	 * @return array<string, mixed> news, archive, author, url, visible, elements
	 */
	public function __invoke(int $id): array
	{
		$dca = $this->tableAccess->assertReadable('tl_news');

		if (!$this->tableAccess->canReadRow('tl_news', $id)) {
			throw new ToolCallException(sprintf('Die Nachricht %d gibt es nicht oder ihr Archiv ist für dich nicht freigegeben.', $id));
		}

		$row = $this->connection->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', array($id));

		if (false === $row) {
			throw new ToolCallException(sprintf('Die Nachricht %d gibt es nicht.', $id));
		}

		$archive = $this->connection->fetchOne('SELECT title FROM tl_news_archive WHERE id = ?', array((int) $row['pid']));
		$author = $this->connection->fetchOne('SELECT name FROM tl_user WHERE id = ?', array((int) $row['author']));
		$url = null;

		try {
			$model = $this->urls->findModel('tl_news', $id);
			$url = null === $model ? null : $this->urls->getLiveUrl($model);
		} catch (\Throwable $exception) {
			// Ohne Weiterleitungsseite im Archiv gibt es keine Adresse
		}

		return array(
			'news' => $this->formatter->format($row, $this->tableAccess->getVisibleFields('tl_news', $dca, $row), true),
			'archive' => array('id' => (int) $row['pid'], 'title' => false === $archive ? null : (string) $archive),
			'author' => false === $author ? null : (string) $author,
			'url' => $url,
			'visible' => self::isVisible($row),
			'elements' => $this->elements->listFor('tl_news', $id, true),
		);
	}
}
