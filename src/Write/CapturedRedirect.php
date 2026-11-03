<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

/**
 * Eine Weiterleitung, die WriteDataContainer statt einer echten auslöst.
 *
 * DC_Table beendet create() und andere Aktionen mit einer Weiterleitung, im
 * Backend die Antwort auf das Formular. Beim Schreiben über Claude gibt es
 * keine Seite, zu der weitergeleitet werden könnte; die Adresse wird nur
 * gebraucht, um etwa die ID eines neuen Datensatzes abzulesen.
 */
final class CapturedRedirect extends \RuntimeException
{
	/**
	 * @param string $location Ziel der Weiterleitung, leer bei reload()
	 */
	public function __construct(public readonly string $location)
	{
		parent::__construct('Contao wollte weiterleiten nach: '.('' === $location ? '(neu laden)' : $location));
	}
}
