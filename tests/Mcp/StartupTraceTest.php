<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Mcp\StartupTrace;

/**
 * Prüft, dass ein Abbruch beim Start auf die richtige Stelle zurückgeführt
 * wird und keine Serverpfade nach außen gelangen.
 */
class StartupTraceTest extends TestCase
{
	/**
	 * Die werfende Stelle im Bundle selbst gilt nicht als Verursacher; genannt
	 * wird die erste Stelle außerhalb, mit Pfad relativ zur Installation.
	 */
	public function testOriginSkipsOwnBundleAndStripsProjectDir(): void
	{
		$projectDir = \dirname(__DIR__);
		$trace = new StartupTrace($projectDir, null, \dirname(__DIR__, 2).'/src');

		$exception = $this->throwFromHere();

		$this->assertSame('/Mcp/StartupTraceTest.php:'.$this->line, $trace->origin($exception));
		$this->assertStringNotContainsString($projectDir, $trace->describe($exception));
	}

	/**
	 * Liegt jede Stelle im Kern oder im Bundle, gibt es keinen Verursacher.
	 */
	public function testOriginIsNullWhenEverythingIsInnocent(): void
	{
		$trace = new StartupTrace('/irgendwo', null, \dirname(__DIR__, 2));

		$this->assertNull($trace->origin($this->throwFromHere()));
	}

	/**
	 * Zeile, in der throwFromHere() wirft.
	 */
	private int $line = 0;

	/**
	 * Erzeugt eine Ausnahme mit bekannter Wurfzeile in dieser Datei.
	 *
	 * @return \RuntimeException Die Ausnahme, nicht geworfen, aber mit Trace
	 */
	private function throwFromHere(): \RuntimeException
	{
		$this->line = __LINE__ + 1;
		$exception = new \RuntimeException('Probe');

		return $exception;
	}
}
