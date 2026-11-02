<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;
use Schachbulle\ContaoClaudeBundle\Tool\ToolInterface;

/**
 * Prüft für jedes Werkzeug den Vertrag mit dem SDK.
 *
 * Das SDK ordnet die Argumente aus tools/call den Parametern von __invoke()
 * nach ihrem Namen zu. Ein Tippfehler im Schema lässt einen Parameter
 * stillschweigend auf seinem Vorgabewert stehen – das fiele im Betrieb kaum
 * auf. Deshalb wird hier jeder Schlüssel gegen die Signatur geprüft.
 */
class ToolContractTest extends TestCase
{
	/**
	 * Liefert alle Werkzeugklassen des Bundles.
	 *
	 * @return iterable<string, array{0: class-string<ToolInterface>}> Klassenname je Werkzeug
	 */
	public static function toolProvider(): iterable
	{
		foreach (glob(__DIR__.'/../../src/Tool/*Tool.php') ?: array() as $file) {
			$class = 'Schachbulle\\ContaoClaudeBundle\\Tool\\'.basename($file, '.php');

			if ((new \ReflectionClass($class))->isInstantiable()) {
				yield basename($file, '.php') => array($class);
			}
		}
	}

	/**
	 * Es gibt genau die zwölf Werkzeuge der Ausbaustufe 1.
	 */
	public function testAllToolsPresent(): void
	{
		$names = array();

		foreach (self::toolProvider() as [$class]) {
			$names[] = $this->tool($class)->getName();
		}

		sort($names);

		$this->assertSame(array('article_list', 'content_list', 'dca_describe', 'file_list', 'news_get', 'news_search', 'page_tree', 'preview_url', 'record_get', 'record_history', 'record_list', 'site_info'), $names);
	}

	/**
	 * Schema und Signatur passen zueinander.
	 *
	 * @dataProvider toolProvider
	 *
	 * @param class-string<ToolInterface> $class Die Werkzeugklasse
	 */
	public function testSchemaMatchesSignature(string $class): void
	{
		$tool = $this->tool($class);
		$schema = $tool->getInputSchema();
		$properties = array_keys((array) $schema['properties']);
		$parameters = array();
		$requiredParameters = array();

		foreach ((new \ReflectionMethod($class, '__invoke'))->getParameters() as $parameter) {
			$parameters[] = $parameter->getName();

			if (!$parameter->isOptional()) {
				$requiredParameters[] = $parameter->getName();
			}
		}

		sort($properties);
		sort($parameters);
		sort($requiredParameters);

		$required = (array) ($schema['required'] ?? array());
		sort($required);

		$this->assertSame('object', $schema['type']);
		$this->assertSame($parameters, $properties, 'Schema-Schlüssel und Parameternamen');
		$this->assertSame($requiredParameters, $required, 'Pflichtangaben');
	}

	/**
	 * Name, Beschreibung und Hinweise genügen den Regeln.
	 *
	 * @dataProvider toolProvider
	 *
	 * @param class-string<ToolInterface> $class Die Werkzeugklasse
	 */
	public function testMetadata(string $class): void
	{
		$tool = $this->tool($class);
		$annotations = $tool->getAnnotations();

		// Regel des SDK (NameValidator), für Claude zusätzlich nur Kleinbuchstaben
		$this->assertMatchesRegularExpression('/^[a-z_]{1,64}$/', $tool->getName());
		$this->assertGreaterThan(60, mb_strlen($tool->getDescription()));
		$this->assertNotSame('', $tool->getTitle());
		$this->assertTrue($annotations->readOnlyHint);
		$this->assertFalse($annotations->destructiveHint);
		$this->assertSame(AuthenticatedClient::SCOPE_READ, $tool->getRequiredScope());
	}

	/**
	 * Erzeugt ein Werkzeug ohne seine Abhängigkeiten.
	 *
	 * Name, Schema und Hinweise brauchen keine Dienste.
	 *
	 * @param class-string<ToolInterface> $class Die Werkzeugklasse
	 *
	 * @return ToolInterface Das Werkzeug
	 */
	private function tool(string $class): ToolInterface
	{
		return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
	}
}
