<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Settings;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;

/**
 * Liest die Einstellungen des MCP-Servers aus der localconfig.php.
 *
 * Gepflegt werden sie im Backend unter System → MCP-Server (DCA
 * tl_claude_settings, ein DC_File wie tl_settings). Alle Schlüssel beginnen
 * mit „claude“, damit sie in der localconfig.php nicht mit denen anderer
 * Erweiterungen kollidieren.
 */
class Settings
{
	public const KEY_ENABLED = 'claudeMcpEnabled';
	public const KEY_INSTRUCTIONS = 'claudeInstructions';
	public const KEY_BLOCKED_TABLES = 'claudeBlockedTables';
	public const KEY_TEST_USER = 'claudeTestUser';
	public const KEY_TEST_TOKEN_HASH = 'claudeTestTokenHash';
	public const KEY_TEST_TOKEN_CREATED = 'claudeTestTokenCreated';
	public const KEY_ALLOW_ADMINS = 'claudeAllowAdmins';
	public const KEY_ALLOWED_GROUPS = 'claudeAllowedGroups';
	public const KEY_WRITE_ENABLED = 'claudeWriteEnabled';
	public const KEY_PUBLISH_ENABLED = 'claudePublishEnabled';
	public const KEY_DELETE_ENABLED = 'claudeDeleteEnabled';

	/**
	 * @param ContaoFramework $framework Liefert den Adapter auf Contao\Config;
	 *                                   das Framework wird bei Bedarf gestartet
	 */
	public function __construct(private readonly ContaoFramework $framework)
	{
	}

	/**
	 * Gibt an, ob der Notschalter den Server freigibt.
	 *
	 * Ohne gespeicherte Einstellung ist der Server aus: Eine frische
	 * Installation des Bundles öffnet keine Schnittstelle, bevor jemand sie
	 * im Backend bewusst einschaltet.
	 *
	 * @return bool true, wenn /mcp Anfragen beantworten darf
	 */
	public function isEnabled(): bool
	{
		return (bool) $this->get(self::KEY_ENABLED);
	}

	/**
	 * Liefert die Hausregeln der Installation für Claude.
	 *
	 * @return string Der Text mit Zeilenumbrüchen, oder eine leere
	 *                Zeichenkette, wenn keine Hausregeln gepflegt sind
	 */
	public function getInstructions(): string
	{
		return trim(self::unpackText($this->get(self::KEY_INSTRUCTIONS)));
	}

	/**
	 * Liefert die im Backend zusätzlich gesperrten Tabellen.
	 *
	 * @return list<string> Tabellennamen in Kleinschreibung; ein Eintrag mit
	 *                      „*“ am Ende sperrt alle Tabellen mit diesem Anfang
	 */
	public function getExtraBlockedTables(): array
	{
		return self::parseTableList((string) $this->get(self::KEY_BLOCKED_TABLES));
	}

	/**
	 * Liefert die ID des Backend-Benutzers, für den der Testschlüssel gilt.
	 *
	 * @return int Die ID aus tl_user, oder 0, wenn keiner gewählt ist
	 */
	public function getTestUserId(): int
	{
		return (int) $this->get(self::KEY_TEST_USER);
	}

	/**
	 * Liefert den SHA-256-Hash des Testschlüssels.
	 *
	 * Der Schlüssel selbst wird nirgends gespeichert; er erscheint nur
	 * einmal im Backend, direkt nach dem Erzeugen.
	 *
	 * @return string|null Der Hash in Hexadezimalschreibweise, oder null,
	 *                     wenn noch kein Testschlüssel erzeugt wurde
	 */
	public function getTestTokenHash(): ?string
	{
		$hash = (string) $this->get(self::KEY_TEST_TOKEN_HASH);

		return '' === $hash ? null : $hash;
	}

	/**
	 * Liefert den Zeitpunkt, zu dem der Testschlüssel erzeugt wurde.
	 *
	 * @return int Unix-Zeitstempel, oder 0, wenn es keinen Schlüssel gibt
	 */
	public function getTestTokenCreated(): int
	{
		return (int) $this->get(self::KEY_TEST_TOKEN_CREATED);
	}

	/**
	 * Gibt an, ob sich Administratoren über OAuth verbinden dürfen.
	 *
	 * Ohne Einstellung nicht: Ein Administrator-Zugang gäbe Claude Zugriff
	 * auf alles außerhalb der Sperrliste. Der Testschlüssel ist davon nicht
	 * betroffen, ihn stellt ein Administrator ausdrücklich aus.
	 *
	 * @return bool true, wenn der Schalter in den Einstellungen gesetzt ist
	 */
	public function allowsAdmins(): bool
	{
		return (bool) $this->get(self::KEY_ALLOW_ADMINS);
	}

	/**
	 * Liefert die Benutzergruppen, deren Mitglieder Claude verbinden dürfen.
	 *
	 * @return list<int> IDs aus tl_user_group; leer heißt „alle Gruppen“
	 */
	public function getAllowedGroups(): array
	{
		return array_values(array_filter(array_map('intval', StringUtil::deserialize($this->get(self::KEY_ALLOWED_GROUPS), true))));
	}

