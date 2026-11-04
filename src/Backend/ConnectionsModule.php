<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Backend;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\Controller;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Schachbulle\ContaoClaudeBundle\OAuth\GrantRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Backend-Modul System → Claude-Verbindungen.
 *
 * Zeigt die OAuth-Verbindungen und widerruft sie. Jeder Benutzer mit
 * Zugriff auf das Modul sieht seine eigenen, Administratoren alle. Ein
 * klassisches Modul statt DC_Table, weil die Tabelle nur gelesen und
 * widerrufen wird; so greifen weder DC_Table-Rechte noch tl_undo
 * (aus dem eine gelöschte Verbindung samt Schlüssel-Hash wieder
 * auferstehen könnte).
 *
 * Contao erzeugt das Modul mit „new“; die Dienste kommen deshalb aus dem
 * Container. Der Widerruf ist ein POST-Formular, dessen Request-Token
 * Contao für Backend-Routen selbst prüft.
 */
class ConnectionsModule extends BackendModule
{
	protected $strTemplate = 'be_claude_connections';

	/**
	 * Verarbeitet einen Widerruf und füllt das Template.
	 *
	 * Ein Benutzer ohne Administratorrechte kann nur eigene Verbindungen
	 * widerrufen; die ID aus dem Formular wird dagegen geprüft.
	 */
	protected function compile(): void
	{
		System::loadLanguageFile('default');

		$user = BackendUser::getInstance();
		$repository = System::getContainer()->get(GrantRepository::class);
		$ownerFilter = $user->isAdmin ? null : (int) $user->id;

		if ('claude_connections' === Input::post('FORM_SUBMIT')) {
			$grant = $repository->find((int) Input::post('grant'));

			if (null !== $grant && (null === $ownerFilter || (int) $grant['userId'] === $ownerFilter)) {
				$repository->revoke((int) $grant['id']);
				Message::addConfirmation($GLOBALS['TL_LANG']['MSC']['claude_connections']['revoked']);
			}

			Controller::reload();
		}

		$now = time();
		$rows = array();

		foreach ($repository->listForBackend($ownerFilter) as $grant) {
			$status = 'active';

			if ('1' === (string) $grant['revoked']) {
				$status = 'revoked';
			} elseif ((int) $grant['refreshExpires'] < $now) {
				$status = 'expired';
			}

			$rows[] = array(
				'id' => (int) $grant['id'],
				'user' => sprintf('%s (%s)', (string) ($grant['userName'] ?? '–'), (string) ($grant['userUsername'] ?? '–')),
				'client' => (string) $grant['clientName'],
				'scope' => (string) $grant['scope'],
				'created' => (int) $grant['created'],
				'lastUsed' => (int) $grant['lastUsed'],
				'expires' => (int) $grant['refreshExpires'],
				'status' => $status,
			);
		}

		$this->Template->rows = $rows;
		$this->Template->showUser = null === $ownerFilter;
		$this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();
		$this->Template->messages = Message::generate();

		// Adresse für den Konnektor in claude.ai, für den Hilfeteil über der Liste
		$this->Template->mcpUrl = System::getContainer()->get('router')->generate('claude_mcp', array(), UrlGeneratorInterface::ABSOLUTE_URL);
	}
}
