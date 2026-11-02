<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Data;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Data\ContentUrls;

/**
 * Prüft das Einsetzen des Vorschauskripts.
 */
class ContentUrlsTest extends TestCase
{
	/**
	 * Das Skript kommt direkt hinter den Host, Port und Pfad bleiben.
	 */
	public function testInsertsPreviewScript(): void
	{
		$urls = $this->urls('/preview.php');

		$this->assertSame('https://example.org/preview.php/news/titel.html', $urls->getPreviewUrl('https://example.org/news/titel.html'));
		$this->assertSame('http://127.0.0.1:8160/preview.php/', $urls->getPreviewUrl('http://127.0.0.1:8160/'));
		$this->assertSame('http://127.0.0.1:8160/preview.php/', $urls->getPreviewUrl('http://127.0.0.1:8160'));
	}

	/**
	 * Eine Adresse, die das Skript schon enthält, bleibt unverändert.
	 */
	public function testKeepsExistingPreviewScript(): void
	{
		$this->assertSame('https://example.org/preview.php/seite', $this->urls('preview.php')->getPreviewUrl('https://example.org/preview.php/seite'));
	}

	/**
	 * Ohne Vorschauskript gibt es keine Vorschauadresse.
	 */
	public function testWithoutPreviewScript(): void
	{
		$this->assertNull($this->urls('')->getPreviewUrl('https://example.org/seite'));
		$this->assertNull($this->urls('/preview.php')->getPreviewUrl('keine-adresse'));
	}

	/**
	 * Baut den Dienst mit einem Vorschauskript.
	 *
	 * @param string $script Wert von contao.preview_script
	 *
	 * @return ContentUrls Der Dienst
	 */
	private function urls(string $script): ContentUrls
	{
		return new ContentUrls($this->createMock(ContaoFramework::class), $script);
	}
}
