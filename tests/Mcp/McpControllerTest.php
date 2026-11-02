<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\Mcp;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Schachbulle\ContaoClaudeBundle\Controller\McpController;
use Schachbulle\ContaoClaudeBundle\Mcp\CallContext;
use Schachbulle\ContaoClaudeBundle\Mcp\ServerFactory;
use Schachbulle\ContaoClaudeBundle\Security\BackendUserLogin;
use Schachbulle\ContaoClaudeBundle\Security\LoginFailedException;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Security\TokenValidator;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durchstich durch Controller, Schlüsselprüfung und das echte MCP-SDK.
 *
 * Nur Contao selbst ist ersetzt (Benutzer, Einstellungen, Anmeldung); der
 * Protokollteil läuft mit dem SDK wie im Betrieb, samt Sitzungsablage im
 * Dateisystem.
 */
class McpControllerTest extends TestCase
{
	private const TOKEN = 'test-schluessel';

	private string $sessionDirectory;

	private bool $enabled = true;

	private ?BackendUserLogin $login = null;

	/**
	 * Legt ein leeres Verzeichnis für die Protokollsitzungen an.
	 */
	protected function setUp(): void
	{
		$this->sessionDirectory = sys_get_temp_dir().'/contao-claude-test-'.bin2hex(random_bytes(4));
		$this->enabled = true;
		$this->login = null;
	}

	/**
	 * Räumt die Sitzungsdateien wieder ab.
	 */
	protected function tearDown(): void
	{
		foreach (glob($this->sessionDirectory.'/*') ?: array() as $file) {
			unlink($file);
		}

		if (is_dir($this->sessionDirectory)) {
			rmdir($this->sessionDirectory);
		}
	}

	/**
	 * Der Notschalter sperrt den Endpunkt mit 503.
	 */
	public function testKillSwitch(): void
	{
		$this->enabled = false;
		$response = $this->send($this->initialize());

		$this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
		$this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
	}

	/**
	 * Ohne Schlüssel kommt 401 mit WWW-Authenticate, aber ohne Fehlercode.
	 */
	public function testMissingToken(): void
	{
		$response = $this->send($this->initialize(), null);

		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertSame('Bearer realm="Contao MCP"', $response->headers->get('WWW-Authenticate'));
		$this->assertSame(-32001, json_decode((string) $response->getContent(), true)['error']['code']);
	}

	/**
	 * Ein falscher Schlüssel bekommt error="invalid_token".
	 */
	public function testWrongToken(): void
	{
		$response = $this->send($this->initialize(), 'falsch');

		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertStringContainsString('error="invalid_token"', (string) $response->headers->get('WWW-Authenticate'));
	}

	/**
	 * Ein gesperrter Benutzer wird wie ein ungültiger Schlüssel behandelt.
	 */
	public function testLockedUser(): void
	{
		$this->login = $this->createMock(BackendUserLogin::class);
		$this->login->method('login')->willThrowException(new LoginFailedException('Der Backend-Benutzer zum Schlüssel ist deaktiviert.'));

		$response = $this->send($this->initialize());

		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertStringContainsString('deaktiviert', (string) $response->getContent());
	}

	/**
	 * GET beantwortet der Endpunkt mit 405 und dem Allow-Header.
	 */
	public function testGetIsNotAllowed(): void
	{
		$response = $this->controller()(Request::create('https://example.org/mcp', 'GET'));

		$this->assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
		$this->assertSame('POST, DELETE, OPTIONS', $response->headers->get('Allow'));
	}

