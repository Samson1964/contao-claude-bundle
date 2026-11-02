<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Mcp;

use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;

/**
 * Hält den angemeldeten Zugang für die Dauer einer Anfrage an /mcp.
 *
 * Der Controller setzt ihn nach der Schlüsselprüfung; Werkzeuge und die
 * Server-Fabrik lesen ihn. Den Backend-Benutzer selbst liefert
 * Security\Permissions aus dem Token-Speicher.
 */
class CallContext
{
	private ?AuthenticatedClient $client = null;

	/**
	 * Setzt den Zugang der laufenden Anfrage.
	 *
	 * @param AuthenticatedClient|null $client Der geprüfte Zugang, oder null zum Zurücksetzen
	 */
	public function setClient(?AuthenticatedClient $client): void
	{
		$this->client = $client;
	}

	/**
	 * Liefert den Zugang der laufenden Anfrage.
	 *
	 * @return AuthenticatedClient Der geprüfte Zugang
	 *
	 * @throws ToolCallException Wenn außerhalb einer angemeldeten Anfrage aufgerufen
	 */
	public function getClient(): AuthenticatedClient
	{
		if (null === $this->client) {
			throw new ToolCallException('Kein angemeldeter Zugang.');
		}

		return $this->client;
	}

	/**
	 * Gibt an, ob ein Zugang gesetzt ist.
	 *
	 * @return bool true innerhalb einer angemeldeten Anfrage
	 */
	public function hasClient(): bool
	{
		return null !== $this->client;
	}
}
