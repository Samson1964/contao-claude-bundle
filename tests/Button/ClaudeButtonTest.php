<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Button;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Button\ClaudeButton;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft Sichtbarkeit, Kontext und Adresse des Claude-Buttons.
 */
class ClaudeButtonTest extends TestCase
{
	/**
	 * Sichtbar nur bei eingeschaltetem Server und erlaubter Verbindung.
	 */
	public function testVisibility(): void
	{
		$this->assertTrue($this->button(true, null)->isVisibleFor(5));
		$this->assertFalse($this->button(false, null)->isVisibleFor(5));
		$this->assertFalse($this->button(true, 'Gruppe nicht erlaubt')->isVisibleFor(5));
		$this->assertFalse($this->button(true, null)->isVisibleFor(0));
	}

	/**
	 * Der Kontext beginnt mit der Installation und endet mit der Adresse.
	 */
	public function testContext(): void
	{
		$context = $this->button(true, null)->buildContext('www.example.org', array('Backend-Modul: Nachrichten (do=news)'), 'https://www.example.org/contao?do=news');

		$this->assertSame("Nutze den Contao-Konnektor der Installation www.example.org.\nBackend-Modul: Nachrichten (do=news)\nAdresse: https://www.example.org/contao?do=news", $context);
	}

	/**
	 * Request-Token und Verweis-Kennung verlassen die Installation nicht.
	 */
	public function testCleanUrlRemovesToken(): void
	{
		$request = Request::create('https://www.example.org/contao?do=news&act=edit&id=7&rt=geheim&ref=abc');

		$this->assertSame('https://www.example.org/contao?do=news&act=edit&id=7', ClaudeButton::cleanUrl($request));
	}

	/**
	 * Baut den Button mit festen Antworten für Notschalter und Zugangsregel.
	 *
	 * @param bool        $enabled Ob der Server eingeschaltet ist
	 * @param string|null $denied  Antwort der Zugangsregel, null für erlaubt
	 *
	 * @return ClaudeButton Der Button
	 */
	private function button(bool $enabled, ?string $denied): ClaudeButton
	{
		$settings = $this->createMock(Settings::class);
		$settings->method('isEnabled')->willReturn($enabled);

		$policy = $this->createMock(ConnectionPolicy::class);
		$policy->method('check')->willReturn($denied);

		return new ClaudeButton($settings, $policy);
	}
}