	/**
	 * Handschlag, Werkzeugliste, Aufruf und Werkzeugfehler in einer Sitzung.
	 */
	public function testFullSession(): void
	{
		$init = $this->send($this->initialize());
		$result = json_decode((string) $init->getContent(), true)['result'] ?? null;
		$session = $init->headers->get('Mcp-Session-Id');

		$this->assertSame(Response::HTTP_OK, $init->getStatusCode());
		$this->assertSame('2025-11-25', $result['protocolVersion']);
		$this->assertSame('contao-claude', $result['serverInfo']['name']);
		$this->assertStringContainsString('example.org', $result['instructions']);
		$this->assertStringContainsString('Test Redakteur (redakteur)', $result['instructions']);
		$this->assertStringContainsString('Regel A', $result['instructions']);
		$this->assertNotEmpty($session);
		$this->assertStringContainsString('no-store', (string) $init->headers->get('Cache-Control'));

		$initialized = $this->send(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'), self::TOKEN, $session);
		$this->assertSame(Response::HTTP_ACCEPTED, $initialized->getStatusCode());

		$list = json_decode((string) $this->send(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'), self::TOKEN, $session)->getContent(), true);
		$this->assertSame(array('echo'), array_column($list['result']['tools'], 'name'));
		$this->assertTrue($list['result']['tools'][0]['annotations']['readOnlyHint']);

		$call = json_decode((string) $this->send($this->call('Hallo Welt'), self::TOKEN, $session)->getContent(), true);
		$this->assertFalse($call['result']['isError'] ?? false);
		$this->assertSame(array('echo' => 'Hallo Welt'), $call['result']['structuredContent']);

		$error = json_decode((string) $this->send($this->call('fehler'), self::TOKEN, $session)->getContent(), true);
		$this->assertTrue($error['result']['isError']);
		$this->assertSame('Absichtlicher Fehler.', $error['result']['content'][0]['text']);
	}

	/**
	 * Fehlende Pflichtargumente weist das SDK schon vor dem Werkzeug ab.
	 */
	public function testSchemaValidation(): void
	{
		$session = $this->send($this->initialize())->headers->get('Mcp-Session-Id');
		$this->send(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'), self::TOKEN, $session);

		$response = json_decode((string) $this->send(array('jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => array('name' => 'echo', 'arguments' => new \stdClass())), self::TOKEN, $session)->getContent(), true);

		$this->assertSame(-32602, $response['error']['code']);
	}

	/**
	 * Baut die initialize-Nachricht.
	 *
	 * @return array<string, mixed> Die Nachricht
	 */
	private function initialize(): array
	{
		return array(
			'jsonrpc' => '2.0',
			'id' => 1,
			'method' => 'initialize',
			'params' => array(
				'protocolVersion' => '2025-11-25',
				'capabilities' => new \stdClass(),
				'clientInfo' => array('name' => 'phpunit', 'version' => '1.0'),
			),
		);
	}

	/**
	 * Baut einen Aufruf des Echo-Werkzeugs.
	 *
	 * @param string $text Der Text
	 *
	 * @return array<string, mixed> Die Nachricht
	 */
	private function call(string $text): array
	{
		return array('jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array('name' => 'echo', 'arguments' => array('text' => $text)));
	}

	/**
	 * Schickt eine Nachricht an den Controller.
	 *
	 * @param array<string, mixed> $message Die JSON-RPC-Nachricht
	 * @param string|null          $token   Bearer-Schlüssel, null für keinen
	 * @param string|null          $session Mcp-Session-Id
	 *
	 * @return Response Die Antwort
	 */
	private function send(array $message, ?string $token = self::TOKEN, ?string $session = null): Response
	{
		$server = array(
			'CONTENT_TYPE' => 'application/json',
			'HTTP_ACCEPT' => 'application/json, text/event-stream',
			'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
		);

		if (null !== $token) {
			$server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
		}

		if (null !== $session) {
			$server['HTTP_MCP_SESSION_ID'] = $session;
		}

		return $this->controller()(Request::create('https://example.org/mcp', 'POST', array(), array(), array(), $server, (string) json_encode($message)));
	}

	/**
	 * Baut den Controller mit Attrappen für Contao.
	 *
	 * @return McpController Der Controller
	 */
	private function controller(): McpController
	{
		$user = (new \ReflectionClass(BackendUser::class))->newInstanceWithoutConstructor();
		$user->name = 'Test Redakteur';
		$user->username = 'redakteur';
		$user->admin = '';

		$settings = $this->createMock(Settings::class);
		$settings->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$settings->method('getTestTokenHash')->willReturn(Settings::hashToken(self::TOKEN));
		$settings->method('getTestUserId')->willReturn(5);
		$settings->method('getInstructions')->willReturn('Regel A');

		$login = $this->login;

		if (null === $login) {
			$login = $this->createMock(BackendUserLogin::class);
			$login->method('login')->willReturn($user);
		}

		$permissions = $this->createMock(Permissions::class);
		$permissions->method('getUser')->willReturn($user);

		$requestStack = new RequestStack();
		$requestStack->push(Request::create('https://example.org/mcp'));

		$context = new CallContext();
		$factory = new ServerFactory(array(new EchoTool()), $settings, $permissions, $context, $requestStack, new NullLogger(), $this->sessionDirectory);

		return new McpController($this->createMock(ContaoFramework::class), $settings, new TokenValidator($settings), $login, $context, $factory, new NullLogger());
	}
}
