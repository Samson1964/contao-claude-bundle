<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Data;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Data\PaletteResolver;

/**
 * Prüft den Nachbau von Contaos Palettenauswahl.
 */
class PaletteResolverTest extends TestCase
{
	/**
	 * Gekürztes DCA im Stil von tl_content.
	 *
	 * @var array<string, mixed>
	 */
	private const DCA = array(
		'palettes' => array(
			'__selector__' => array('type', 'addImage', 'source'),
			'default' => '{type_legend},type',
			'text' => '{type_legend},type,headline;{text_legend},text;{image_legend},addImage;{invisible_legend},invisible',
			'download' => '{type_legend},type;{source_legend},source',
		),
		'subpalettes' => array(
			'addImage' => 'singleSRC,size',
			'source_external' => 'url',
		),
		'fields' => array(
			'type' => array('inputType' => 'select'),
			'addImage' => array('inputType' => 'checkbox'),
			'source' => array('inputType' => 'radio'),
		),
	);

	/**
	 * combiner() liefert dieselbe Reihenfolge wie Contao.
	 *
	 * Erwartungswerte mit Contaos Originalcode (PaletteBuilder::combiner()
	 * aus 5.7) nachgerechnet.
	 */
	public function testCombinerMatchesContao(): void
	{
		$this->assertSame(array(1 => 'b', 2 => 'ab', 3 => 'a'), PaletteResolver::combiner(array('a', 'b')));
		$this->assertSame(array('c', 'bc', 'b', 'ab', 'abc', 'ac', 'a'), array_values(PaletteResolver::combiner(array('a', 'b', 'c'))));
	}

	/**
	 * Der Typ wählt die Palette, eine aktive Checkbox fügt ihre Subpalette ein.
	 */
	public function testTypeAndCheckboxSubpalette(): void
	{
		$this->assertSame(
			array('type', 'headline', 'text', 'addImage', 'singleSRC', 'size', 'invisible'),
			PaletteResolver::getFields(self::DCA, array('type' => 'text', 'addImage' => '1'))
		);
	}

	/**
	 * Ohne Haken bleibt die Subpalette draußen.
	 */
	public function testInactiveCheckbox(): void
	{
		$this->assertSame(
			array('type', 'headline', 'text', 'addImage', 'invisible'),
			PaletteResolver::getFields(self::DCA, array('type' => 'text', 'addImage' => ''))
		);
	}

	/**
	 * Bei Auswahlfeldern heißt die Subpalette „feld_wert“.
	 */
	public function testSelectSubpalette(): void
	{
		$this->assertSame(array('type', 'source', 'url'), PaletteResolver::getFields(self::DCA, array('type' => 'download', 'source' => 'external')));
		$this->assertSame(array('type', 'source'), PaletteResolver::getFields(self::DCA, array('type' => 'download', 'source' => 'internal')));
	}

	/**
	 * Ein unbekannter Typ fällt auf „default“ zurück.
	 */
	public function testUnknownTypeFallsBackToDefault(): void
	{
		$this->assertSame(array('type'), PaletteResolver::getFields(self::DCA, array('type' => 'gibtsnicht')));
	}

	/**
	 * Ohne Selektoren gilt die Palette „default“, ohne Paletten gibt es nichts.
	 */
	public function testWithoutSelectors(): void
	{
		$this->assertSame(array('title', 'alias'), PaletteResolver::getFields(array('palettes' => array('default' => '{title_legend},title,alias')), array()));
		$this->assertSame(array(), PaletteResolver::getFields(array(), array()));
	}
}
