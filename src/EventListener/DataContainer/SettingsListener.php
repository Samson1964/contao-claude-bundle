<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\EventListener\DataContainer;

use Contao\Config;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\Date;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Rückrufe der Einstellungsmaske tl_claude_settings.
 *
 * Die Attribute #[AsCallback] gibt es in Contao 4.13 wie in 5.7 am selben
 * Ort, #[AsEventListener] ab Symfony 5.3; registriert werden beide über
 * autoconfigure in der services.yaml.
 */
class SettingsListener
{
	/**
	 * Der eben erzeugte Testschlüssel, bis er als Meldung abgelegt ist.
	 */
	private ?string $pendingToken = null;

	/**
	 * @param AuthorizationCheckerInterface $authorizationChecker Prüft die Administratorrolle
	 * @param Connection                    $connection           Liest die Backend-Benutzer
	 * @param RequestStack                  $requestStack         Unterscheidet Anzeigen und Speichern
	 * @param UrlGeneratorInterface         $urlGenerator         Erzeugt die Adresse des Endpunkts
	 */
	public function __construct(
		private readonly AuthorizationCheckerInterface $authorizationChecker,
		private readonly Connection $connection,
		private readonly RequestStack $requestStack,
		private readonly UrlGeneratorInterface $urlGenerator,
	) {
	}

