<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Button;

use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gemeinsames für den Claude-Button in Backend und Frontend.
 *
 * Der Button öffnet ein kleines Eingabefeld „Was soll Claude tun?“. Beim
 * Abschicken setzt das Skript den Kontext und die Eingabe zu einer Anfrage
 * zusammen, kopiert sie in die Zwischenablage und öffnet claude.ai/new in
 * einem eigenen Fenster, vorausgefüllt über ?q=. Den Parameter hat
 * Anthropic nicht dokumentiert, deshalb immer auch die Zwischenablage.
 *
 * Die Anfrage beginnt mit dem Namen der Installation, damit Claude bei
 * mehreren verbundenen Contao-Installationen den richtigen Konnektor wählt.
 */
class ClaudeButton
{
	/**
	 * Pfade der Dateien unter public/ der Installation.
	 */
	public const SCRIPT = 'bundles/contaoclaude/claude-button.js';
	public const STYLESHEET = 'bundles/contaoclaude/claude-button.css';

	/**
	 * Adresse, unter der Claude einen neuen Chat öffnet.
	 */
	public const CLAUDE_URL = 'https://claude.ai/new';

	/**
	 * @param Settings         $settings Notschalter
	 * @param ConnectionPolicy $policy   Wer Claude verbinden darf
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ConnectionPolicy $policy,
	) {
	}

	/**
	 * Gibt an, ob ein Backend-Benutzer den Button sieht.
	 *
	 * Genau dann, wenn der Server eingeschaltet ist und der Benutzer sich mit
	 * Claude verbinden dürfte (erlaubte Gruppen, Administratoren nur mit
	 * Schalter). Wer sich nicht verbinden darf, hätte von der Anfrage nichts.
	 *
	 * @param int $userId ID aus tl_user
	 *
	 * @return bool true, wenn der Button erscheinen soll
	 */
	public function isVisibleFor(int $userId): bool
	{
		return $userId > 0 && $this->settings->isEnabled() && null === $this->policy->check($userId);
	}

	/**
	 * Setzt den Kontext zusammen, der der Eingabe vorangestellt wird.
	 *
	 * @param string       $host  Host der Installation
	 * @param list<string> $lines Beschreibung der Stelle, an der der Benutzer ist
	 * @param string       $url   Adresse der aktuellen Seite
	 *
	 * @return string Der Kontext mit Zeilenumbrüchen
	 */
	public function buildContext(string $host, array $lines, string $url): string
	{
		$context = array(sprintf('Nutze den Contao-Konnektor der Installation %s.', $host));

		foreach ($lines as $line) {
			$context[] = $line;
		}

		$context[] = 'Adresse: '.$url;

		return implode("\n", $context);
	}

	/**
	 * Liefert die Adresse der Seite ohne Request-Token und Verweis-Kennung.
	 *
	 * „rt“ ist Contaos Schutz gegen untergeschobene Anfragen und gehört nicht
	 * in eine Nachricht an Dritte; „ref“ ist nur für die Zurück-Navigation
	 * des Backends gedacht.
	 *
	 * @param Request $request Die Anfrage
	 *
	 * @return string Die bereinigte Adresse
	 */
	public static function cleanUrl(Request $request): string
	{
		$query = $request->query->all();
		unset($query['rt'], $query['ref'], $query['_hash']);

		return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().(array() === $query ? '' : '?'.http_build_query($query));
	}

	/**
	 * Liefert die Beschriftungen für das Skript.
	 *
	 * @return array<string, string> Schlüssel => Text
	 */
	public static function labels(): array
	{
		return array(
			'title' => 'Was soll Claude tun?',
			'context' => 'Kontext',
			'placeholder' => 'z. B. „Schreib einen Teaser für diese Nachricht“',
			'send' => 'An Claude senden',
			'cancel' => 'Abbrechen',
			'copied' => 'Die Anfrage ist in der Zwischenablage. Falls claude.ai sie nicht übernimmt: dort einfügen.',
			'notCopied' => 'Kopieren hat nicht geklappt. Bitte die Anfrage hier markieren und kopieren:',
			'url' => self::CLAUDE_URL,
		);
	}
}
