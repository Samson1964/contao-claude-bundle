<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Mcp\Schema\ToolAnnotations;
use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Gemeinsame Grundlage der schreibenden Werkzeuge.
 *
 * Sie brauchen den Scope „contao:write“ und erscheinen nur, wenn das
 * Schreiben in den Einstellungen freigegeben ist. Die Hinweise sagen
 * Claude: verändert Daten, bei wiederholtem Aufruf nicht dasselbe
 * Ergebnis (ein zweites create legt einen zweiten Datensatz an), nur diese
 * Installation.
 */
abstract class AbstractWriteTool implements ToolInterface
{
	/**
	 * @var Settings|null Die Einstellungen; per Setter, damit die Werkzeuge
	 *                    ihre eigenen Konstruktoren behalten
	 */
	protected ?Settings $settings = null;

	/**
	 * Setzt die Einstellungen (vom Container über #[Required] aufgerufen).
	 *
	 * @param Settings $settings Die Einstellungen
	 */
	#[Required]
	public function setSettings(Settings $settings): void
	{
		$this->settings = $settings;
	}

	/**
	 * Liefert die Hinweise für ein schreibendes Werkzeug.
	 *
	 * @return ToolAnnotations Nicht lesend, destructive nach isDestructive()
	 */
	public function getAnnotations(): ToolAnnotations
	{
		return new ToolAnnotations($this->getTitle(), false, $this->isDestructive(), $this->isIdempotent(), false);
	}

	/**
	 * Schreibende Werkzeuge brauchen den Scope „contao:write“.
	 *
	 * @return string Der Scope
	 */
	public function getRequiredScope(): string
	{
		return AuthenticatedClient::SCOPE_WRITE;
	}

	/**
	 * Schreibende Werkzeuge gibt es nur bei freigegebenem Schreiben.
	 *
	 * @return bool true, wenn der Schalter in den Einstellungen gesetzt ist
	 */
	public function isAvailable(): bool
	{
		return null !== $this->settings && $this->settings->isWriteEnabled();
	}

	/**
	 * Gibt an, ob das Werkzeug Daten entfernt oder überschreibt.
	 *
	 * @return bool Vorgabe false; überschrieben von record_delete und record_restore
	 */
	protected function isDestructive(): bool
	{
		return false;
	}

	/**
	 * Gibt an, ob ein wiederholter Aufruf nichts weiter ändert.
	 *
	 * @return bool Vorgabe false; true für Änderungen mit festen Werten
	 */
	protected function isIdempotent(): bool
	{
		return false;
	}

	/**
	 * Beschreibung der Werteform, die in mehreren Werkzeugen gleich ist.
	 *
	 * @return string Der Satz für die Beschreibung von „values“
	 */
	protected static function valuesHint(): string
	{
		return 'Feldname → Wert in der Form von record_get: Datum als „JJJJ-MM-TT“ bzw. „JJJJ-MM-TT HH:MM“, Dateien als Pfad, Checkboxen als true/false, Mehrfachauswahl als Liste, HTML für Textfelder mit Editor. Nur Felder der Palette des Typs (dca_describe).';
	}
}
