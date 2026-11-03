<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\EventListener\DataContainer;

use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\Input;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Callbacks für das Backend-Modul „Claude-Protokoll“ (tl_claude_log).
 */
class LogListener
{
	/**
	 * @param TokenStorageInterface $tokenStorage Liefert den angemeldeten Backend-Benutzer
	 * @param Connection            $connection   Prüft den Eintrag der Detailansicht
	 */
	public function __construct(
		private readonly TokenStorageInterface $tokenStorage,
		private readonly Connection $connection,
	) {
	}

	/**
	 * Zeigt Benutzern ohne Administratorrecht nur ihre eigenen Einträge.
	 *
	 * Wer das Modul über seine Gruppe bekommt, soll nachsehen können, was
	 * Claude in seinem Namen getan hat, aber nicht, was andere tun. Die Liste
	 * wird über die Filterbedingung der Tabelle eingeschränkt; die
	 * Detailansicht (act=show) liest den Datensatz unabhängig davon und wird
	 * deshalb eigens geprüft.
	 *
	 * @throws AccessDeniedException Beim Öffnen eines fremden Eintrags
	 */
	#[AsCallback(table: 'tl_claude_log', target: 'config.onload')]
	public function restrictToOwnEntries(): void
	{
		$user = $this->tokenStorage->getToken()?->getUser();

		if (!$user instanceof BackendUser || $user->isAdmin) {
			return;
		}

		$GLOBALS['TL_DCA']['tl_claude_log']['list']['sorting']['filter'][] = array('userId=?', (int) $user->id);

		if ('show' === Input::get('act')) {
			$owner = $this->connection->fetchOne('SELECT userId FROM tl_claude_log WHERE id = ?', array((int) Input::get('id')));

			if ((int) $owner !== (int) $user->id) {
				throw new AccessDeniedException('Dieser Protokolleintrag gehört einem anderen Benutzer.');
			}
		}
	}
}
