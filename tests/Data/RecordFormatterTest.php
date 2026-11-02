<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Data;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;

/**
 * Prüft die Aufbereitung von Datenbankwerten für Claude.
 */
class RecordFormatterTest extends TestCase
{
	/**
	 * Eine binäre UUID der Version 1, wie Contao sie in tl_files vergibt.
	 */
	private const UUID = '468a5098-8f26-11f1-9600-0a0027000008';

	/**
	 * Contaos Ersatzschreibweisen werden zu echten Entities.
	 */
	public function testRestoresBasicEntities(): void
	{
		$this->assertSame('a&nbsp;b &lt;c&gt; &amp;', $this->formatter()->formatValue('text', 'a[nbsp]b [lt]c[gt] [&]'));
	}

	/**
	 * Systemspalten werden zu Zahlen, tstamp zu einem ISO-Zeitpunkt.
	 */
	public function testSystemColumns(): void
	{
		$formatter = $this->formatter();

		$this->assertSame(7, $formatter->formatValue('id', '7'));
		$this->assertSame(128, $formatter->formatValue('sorting', '128'));
		$this->assertSame('2026-11-02T15:30:00+01:00', $formatter->formatValue('tstamp', (string) mktime(15, 30, 0, 11, 2, 2026)));
	}

	/**
	 * Datumsfelder richten sich nach rgxp, leere Felder werden zu null.
	 */
	public function testDates(): void
	{
		$timestamp = mktime(9, 5, 0, 3, 1, 2026);

		$this->assertSame('2026-03-01', RecordFormatter::formatDate($timestamp, 'date'));
		$this->assertSame('09:05', RecordFormatter::formatDate($timestamp, 'time'));
		$this->assertSame('2026-03-01T09:05:00+01:00', RecordFormatter::formatDate($timestamp, 'datim'));
		$this->assertNull(RecordFormatter::formatDate('', 'date'));
		$this->assertNull(RecordFormatter::formatDate('0', 'date'));
		$this->assertSame('2026-03-01', $this->formatter()->formatValue('date', (string) $timestamp, array('eval' => array('rgxp' => 'date'))));
	}

	/**
	 * Einzelne Checkboxen werden zu true/false, mehrfache bleiben Listen.
	 */
	public function testCheckboxes(): void
	{
		$formatter = $this->formatter();
		$single = array('inputType' => 'checkbox');

		$this->assertTrue($formatter->formatValue('published', '1', $single));
		$this->assertFalse($formatter->formatValue('published', '', $single));
		$this->assertFalse($formatter->formatValue('published', 0, $single));
		$this->assertSame(array('a', 'b'), $formatter->formatValue('groups', serialize(array('a', 'b')), array('inputType' => 'checkbox', 'eval' => array('multiple' => true))));
	}

	/**
	 * Eine Überschrift ohne Text gilt als leer, mit Text bleibt sie erhalten.
	 */
	public function testInputUnit(): void
	{
		$formatter = $this->formatter();
		$config = array('inputType' => 'inputUnit');

		$this->assertSame('', $formatter->formatValue('headline', serialize(array('value' => '', 'unit' => 'h2')), $config));
		$this->assertSame(array('value' => 'Titel', 'unit' => 'h3'), $formatter->formatValue('headline', serialize(array('value' => 'Titel', 'unit' => 'h3')), $config));
	}

	/**
	 * Binäre UUIDs werden zu {uuid, path}, auch in Listen.
	 */
	public function testFileReferences(): void
	{
		$binary = StringUtil::uuidToBin(self::UUID);
		$formatter = $this->formatter('files/bilder/turm.jpg');

		$this->assertSame(array('uuid' => self::UUID, 'path' => 'files/bilder/turm.jpg'), $formatter->formatValue('singleSRC', $binary));
		$this->assertSame(array(array('uuid' => self::UUID, 'path' => 'files/bilder/turm.jpg')), $formatter->formatValue('multiSRC', serialize(array($binary))));
	}

	/**
	 * Eine UUID ohne Eintrag in tl_files behält ihren Pfad null.
	 */
	public function testMissingFile(): void
	{
		$this->assertSame(array('uuid' => self::UUID, 'path' => null), $this->formatter(null)->formatValue('singleSRC', StringUtil::uuidToBin(self::UUID)));
	}

	/**
	 * Andere Binärdaten werden nicht roh ausgegeben.
	 */
	public function testBinaryData(): void
	{
		$this->assertSame('(Binärdaten, 3 Byte)', $this->formatter()->formatValue('blob', "\xFF\xFE\x00"));
	}

	/**
	 * format() hält die Reihenfolge der Felder ein und lässt Leeres weg.
	 */
	public function testFormatRow(): void
	{
		$row = array('id' => '3', 'title' => 'Hallo', 'teaser' => '', 'unbekannt' => 'x', 'cssID' => serialize(array('', '')));
		$fields = array('id' => array(), 'title' => array(), 'teaser' => array(), 'cssID' => array());

		$this->assertSame(array('id' => 3, 'title' => 'Hallo', 'teaser' => '', 'cssID' => array('', '')), $this->formatter()->format($row, $fields));
		$this->assertSame(array('id' => 3, 'title' => 'Hallo', 'cssID' => array('', '')), $this->formatter()->format($row, $fields, true));
	}

	/**
	 * Der Textauszug entfernt Tags und kürzt an der Zeichengrenze.
	 */
	public function testExcerpt(): void
	{
		$this->assertSame('Ein Text mit Umlauten: äöü', RecordFormatter::excerpt("<p>Ein   Text</p>\n<p>mit <b>Umlauten</b>:[nbsp]äöü</p>"));
		$this->assertSame('Abcd…', RecordFormatter::excerpt('Abcdefgh', 5));
	}

	/**
	 * Baut den Formatierer mit einem FilesModel-Adapter.
	 *
	 * @param string|null $path Pfad, den findByUuid() liefert; null für „nicht gefunden“
	 *
	 * @return RecordFormatter Der Formatierer
	 */
	private function formatter(?string $path = 'files/test.jpg'): RecordFormatter
	{
		$model = null;

		if (null !== $path) {
			$model = $this->getMockBuilder(FilesModel::class)->disableOriginalConstructor()->onlyMethods(array('__get'))->getMock();
			$model->method('__get')->willReturnCallback(static fn (string $key) => 'path' === $key ? $path : null);
		}

		$adapter = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(array('findByUuid'))->getMock();
		$adapter->method('findByUuid')->willReturn($model);

		$framework = $this->createMock(ContaoFramework::class);
		$framework->method('getAdapter')->willReturn($adapter);

		return new RecordFormatter($framework);
	}
}
