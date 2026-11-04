<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Mcp;

use Contao\CoreBundle\Config\ResourceFinderInterface;

/**
 * Wertet einen Abbruch beim Start des Contao-Frameworks aus.
 *
 * Hintergrund: Auf schachbund.de leitete /mcp beim Start von Contao im
 * Backend-Modus zur Backend-Anmeldung um (303), in den Testinstallationen
 * mit derselben Contao-Fassung nicht. Irgendein Code der Installation wirft
 * dabei eine Weiterleitung. Diese Klasse macht aus dem Stack-Trace eine
 * lesbare Liste und sucht die erste Stelle außerhalb von Contao, Symfony und
 * diesem Bundle – das ist mit großer Wahrscheinlichkeit der Verursacher.
 */
final class StartupTrace
{
	/**
	 * Höchstzahl der Stellen, die in Protokoll und Antwort landen.
	 */
	private const MAX_FRAMES = 25;

	/**
	 * Pfadteile, die als „unschuldig“ gelten: Sie liegen auf jedem Weg zum
	 * Verursacher, sind es aber selbst nicht.
	 */
	private const INNOCENT = array(
		'/vendor/contao/core-bundle/',
		'/vendor/contao/manager-bundle/',
		'/vendor/symfony/',
		'/vendor/composer/',
		'/vendor/friendsofsymfony/http-cache/',
		'/vendor/autoload.php',
		'/public/index.php',
		'/web/index.php',
	);

	/**
	 * Wurzel dieses Bundles.
	 */
	private readonly string $bundleDir;

	/**
	 * @param string      $projectDir Wurzel der Installation; wird aus den Pfaden
	 *                                entfernt, damit keine Serverpfade nach außen gehen
	 * @param ResourceFinderInterface|null $resourceFinder Findet die config.php-Dateien
	 *                                aller Bundles, um eine Stelle im zusammengefassten
	 *                                Cache auf die Quelldatei zurückzuführen
	 * @param string|null $bundleDir  Wurzel dieses Bundles, deren Stellen ebenfalls
	 *                                nicht als Verursacher zählen; null nimmt den
	 *                                Ordner, in dem diese Klasse liegt (für Tests)
	 */
	public function __construct(private readonly string $projectDir, private readonly ?ResourceFinderInterface $resourceFinder = null, ?string $bundleDir = null)
	{
		$this->bundleDir = $bundleDir ?? \dirname(__DIR__, 2);
	}

	/**
	 * Liefert die Stellen des Abbruchs, beginnend mit der werfenden Zeile.
	 *
	 * Jede Stelle hat die Form „pfad:zeile Klasse->methode()“, der Pfad ist
	 * relativ zur Installation. Stellen ohne Datei (interne PHP-Aufrufe)
	 * entfallen.
	 *
	 * @param \Throwable $exception Der Abbruch
	 *
	 * @return list<array{file: string, line: int, call: string}> Höchstens MAX_FRAMES Stellen
	 */
	public function frames(\Throwable $exception): array
	{
		$frames = array(array('file' => $this->normalize($exception->getFile()), 'line' => $exception->getLine(), 'call' => 'throw '.$exception::class));

		foreach ($exception->getTrace() as $frame) {
			if (!isset($frame['file'])) {
				continue;
			}

			$call = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '->').$frame['function'].'()' : ($frame['function'] ?? '').'()';
			$frames[] = array('file' => $this->normalize($frame['file']), 'line' => (int) ($frame['line'] ?? 0), 'call' => $call);

			if (\count($frames) >= self::MAX_FRAMES) {
				break;
			}
		}

