<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Schachbulle\ContaoClaudeBundle\Write\RecordWriter;
use Schachbulle\ContaoClaudeBundle\Write\ResultBuilder;

/**
 * publish_toggle: einen Datensatz veröffentlichen oder verstecken.
 *
 * Erscheint nur, wenn das Veröffentlichen in den Einstellungen eigens
 * freigegeben ist.
 */
class PublishToggleTool extends AbstractWriteTool
{
	/**
	 * @param RecordWriter  $writer  Setzt das Feld
	 * @param ResultBuilder $results Baut die Antwort
	 */
	public function __construct(
		private readonly RecordWriter $writer,
		private readonly ResultBuilder $results,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'publish_toggle';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Veröffentlichen oder verstecken';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Macht einen Datensatz für Besucher sichtbar (published: true) oder versteckt ihn (false): Nachrichten, Termine, Seiten, Artikel (Feld published) und Inhaltselemente (Feld invisible). Start- und Endzeit bleiben, wie sie sind. Nur auf ausdrücklichen Wunsch des Benutzers verwenden; die Seite ist danach sofort öffentlich.';
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
				'id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'ID des Datensatzes.'),
				'published' => array('type' => 'boolean', 'description' => 'true: sichtbar machen, false: verstecken.'),
			),
			'required' => array('table', 'id', 'published'),
		);
	}

	/**
	 * Gibt das Werkzeug nur bei freigegebenem Veröffentlichen frei.
	 *
	 * @return bool true, wenn Schreiben und Veröffentlichen erlaubt sind
	 */
	public function isAvailable(): bool
	{
		return null !== $this->settings && $this->settings->isPublishEnabled();
	}

	/**
	 * Setzt die Sichtbarkeit.
	 *
	 * @param string $table     Tabelle
	 * @param int    $id        ID
	 * @param bool   $published Sichtbar oder nicht
	 *
	 * @return array<string, mixed> Der Datensatz danach, mit Backend-Link
	 */
	public function __invoke(string $table, int $id, bool $published): array
	{
		$field = $this->writer->setPublished($this->getName(), $table, $id, $published);

		return $this->results->build($table, $id, $published ? 'published' : 'hidden', array('field' => $field));
	}

	/**
	 * Derselbe Zustand noch einmal gesetzt ändert nichts mehr.
	 *
	 * @return bool true
	 */
	protected function isIdempotent(): bool
	{
		return true;
	}
}
