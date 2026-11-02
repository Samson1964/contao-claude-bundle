<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Schachbulle\ContaoClaudeBundle\OAuth\GrantRepository;

/**
 * Räumt täglich die Verbindungstabelle auf.
 *
 * Das Attribut #[AsCronJob] gibt es in Contao 4.13 wie in 5.7; ohne
 * Methodenangabe ruft Contao __invoke() auf.
 */
#[AsCronJob('daily')]
class CleanupCron
{
	/**
	 * @param GrantRepository $grants Die Verbindungen
	 */
	public function __construct(private readonly GrantRepository $grants)
	{
	}

	/**
	 * Entfernt verfallene Codes, alte widerrufene oder abgelaufene
	 * Verbindungen und solche gelöschter Benutzer (GrantRepository::cleanup()).
	 */
	public function __invoke(): void
	{
		$this->grants->cleanup(time());
	}
}