	/**
	 * Lässt nur Administratoren in die Maske und zeigt den Stand an.
	 *
	 * Wer die Maske bedienen kann, kann einen Testschlüssel mit den Rechten
	 * jedes Backend-Benutzers ausstellen – auch eines Administrators. Darum
	 * genügen Modulrechte hier nicht. ROLE_ADMIN vergibt Contao in 4.13 und
	 * 5.7 gleichermaßen an Benutzer mit dem Haken „Administrator“.
	 *
	 * Die Hinweise auf Endpunkt und Schlüssel erscheinen nur beim Anzeigen;
	 * beim Speichern lädt DC_File die Seite neu, und sie stünden sonst doppelt
	 * da.
	 *
	 * @throws AccessDeniedException Wenn kein Administrator angemeldet ist
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'config.onload')]
	public function checkAccess(): void
	{
		System::loadLanguageFile('tl_claude_settings');

		if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
			throw new AccessDeniedException($GLOBALS['TL_LANG']['tl_claude_settings']['adminOnly']);
		}

		$request = $this->requestStack->getCurrentRequest();

		if (null === $request || !$request->isMethod('GET')) {
			return;
		}

		Message::addInfo(sprintf(
			$GLOBALS['TL_LANG']['tl_claude_settings']['endpoint'],
			$this->urlGenerator->generate('claude_mcp', array(), UrlGeneratorInterface::ABSOLUTE_URL)
		));

		$created = (int) Config::get(Settings::KEY_TEST_TOKEN_CREATED);

		if ('' !== (string) Config::get(Settings::KEY_TEST_TOKEN_HASH) && $created > 0) {
			Message::addInfo(sprintf($GLOBALS['TL_LANG']['tl_claude_settings']['testTokenStatus'], Date::parse(Config::get('datimFormat'), $created)));
		} else {
			Message::addInfo($GLOBALS['TL_LANG']['tl_claude_settings']['testTokenMissing']);
		}
	}

	/**
	 * Packt die gespeicherten Hausregeln für das Textfeld aus.
	 *
	 * @param mixed $value Der Wert aus der localconfig.php
	 *
	 * @return string Der Text mit Zeilenumbrüchen
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'fields.claudeInstructions.load')]
	public function loadInstructions($value): string
	{
		return Settings::unpackText($value);
	}

	/**
	 * Verpackt die Hausregeln, damit die Zeilenumbrüche erhalten bleiben.
	 *
	 * @param mixed $value Der Text aus dem Formular
	 *
	 * @return string Der Text als JSON-Zeichenkette
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'fields.claudeInstructions.save')]
	public function saveInstructions($value): string
	{
		return Settings::packText($value);
	}

	/**
	 * Bringt die Liste gesperrter Tabellen in eine einheitliche Form.
	 *
	 * Ungültige Einträge fallen heraus; was übrig bleibt, wird durch Komma
	 * getrennt gespeichert. So sieht der Administrator nach dem Speichern,
	 * welche Einträge wirklich greifen.
	 *
	 * @param mixed $value Die Eingabe aus dem Formular
	 *
	 * @return string Die bereinigte Liste
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'fields.claudeBlockedTables.save')]
	public function saveBlockedTables($value): string
	{
		return implode(', ', Settings::parseTableList((string) $value));
	}

	/**
	 * Liefert die Backend-Benutzer für die Auswahl des Testbenutzers.
	 *
	 * Deaktivierte Benutzer und Administratoren bleiben wählbar, sind aber
	 * gekennzeichnet: Ein Administrator als Testbenutzer gibt Claude Zugriff
	 * auf alles, was nicht auf der Sperrliste steht.
	 *
	 * @return array<int, string> Benutzer-ID => „Name (Benutzername)“
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'fields.claudeTestUser.options')]
	public function getUserOptions(): array
	{
		$options = array();
		$rows = $this->connection->fetchAllAssociative('SELECT id, name, username, admin, disable FROM tl_user ORDER BY name, username');

		foreach ($rows as $row) {
			$notes = array();

			if ($row['admin']) {
				$notes[] = $GLOBALS['TL_LANG']['tl_claude_settings']['userAdmin'];
			}

			if ($row['disable']) {
				$notes[] = $GLOBALS['TL_LANG']['tl_claude_settings']['userDisabled'];
			}

			$label = sprintf('%s (%s)', $row['name'], $row['username']);

			if (array() !== $notes) {
				$label .= ' – '.implode(', ', $notes);
			}

			$options[(int) $row['id']] = $label;
		}

		return $options;
	}

	/**
	 * Erzeugt einen neuen Testschlüssel, wenn der Haken gesetzt wurde.
	 *
	 * Gespeichert werden nur Hash und Zeitpunkt, und zwar direkt über
	 * Config::persist(); das Feld selbst bleibt leer. Der Schlüssel selbst
	 * wird nur vorgemerkt: Eine hier gesetzte Meldung ginge verloren, weil
	 * DC_File die Meldungen noch in derselben Anfrage ausgibt und verwirft
	 * (4.13 und 5.7), bei „Speichern und schließen“ sogar mit
	 * Message::reset(). Abgelegt wird sie deshalb erst in addTokenMessage().
	 *
	 * @param mixed $value Der Wert des Schalters, „1“ wenn angehakt
	 *
	 * @return string Immer leer, damit der Schalter nicht gesetzt bleibt
	 */
	#[AsCallback(table: 'tl_claude_settings', target: 'fields.claudeTestTokenNew.save')]
	public function createTestToken($value): string
	{
		if (!$value) {
			return '';
		}

		$token = Settings::generateToken();

		Config::persist(Settings::KEY_TEST_TOKEN_HASH, Settings::hashToken($token));
		Config::persist(Settings::KEY_TEST_TOKEN_CREATED, time());

		$this->pendingToken = $token;

		return '';
	}

	/**
	 * Legt den vorgemerkten Testschlüssel als Meldung ab.
	 *
	 * Läuft auf kernel.response derselben Anfrage, also nach dem Speichern
	 * und Zurücksetzen der Meldungen durch DC_File, aber vor dem Schreiben der
	 * Sitzung (Symfonys SessionListener hat Priorität -1000). Die Meldung
	 * erscheint damit auf der Seite, zu der Contao weiterleitet.
	 *
	 * @param ResponseEvent $event Die Antwort auf die Speicher-Anfrage
	 */
	#[AsEventListener(event: KernelEvents::RESPONSE)]
	public function addTokenMessage(ResponseEvent $event): void
	{
		if (null === $this->pendingToken || !$event->isMainRequest()) {
			return;
		}

		System::loadLanguageFile('tl_claude_settings');
		Message::addInfo(sprintf($GLOBALS['TL_LANG']['tl_claude_settings']['testTokenCreated'], $this->pendingToken));

		$this->pendingToken = null;
	}
}
