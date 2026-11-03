<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoClaudeBundle\Button\ClaudeButton;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Zeigt den Claude-Button im Frontend, solange die Vorschau läuft.
 *
 * Nur für angemeldete Backend-Benutzer im Vorschaumodus (dort, wo auch
 * Contaos Vorschauleiste erscheint), und nur, wenn sie Claude verbinden
 * dürften. Der Button schwebt unten rechts; als Kontext bekommt Claude die
 * angezeigte Seite mit ID und Adresse. Besucher und der Seitencache sehen
 * nichts davon: Antworten in der Vorschau sind ohnehin privat.
 */
#[AsEventListener(KernelEvents::RESPONSE, priority: -10)]
class FrontendButtonListener
{
	/**
	 * @param ScopeMatcher $scopeMatcher Erkennt Frontend-Anfragen
	 * @param TokenChecker $tokenChecker Backend-Benutzer und Vorschaumodus
	 * @param ClaudeButton $button       Sichtbarkeit und Anfrage
	 * @param Connection   $connection   Findet die ID des Backend-Benutzers
	 */
	public function __construct(
		private readonly ScopeMatcher $scopeMatcher,
		private readonly TokenChecker $tokenChecker,
		private readonly ClaudeButton $button,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Fügt den Button vor </body> ein.
	 *
	 * @param ResponseEvent $event Die Antwort
	 */
	public function __invoke(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		$response = $event->getResponse();

		if (!$this->scopeMatcher->isFrontendMainRequest($event) || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
			return;
		}

		// Vorschau heißt: Aufruf über preview.php (Merkmal _preview) mit
		// angemeldetem Backend-Benutzer. TokenChecker::isPreviewMode() wäre
		// nur wahr, wenn zusätzlich „Unveröffentlichte anzeigen“ an ist.
		if (!$request->attributes->get('_preview', false) || !$this->tokenChecker->hasBackendUser()) {
			return;
		}

		$content = (string) $response->getContent();
		$position = strripos($content, '</body>');

		if (false === $position) {
			return;
		}

		$userId = (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', array((string) $this->tokenChecker->getBackendUsername()));

		if (!$this->button->isVisibleFor($userId)) {
			return;
		}

		$lines = array();
		$page = $request->attributes->get('pageModel');

		if ($page instanceof PageModel) {
			$lines[] = sprintf('Frontend-Seite: tl_page %d „%s“', $page->id, $page->title);
		}

		// Öffentliche Adresse der Seite statt der Vorschau-Adresse
		$url = str_replace($request->getSchemeAndHttpHost().'/preview.php', $request->getSchemeAndHttpHost(), ClaudeButton::cleanUrl($request));
		$context = $this->button->buildContext($request->getHost(), $lines, $url);
		$base = $request->getBasePath();
		$html = sprintf(
			'<link rel="stylesheet" href="%s"><script src="%s" defer></script><a href="#claude" class="claude-button claude-button--floating" title="%s" data-claude-button data-claude-context="%s" data-claude-labels="%s">Claude</a>',
			htmlspecialchars($base.'/'.ClaudeButton::STYLESHEET),
			htmlspecialchars($base.'/'.ClaudeButton::SCRIPT),
			htmlspecialchars(ClaudeButton::labels()['title']),
			htmlspecialchars($context),
			htmlspecialchars((string) json_encode(ClaudeButton::labels(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		);

		$response->setContent(substr($content, 0, $position).$html.substr($content, $position));
	}
}