	/**
	 * Gibt an, ob Claude Inhalte anlegen und ändern darf.
	 *
	 * Ohne Einstellung nicht: Eine Installation bleibt lesend, bis jemand das
	 * Schreiben im Backend bewusst freigibt. Ist der Schalter aus, erscheinen
	 * die schreibenden Werkzeuge auch für Verbindungen nicht, die das Recht
	 * „contao:write“ früher erhalten haben.
	 *
	 * @return bool true, wenn die schreibenden Werkzeuge freigegeben sind
	 */
	public function isWriteEnabled(): bool
	{
		return (bool) $this->get(self::KEY_WRITE_ENABLED);
	}

	/**
	 * Gibt an, ob Claude veröffentlichen und verstecken darf.
	 *
	 * Gilt nur zusammen mit isWriteEnabled(). Ohne diesen Schalter legt Claude
	 * alles unveröffentlicht an und darf Felder wie „published“ oder
	 * „invisible“ nicht ändern.
	 *
	 * @return bool true, wenn publish_toggle freigegeben ist
	 */
	public function isPublishEnabled(): bool
	{
		return $this->isWriteEnabled() && (bool) $this->get(self::KEY_PUBLISH_ENABLED);
	}

	/**
	 * Gibt an, ob Claude Datensätze löschen darf.
	 *
	 * Gilt nur zusammen mit isWriteEnabled(). Gelöscht wird wie im Backend
	 * über tl_undo, also wiederherstellbar.
	 *
	 * @return bool true, wenn record_delete freigegeben ist
	 */
	public function isDeleteEnabled(): bool
	{
		return $this->isWriteEnabled() && (bool) $this->get(self::KEY_DELETE_ENABLED);
	}

	/**
	 * Erzeugt einen neuen zufälligen Zugriffsschlüssel.
	 *
	 * 32 Byte aus random_bytes(), base64url-kodiert ohne Auffüllzeichen –
	 * so lässt sich der Schlüssel unverändert in einen HTTP-Header schreiben.
	 *
	 * @return string Der Schlüssel, 43 Zeichen lang
	 */
	public static function generateToken(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	/**
	 * Bildet den Hash, unter dem ein Schlüssel gespeichert wird.
	 *
	 * Ein schneller Hash genügt hier: Die Schlüssel sind zufällig und lang,
	 * ein Wörterbuchangriff wie bei Kennwörtern läuft ins Leere.
	 *
	 * @param string $token Der Schlüssel im Klartext
	 *
	 * @return string Der SHA-256-Hash in Hexadezimalschreibweise
	 */
	public static function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}

	/**
	 * Verpackt einen mehrzeiligen Text für die localconfig.php.
	 *
	 * Config::persist() fasst Zeilenumbrüche zu Leerzeichen zusammen; als
	 * JSON-Zeichenkette überstehen sie das Speichern.
	 *
	 * @param mixed $value Der Text aus dem Formular
	 *
	 * @return string Der Text als JSON-Zeichenkette, oder leer bei leerem Text
	 */
	public static function packText($value): string
	{
		$text = str_replace("\r\n", "\n", (string) $value);

		if ('' === trim($text)) {
			return '';
		}

		return (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Packt einen mit packText() gespeicherten Text wieder aus.
	 *
	 * Contao 4.13 maskiert Text- und Textarea-Werte in DC_File schon vor dem
	 * load_callback mit htmlspecialchars(); aus dem Anführungszeichen am
	 * Anfang wird dann „&quot;“. Diese Maskierung wird hier zuerst
	 * zurückgenommen. Werte, die keine JSON-Zeichenkette sind (etwa von Hand
	 * in die localconfig.php geschrieben), kommen unverändert zurück.
	 *
	 * @param mixed $value Der gespeicherte Wert
	 *
	 * @return string Der Text mit Zeilenumbrüchen
	 */
	public static function unpackText($value): string
	{
		$value = (string) $value;

		if (str_starts_with($value, '&quot;')) {
			$value = htmlspecialchars_decode($value, ENT_QUOTES);
		}

		if ('' === $value || '"' !== $value[0]) {
			return $value;
		}

		$text = json_decode($value, true);

		return \is_string($text) ? $text : $value;
	}

	/**
	 * Zerlegt eine Liste von Tabellennamen.
	 *
	 * Erlaubt sind Komma, Semikolon und Leerraum als Trenner. Einträge, die
	 * kein gültiger Tabellenname sind, fallen still heraus.
	 *
	 * @param string $list Die Eingabe, etwa „tl_foo, tl_bar_*“
	 *
	 * @return list<string> Die Namen in Kleinschreibung, ohne Doppelte
	 */
	public static function parseTableList(string $list): array
	{
		$tables = array();

		foreach (preg_split('/[\s,;]+/', strtolower($list)) ?: array() as $entry) {
			if (preg_match('/^[a-z0-9_]+\*?$/', $entry)) {
				$tables[$entry] = $entry;
			}
		}

		return array_values($tables);
	}

	/**
	 * Liest einen Wert aus der localconfig.php.
	 *
	 * @param string $key Der Schlüssel in $GLOBALS['TL_CONFIG']
	 *
	 * @return mixed Der Wert, oder null, wenn er nicht gesetzt ist
	 */
	private function get(string $key)
	{
		$this->framework->initialize();

		return $this->framework->getAdapter(Config::class)->get($key);
	}
}
