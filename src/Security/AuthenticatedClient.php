<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

/**
 * Ergebnis einer erfolgreichen Schlüsselprüfung.
 *
 * Hält fest, für welchen Backend-Benutzer der Schlüssel ausgestellt ist,
 * welche Rechte (Scopes) er trägt und über welchen Weg er ausgestellt wurde.
 */
final class AuthenticatedClient
{
	public const SCOPE_READ = 'contao:read';
	public const SCOPE_WRITE = 'contao:write';

	/**
	 * @param int          $userId     ID des Backend-Benutzers in tl_user
	 * @param list<string> $scopes     Gewährte Scopes, etwa „contao:read“
	 * @param string       $clientName Anzeigename des Zugangs, z. B. „Testschlüssel“ oder „Claude Code“
	 * @param int          $grantId    ID der OAuth-Verbindung in tl_claude_token, 0 beim Testschlüssel
	 */
	public function __construct(
		public readonly int $userId,
		public readonly array $scopes,
		public readonly string $clientName,
		public readonly int $grantId = 0,
	) {
	}

	/**
	 * Gibt an, ob der Zugang über OAuth zustande kam.
	 *
	 * Nur dann gelten die Regeln, wer sich verbinden darf; den Testschlüssel
	 * stellt ein Administrator ausdrücklich für einen bestimmten Benutzer aus.
	 *
	 * @return bool true für Zugriffsschlüssel aus einer OAuth-Verbindung
	 */
	public function isOAuth(): bool
	{
		return $this->grantId > 0;
	}

	/**
	 * Prüft, ob der Schlüssel einen Scope trägt.
	 *
	 * @param string $scope Der gesuchte Scope
	 *
	 * @return bool true, wenn der Scope gewährt ist
	 */
	public function hasScope(string $scope): bool
	{
		return \in_array($scope, $this->scopes, true);
	}
}
