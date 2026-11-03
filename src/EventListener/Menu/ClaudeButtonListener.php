<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\EventListener\Menu;

use Contao\BackendUser;
use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\Util\MenuManipulator;
use Schachbulle\ContaoClaudeBundle\Button\ClaudeButton;
use Schachbulle\ContaoClaudeBundle\Button\ContextDescriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Fügt den Claude-Button in die Kopfzeile des Backends ein.
 *
 * Contao baut die Kopfzeile in 4.13 wie in 5.7 als KnpMenu „headerMenu“ und
 * meldet das über das Ereignis contao.backend_menu_build; so fügt Contao
 * auch seinen eigenen Knopf „Vorschau“ ein. Der Button steht direkt vor
 * „Vorschau“. Skript und Stil kommen über TL_JAVASCRIPT und TL_CSS, die
 * be_main erst nach dem Aufbau der Kopfzeile ausgibt.
 */
#[AsEventListener('contao.backend_menu_build', priority: -20)]
class ClaudeButtonListener
{
	/**
	 * @param ClaudeButton          $button       Sichtbarkeit und Anfrage
	 * @param ContextDescriber      $describer    Beschreibt die aktuelle Seite
	 * @param RequestStack          $requestStack Die Backend-Anfrage
	 * @param TokenStorageInterface $tokenStorage Der angemeldete Benutzer
	 */
	public function __construct(
		private readonly ClaudeButton $button,
		private readonly ContextDescriber $describer,
		private readonly RequestStack $requestStack,
		private readonly TokenStorageInterface $tokenStorage,
	) {
	}

	/**
	 * Fügt den Button ein, wenn der Benutzer Claude verbinden darf.
	 *
	 * @param MenuEvent $event Das Menüereignis; nur „headerMenu“ wird erweitert
	 */
	public function __invoke(MenuEvent $event): void
	{
		$tree = $event->getTree();
		$request = $this->requestStack->getCurrentRequest();
		$user = $this->tokenStorage->getToken()?->getUser();

		if ('headerMenu' !== $tree->getName() || null === $request || !$user instanceof BackendUser || !$this->button->isVisibleFor((int) $user->id)) {
			return;
		}

		$context = $this->button->buildContext($request->getHost(), $this->describer->describeBackend($request), ClaudeButton::cleanUrl($request));

		$item = $event->getFactory()
			->createItem('claude')
			->setLabel('Claude')
			->setUri('#claude')
			->setLinkAttribute('class', 'claude-button')
			->setLinkAttribute('title', ClaudeButton::labels()['title'])
			->setLinkAttribute('data-claude-button', '')
			->setLinkAttribute('data-claude-context', $context)
			->setLinkAttribute('data-claude-labels', json_encode(ClaudeButton::labels(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
			->setExtra('translation_domain', false)
		;

		$tree->addChild($item);

		// Vor „Vorschau“, sonst vor das Benutzermenü. In 5.7 fügt Contao
		// „Vorschau“ erst nach diesem Listener ein und stellt sich dabei selbst
		// vor Benutzermenü und Menü-Knopf, also direkt hinter den Button.
		$names = array_keys($tree->getChildren());
		$position = array_search('preview', $names, true);

		if (false === $position) {
			$position = array_search('submenu', $names, true);
		}

		if (false !== $position) {
			(new MenuManipulator())->moveToPosition($item, (int) $position);
		}

		$GLOBALS['TL_JAVASCRIPT']['claude_button'] = ClaudeButton::SCRIPT;
		$GLOBALS['TL_CSS']['claude_button'] = ClaudeButton::STYLESHEET;
	}
}
