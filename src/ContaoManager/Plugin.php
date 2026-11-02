<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Schachbulle\ContaoClaudeBundle\ContaoClaudeBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Meldet das Bundle und seine Routen beim Contao Manager an.
 */
class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
	/**
	 * Meldet das Bundle beim Kernel an.
	 *
	 * Das Bundle wird nach dem Contao-Core geladen, damit dessen
	 * BE_MOD-Einträge schon stehen, wenn die config.php des Bundles das
	 * eigene Modul unter „System“ einhängt.
	 *
	 * @param ParserInterface $parser Wird nicht ausgewertet
	 *
	 * @return array<int, BundleConfig> Die Konfiguration dieses einen Bundles
	 */
	public function getBundles(ParserInterface $parser): array
	{
		return array(
			BundleConfig::create(ContaoClaudeBundle::class)
				->setLoadAfter(array(ContaoCoreBundle::class)),
		);
	}

	/**
	 * Lädt die Routen des MCP-Endpunkts.
	 *
	 * Die Routen stehen bewusst in YAML und nicht als Attribute am
	 * Controller: Symfony 5.4 (Contao 4.13) liest Attribute über den Typ
	 * „annotation“, Symfony 7 (Contao 5) über „attribute“. YAML gilt in
	 * beiden gleich.
	 *
	 * @param LoaderResolverInterface $resolver Findet den passenden Lader für YAML
	 * @param KernelInterface         $kernel   Wird nicht benötigt
	 *
	 * @return RouteCollection|null Die Routen aus Resources/config/routes.yaml,
	 *                              oder null, wenn kein YAML-Lader verfügbar ist
	 */
	public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
	{
		$file = __DIR__.'/../Resources/config/routes.yaml';
		$loader = $resolver->resolve($file);

		return false === $loader ? null : $loader->load($file);
	}
}
