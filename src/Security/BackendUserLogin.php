<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Meldet den Backend-Benutzer eines Schlüssels für die Dauer einer Anfrage an.
 *
 * Die Route /mcp liegt in keiner Contao-Firewall (kein _scope), der
 * Token-Speicher ist also leer. Dieser Dienst legt dort ein Token für den
 * Benutzer ab. Damit greifen Contaos Voter (isGranted('contao_user.…')) und
 * BackendUser::getInstance() so, als wäre der Benutzer im Backend
 * angemeldet – ohne Sitzung und ohne Cookie.
 */
class BackendUserLogin
{
	/**
	 * Name der Backend-Firewall; Contao prüft ihn an manchen Stellen, um ein
	 * Backend-Token zu erkennen.
	 */
	private const FIREWALL = 'contao_backend';

	/**
	 * @param ContaoFramework       $framework    Für den Adapter auf BackendUser
	 * @param Connection            $connection   Liest den Benutzernamen zur ID
	 * @param TokenStorageInterface $tokenStorage Nimmt das Token auf
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Connection $connection,
		private readonly TokenStorageInterface $tokenStorage,
	) {
	}

	/**
	 * Lädt den Benutzer, prüft ihn und legt das Token ab.
	 *
	 * Geprüft werden dieselben Sperren wie bei der Anmeldung im Backend:
	 * deaktiviert, noch nicht oder nicht mehr gültig (start/stop) und nach
	 * Fehlversuchen gesperrt (locked). Die Zwei-Faktor-Anmeldung entfällt
	 * hier bewusst – der Schlüssel selbst ist der zweite Faktor, er wurde im
	 * Backend (Stufe 1) bzw. nach vollständiger Anmeldung (Stufe 2) ausgestellt.
	 *
	 * @param int $userId ID des Benutzers in tl_user
	 *
	 * @return BackendUser Der angemeldete Benutzer mit allen Gruppenrechten
	 *
	 * @throws LoginFailedException Wenn es den Benutzer nicht gibt oder er gesperrt ist
	 */
	public function login(int $userId): BackendUser
	{
		$this->framework->initialize();

		$username = $this->connection->fetchOne('SELECT username FROM tl_user WHERE id = ?', array($userId));

		if (false === $username || '' === (string) $username) {
			throw new LoginFailedException('Der Backend-Benutzer zum Schlüssel existiert nicht mehr.');
		}

		$user = $this->framework->getAdapter(BackendUser::class)->loadUserByIdentifier((string) $username);

		if (!$user instanceof BackendUser) {
			throw new LoginFailedException('Der Backend-Benutzer zum Schlüssel ließ sich nicht laden.');
		}

		$now = time();

		if ($user->disable) {
			throw new LoginFailedException('Der Backend-Benutzer zum Schlüssel ist deaktiviert.');
		}

		if (('' !== (string) $user->start && (int) $user->start > $now) || ('' !== (string) $user->stop && (int) $user->stop > 0 && (int) $user->stop <= $now)) {
			throw new LoginFailedException('Der Backend-Benutzer zum Schlüssel ist zurzeit nicht freigeschaltet.');
		}

		if ((int) $user->locked > $now) {
			throw new LoginFailedException('Der Backend-Benutzer zum Schlüssel ist nach Fehlversuchen vorübergehend gesperrt.');
		}

		$this->tokenStorage->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));

		return $user;
	}

	/**
	 * Entfernt das Token wieder aus dem Speicher.
	 *
	 * Bei PHP-FPM endet der Prozess ohnehin mit der Anfrage; unter einem
	 * langlebigen Prozess (etwa im Test) bliebe der Benutzer sonst angemeldet.
	 */
	public function logout(): void
	{
		$this->tokenStorage->setToken(null);
	}
}
