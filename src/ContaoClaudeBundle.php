<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Hauptklasse des Bundles.
 *
 * Sie trägt keine eigene Logik. Symfony leitet aus dem Klassennamen den
 * Namen der DI-Erweiterung ab (ContaoClaudeBundle → ContaoClaudeExtension)
 * und den öffentlichen Ordner bundles/contaoclaude.
 */
class ContaoClaudeBundle extends Bundle
{
}
