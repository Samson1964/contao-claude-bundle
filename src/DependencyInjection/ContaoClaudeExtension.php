<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Lädt die Dienste des Bundles in den Container.
 *
 * Ohne diese Klasse liest Symfony die services.yaml nie ein, und sämtliche
 * Dienste, Callbacks und Controller blieben stumm. Die Basisklasse liegt in
 * Symfony 5.4 (Contao 4.13) wie in Symfony 7 (Contao 5.7) unter demselben
 * Namensraum.
 */
class ContaoClaudeExtension extends Extension
{
	/**
	 * Liest die services.yaml des Bundles.
	 *
	 * Eigene Konfigurationsschlüssel gibt es nicht; alle Einstellungen liegen
	 * in der localconfig.php und werden im Backend gepflegt.
	 *
	 * @param array<int|string, mixed> $configs   Wird nicht ausgewertet
	 * @param ContainerBuilder         $container Der Container
	 */
	public function load(array $configs, ContainerBuilder $container): void
	{
		$loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
		$loader->load('services.yaml');
	}
}