		return $frames;
	}

	/**
	 * Sucht die erste Stelle außerhalb von Contao, Symfony und diesem Bundle.
	 *
	 * @param \Throwable $exception Der Abbruch
	 *
	 * @return string|null „pfad:zeile“, oder null, wenn alle Stellen im Kern liegen
	 */
	public function origin(\Throwable $exception): ?string
	{
		foreach ($this->frames($exception) as $frame) {
			if (!$this->isInnocent($frame['file'])) {
				return $frame['file'].':'.$frame['line'].$this->cacheSource($frame['file'], $frame['line']);
			}
		}

		return null;
	}

	/**
	 * Führt eine Stelle im zusammengefassten Contao-Cache auf die Quelle zurück.
	 *
	 * Im Prod-Modus legt Contao alle config.php-Dateien (ebenso DCA- und
	 * Sprachdateien) zu einer Datei unter var/cache/<env>/contao/ zusammen,
	 * ohne zu vermerken, woher ein Abschnitt stammt. Die Zeile wird deshalb
	 * gelesen und in den Quelldateien aller Bundles gesucht. Kommt sie in
	 * mehreren vor, werden alle genannt.
	 *
	 * @param string $file Pfad wie von normalize() geliefert
	 * @param int    $line Zeile in dieser Datei
	 *
	 * @return string „ (Quelle: …)“, oder ein leerer Text, wenn die Stelle nicht
	 *                im Cache liegt oder keine Quelle zu finden ist
	 */
	private function cacheSource(string $file, int $line): string
	{
		if (null === $this->resourceFinder || !preg_match('#^/var/cache/[^/]+/contao/((?:config|dca|languages/[^/]+))/([^/]+\.php)$#', $file, $match)) {
			return '';
		}

		$lines = @file($this->projectDir.$file, FILE_IGNORE_NEW_LINES);
		$code = trim((string) ($lines[$line - 1] ?? ''));

		// Sehr kurze Zeilen („}“, „);“) kommen überall vor und sagen nichts
		if (\strlen($code) < 8) {
			return '';
		}

		$sources = array();

		try {
			foreach ($this->resourceFinder->findIn($match[1])->files()->name($match[2]) as $candidate) {
				$path = $candidate->getPathname();

				foreach ((array) @file($path, FILE_IGNORE_NEW_LINES) as $index => $text) {
					if (trim((string) $text) === $code) {
						$sources[] = $this->normalize($path).':'.($index + 1);
						break;
					}
				}
			}
		} catch (\Throwable) {
			// Ohne Quelle bleibt es bei der Stelle im Cache
			return '';
		}

		return array() === $sources ? '' : ' (Quelle: '.implode(' oder ', $sources).')';
	}

	/**
	 * Fasst die Stellen für das Protokoll zu einem Text zusammen.
	 *
	 * @param \Throwable $exception Der Abbruch
	 *
	 * @return string Eine Stelle je Zeile
	 */
	public function describe(\Throwable $exception): string
	{
		$lines = array();

		foreach ($this->frames($exception) as $index => $frame) {
			$lines[] = sprintf('#%d %s:%d%s %s', $index, $frame['file'], $frame['line'], $this->cacheSource($frame['file'], $frame['line']), $frame['call']);
		}

		return implode("\n", $lines);
	}

	/**
	 * Macht einen Pfad relativ zur Installation und vereinheitlicht die
	 * Trennzeichen (Windows liefert Backslashes).
	 *
	 * Liegt das Bundle als Pfad-Paket außerhalb der Installation, erhält es
	 * den Vorsatz „[claude-bundle]“.
	 *
	 * @param string $path Absoluter Pfad
	 *
	 * @return string Der Pfad mit führendem Schrägstrich
	 */
	private function normalize(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$bundle = rtrim(str_replace('\\', '/', $this->bundleDir), '/');
		$project = rtrim(str_replace('\\', '/', $this->projectDir), '/');

		if ('' !== $bundle && str_starts_with($path, $bundle.'/')) {
			return '[claude-bundle]'.substr($path, \strlen($bundle));
		}

		if ('' !== $project && str_starts_with($path, $project.'/')) {
			return substr($path, \strlen($project));
		}

		return $path;
	}

	/**
	 * Prüft, ob eine Stelle zu Contao, Symfony oder diesem Bundle gehört.
	 *
	 * @param string $file Pfad wie von normalize() geliefert
	 *
	 * @return bool true, wenn die Stelle nicht als Verursacher infrage kommt
	 */
	private function isInnocent(string $file): bool
	{
		if (str_starts_with($file, '[claude-bundle]') || str_contains($file, '/vendor/schachbulle/contao-claude-bundle/')) {
			return true;
		}

		foreach (self::INNOCENT as $part) {
			if (str_contains($file, $part)) {
				return true;
			}
		}

		return false;
	}
}
