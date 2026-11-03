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
 * „contao:read“ gibt es immer, „contao:write“ nur, wenn in den
 * Einstellungen die schreibenden Werkzeuge freigegeben sind. Lesen gehört
 * immer dazu: Schreiben ohne Lesen wäre blind. Unbekannte Scopes in einer
 * Anfrage – etwa „offline_access“, das manche Clients von sich aus
 * anhängen – werden still übergangen statt abgewiesen.
 */
final class Scopes
{
	/**
	 * Deutsche Beschreibung je Scope für die Bestätigungsseite.
	 */
	public const DESCRIPTIONS = array(
		AuthenticatedClient::SCOPE_READ => 'Inhalte lesen: Seiten, Artikel, Nachrichten, Dateien und Datensätze, soweit dein Backend-Zugang sie sehen darf',
		AuthenticatedClient::SCOPE_WRITE => 'Inhalte anlegen und ändern, soweit dein Backend-Zugang das darf; Neues entsteht immer unveröffentlicht',
	);

	/**
	 * Liefert die Scopes, die gerade vergeben werden können.
	 *
	 * @param bool $writeEnabled Ob die schreibenden Werkzeuge freigegeben sind
	 *
	 * @return list<string> „contao:read“, dazu „contao:write“ bei freigegebenem Schreiben
	 */
	public static function grantable(bool $writeEnabled): array
	{
		return $writeEnabled
			? array(AuthenticatedClient::SCOPE_READ, AuthenticatedClient::SCOPE_WRITE)
			: array(AuthenticatedClient::SCOPE_READ);
	}

	/**
	 * Ermittelt die zu gewährenden Scopes aus dem Parameter „scope“.
	 *
	 * Ohne Angabe gibt es alles, was gerade vergeben werden kann; so bekommt
	 * auch ein Client, der den Parameter weglässt, das Schreibrecht, wenn es
	 * freigegeben ist. Die Bestätigungsseite zeigt dem Benutzer, was gewährt
	 * wird.
	 *
	 * @param string|null $requested    Leerzeichengetrennte Liste aus der Anfrage
	 * @param bool        $writeEnabled Ob die schreibenden Werkzeuge freigegeben sind
	 *
	 * @return list<string> Die gewährten Scopes, immer mit „contao:read“ zuerst
	 */
	public static function grant(?string $requested, bool $writeEnabled = false): array
	{
		$grantable = self::grantable($writeEnabled);
		$requested = self::split((string) $requested);

		if (array() === $requested) {
			return $grantable;
		}

		return array_values(array_unique(array_merge(array(AuthenticatedClient::SCOPE_READ), array_intersect($grantable, $requested))));
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
