<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;

/**
 * Die Scopes, die der Server über OAuth vergibt.
 *
 * Ausbaustufe 2 vergibt nur „contao:read“; „contao:write“ kommt mit den
 * schreibenden Werkzeugen (Stufe 3) hinzu. Unbekannte Scopes in einer
 * Anfrage – etwa „offline_access“, das manche Clients von sich aus
 * anhängen – werden still übergangen statt abgewiesen.
 */
final class Scopes
{
	/**
	 * Scopes, die jetzt vergeben werden können.
	 */
	public const GRANTABLE = array(AuthenticatedClient::SCOPE_READ);

	/**
	 * Deutsche Beschreibung je Scope für die Bestätigungsseite.
	 */
	public const DESCRIPTIONS = array(
		AuthenticatedClient::SCOPE_READ => 'Inhalte lesen: Seiten, Artikel, Nachrichten, Dateien und Datensätze, soweit dein Backend-Zugang sie sehen darf',
		AuthenticatedClient::SCOPE_WRITE => 'Inhalte anlegen und ändern, soweit dein Backend-Zugang das darf',
	);

	/**
	 * Ermittelt die zu gewährenden Scopes aus dem Parameter „scope“.
	 *
	 * @param string|null $requested Leerzeichengetrennte Liste aus der Anfrage
	 *
	 * @return list<string> Die gewährten Scopes; ohne gültige Angabe „contao:read“
	 */
	public static function grant(?string $requested): array
	{
		$granted = array_values(array_intersect(self::GRANTABLE, preg_split('/\s+/', trim((string) $requested)) ?: array()));

		return array() === $granted ? array(AuthenticatedClient::SCOPE_READ) : $granted;
	}

	/**
	 * Zerlegt eine gespeicherte Scope-Liste.
	 *
	 * @param string $scope Leerzeichengetrennte Liste
	 *
	 * @return list<string> Die einzelnen Scopes
	 */
	public static function split(string $scope): array
	{
		return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: array()));
	}
}
