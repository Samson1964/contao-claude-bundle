<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Settings;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Prüft die reinen Hilfsfunktionen der Einstellungen.
 */
class SettingsTest extends TestCase
{
	/**
	 * Mehrzeilige Hausregeln überstehen das Verpacken samt Umbrüchen.
	 */
	public function testPackAndUnpackKeepLineBreaks(): void
	{
		$text = "E-Mail immer als {{email::adresse}}\r\nNamen <strong>fett</strong>\nUmlaute: äöüß";
		$packed = Settings::packText($text);

		$this->assertStringNotContainsString("\n", $packed);
		$this->assertSame(str_replace("\r\n", "\n", $text), Settings::unpackText($packed));
	}

	/**
	 * Contao 4.13 reicht den Wert mit htmlspecialchars() maskiert an den
	 * load_callback; auch dann kommt der Klartext heraus.
	 */
	public function testUnpackMaskedValue(): void
	{
		$text = "Namen <strong>fett</strong>\nZitate \"kursiv\"";

		$this->assertSame($text, Settings::unpackText(htmlspecialchars(Settings::packText($text))));
	}

	/**
	 * Leerer Text wird zu einer leeren Zeichenkette, nicht zu „""“.
	 */
	public function testPackEmptyText(): void
	{
		$this->assertSame('', Settings::packText("  \n "));
		$this->assertSame('', Settings::unpackText(''));
	}

	/**
	 * Von Hand eingetragene Werte ohne JSON kommen unverändert zurück.
	 */
	public function testUnpackPlainText(): void
	{
		$this->assertSame('Nur eine Zeile', Settings::unpackText('Nur eine Zeile'));
	}

	/**
	 * Die Tabellenliste verträgt verschiedene Trenner und verwirft Unsinn.
	 */
	public function testParseTableList(): void
	{
		$this->assertSame(
			array('tl_foo', 'tl_bar_*', 'tl_baz'),
			Settings::parseTableList("TL_FOO, tl_bar_*;\n tl_baz tl_foo tl-kaputt; *")
		);
	}

	/**
	 * Schlüssel sind 43 Zeichen base64url und jedes Mal anders.
	 */
	public function testGenerateToken(): void
	{
		$first = Settings::generateToken();

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $first);
		$this->assertNotSame($first, Settings::generateToken());
	}

	/**
	 * Der Hash ist SHA-256 in Hexadezimalschreibweise.
	 */
	public function testHashToken(): void
	{
		$this->assertSame(hash('sha256', 'abc'), Settings::hashToken('abc'));
		$this->assertSame(64, \strlen(Settings::hashToken(Settings::generateToken())));
	}
}
