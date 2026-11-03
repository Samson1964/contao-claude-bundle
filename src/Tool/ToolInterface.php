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

/**
 * Ein Werkzeug, das Claude über den MCP-Server aufrufen kann.
 *
 * Jede Klasse, die diese Schnittstelle umsetzt, wird automatisch mit dem Tag
 * „contao_claude.tool“ versehen und von Mcp\ServerFactory beim SDK
 * angemeldet. Aufgerufen wird __invoke(); das SDK ordnet die Argumente aus
 * tools/call den Parametern nach ihrem Namen zu. Die Parameternamen müssen
 * deshalb genau den Schlüsseln in getInputSchema() entsprechen.
 *
 * Fehler, die Claude sehen soll (fehlende Rechte, unbekannte ID), werden als
 * Mcp\Exception\ToolCallException geworfen und kommen als Werkzeugergebnis
 * mit isError: true an, nicht als Protokollfehler.
 */
interface ToolInterface
{
	/**
	 * @return string Der Werkzeugname in snake_case, etwa „page_tree“
	 */
	public function getName(): string;

	/**
	 * @return string Kurzer Anzeigename für die Oberfläche von Claude
	 */
	public function getTitle(): string;

	/**
	 * @return string Beschreibung für Claude: was das Werkzeug liefert und wann es passt
	 */
	public function getDescription(): string;

	/**
	 * @return array<string, mixed> JSON-Schema der Argumente (type: object)
	 */
	public function getInputSchema(): array;

	/**
	 * @return ToolAnnotations Hinweise für Claude: lesend, schreibend, löschend
	 */
	public function getAnnotations(): ToolAnnotations;

	/**
	 * @return string Der Scope, den der Schlüssel tragen muss, damit das
	 *                Werkzeug in tools/list erscheint
	 */
	public function getRequiredScope(): string;

	/**
	 * Gibt an, ob das Werkzeug in dieser Installation gerade angeboten wird.
	 *
	 * Schreibende Werkzeuge hängen an den Schaltern in den Einstellungen;
	 * ein abgeschaltetes Werkzeug erscheint nicht in tools/list.
	 *
	 * @return bool true, wenn das Werkzeug angemeldet werden soll
	 */
	public function isAvailable(): bool;
}
