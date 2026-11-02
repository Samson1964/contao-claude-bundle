<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Mcp;

use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Tool\AbstractReadTool;

/**
 * Werkzeug für die Tests: gibt seinen Text zurück oder meldet einen Fehler.
 */
class EchoTool extends AbstractReadTool
{
	/**
	 * @return string „echo“
	 */
	public function getName(): string
	{
		return 'echo';
	}

	/**
	 * @return string Der Anzeigename
	 */
	public function getTitle(): string
	{
		return 'Echo';
	}

	/**
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Gibt den Text zurück; bei „fehler“ kommt ein Werkzeugfehler.';
	}

	/**
	 * @return array<string, mixed> Ein Textargument
	 */
	public function getInputSchema(): array
	{
		return array(
			'type' => 'object',
			'properties' => array('text' => array('type' => 'string')),
			'required' => array('text'),
		);
	}

	/**
	 * Gibt den Text zurück.
	 *
	 * @param string $text Der Text
	 *
	 * @return array{echo: string} Der Text unter dem Schlüssel „echo“
	 *
	 * @throws ToolCallException Wenn der Text „fehler“ lautet
	 */
	public function __invoke(string $text): array
	{
		if ('fehler' === $text) {
			throw new ToolCallException('Absichtlicher Fehler.');
		}

		return array('echo' => $text);
	}
}
