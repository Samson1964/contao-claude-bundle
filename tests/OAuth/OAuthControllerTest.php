<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tests\OAuth;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Psr\Log\NullLogger;
use Schachbulle\ContaoClaudeBundle\Controller\AuthorizeController;
use Schachbulle\ContaoClaudeBundle\Controller\TokenController;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Durchstich durch Bestätigungsseite und Token-Endpunkt.
 *
 * Mit echtem Twig-Template, echter Positivliste (hinterlegte Werte) und
 * echtem Autorisierungsserver auf SQLite; nur Contao selbst ist ersetzt.
 */
class OAuthControllerTest extends OAuthTestCase
{
	/**
	 * Unbekannter Client: Fehlerseite, keine Weiterleitung.
	 */
	public function testUnknownClient(): void
	{
		$response = $this->authorize(array('client_id' => 'https://evil.example.org/cimd'));

		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertFalse($response->headers->has('Location'));
		$this->assertStringContainsString('nicht zugelassen', (string) $response->getContent());
	}

	/**
	 * Fremde Rücksprungadresse: Fehlerseite, keine Weiterleitung (kein offener Redirect).
	 */
	public function testForeignRedirect(): void
	{
		$response = $this->authorize(array('redirect_uri' => 'https://evil.example.org/callback'));

		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertFalse($response->headers->has('Location'));
	}

	/**
	 * Die Bestätigungsseite nennt Client, Rücksprung und Zugang und trägt alles weiter.
	 */
	public function testConsentPage(): void
	{
		$response = $this->authorize();
		$html = (string) $response->getContent();

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertStringContainsString('Claude Code mit Contao verbinden', $html);
		$this->assertStringContainsString('dieser Rechner (127.0.0.1)', $html);
		$this->assertStringContainsString('Rita Redakteur (rita)', $html);
		$this->assertStringContainsString('name="code_challenge" value="'.self::CHALLENGE.'"', $html);
		$this->assertStringContainsString('name="REQUEST_TOKEN" value="csrf-test"', $html);
		$this->assertStringContainsString('Claude Code gestartet', $html);
		$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		$this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
	}

	/**
	 * Fehlt PKCE, geht der Fehler an den Client zurück, mit state und iss.
	 */
	public function testMissingPkce(): void
	{
		$response = $this->authorize(array('code_challenge' => ''));

		$this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
		$this->assertStringStartsWith(self::REDIRECT.'?error=invalid_request', (string) $response->headers->get('Location'));
		$this->assertStringContainsString('state=zustand-42', (string) $response->headers->get('Location'));
		$this->assertStringContainsString('iss=https%3A%2F%2Fexample.org', (string) $response->headers->get('Location'));
	}

	/**
	 * „Erlauben“ liefert einen Code, der sich am Token-Endpunkt tauschen lässt.
	 */
	public function testAllowAndExchange(): void
	{
		$response = $this->authorize(array('decision' => 'allow'), 'POST');
		$location = (string) $response->headers->get('Location');

		$this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
		$this->assertStringStartsWith(self::REDIRECT.'?code=', $location);

		parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
		$this->assertSame('zustand-42', $query['state']);

		$token = $this->token($this->exchangeParams((string) $query['code']));
		$data = json_decode((string) $token->getContent(), true);

		$this->assertSame(Response::HTTP_OK, $token->getStatusCode());
		$this->assertSame('Bearer', $data['token_type']);
		$this->assertArrayHasKey('refresh_token', $data);
		$this->assertStringContainsString('no-store', (string) $token->headers->get('Cache-Control'));
		$this->assertSame('no-cache', $token->headers->get('Pragma'));
	}

	/**
	 * „Ablehnen“ meldet access_denied an den Client.
	 */
	public function testDeny(): void
	{
		$response = $this->authorize(array('decision' => 'deny'), 'POST');

		$this->assertStringStartsWith(self::REDIRECT.'?error=access_denied', (string) $response->headers->get('Location'));
		$this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_claude_token'));
	}

	/**
	 * Ein Administrator ohne Schalter sieht die Begründung und den Weg zurück.
	 */
	public function testAdminIsRejected(): void
	{
		$response = $this->authorize(array(), 'GET', 2);
		$html = (string) $response->getContent();

		$this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		$this->assertStringContainsString('Administratoren dürfen sich', $html);
		$this->assertStringContainsString('error=access_denied', $html);
	}

	/**
	 * Notschalter aus: keine Bestätigung, kein Token.
	 */
	public function testDisabled(): void
	{
		$this->settingValues['enabled'] = false;

		$this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->authorize()->getStatusCode());
		$this->assertSame('temporarily_unavailable', json_decode((string) $this->token(array('grant_type' => 'refresh_token'))->getContent(), true)['error']);
	}

	/**
	 * Fehlerfälle am Token-Endpunkt nach RFC 6749.
	 */
	public function testTokenErrors(): void
	{
		$this->assertSame('unsupported_grant_type', json_decode((string) $this->token(array('grant_type' => 'password'))->getContent(), true)['error']);
		$this->assertSame('invalid_request', json_decode((string) $this->token(array())->getContent(), true)['error']);
		$this->assertSame('invalid_grant', json_decode((string) $this->token($this->exchangeParams('erfunden'))->getContent(), true)['error']);

		$get = $this->tokenController()->token(Request::create('https://example.org/mcp/token', 'GET'));
		$this->assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $get->getStatusCode());
	}

	/**
	 * Der Widerruf antwortet immer mit 200 und beendet die Verbindung.
	 */
	public function testRevokeEndpoint(): void
	{
		$tokens = $this->server->exchangeCode($this->exchangeParams($this->issueCode()), time());
		$response = $this->tokenController()->revoke(Request::create('https://example.org/mcp/revoke', 'POST', array('token' => $tokens['access_token'])));

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame('{}', $response->getContent());
		$this->assertNull($this->server->validateAccessToken((string) $tokens['access_token'], time()));
	}

	/**
	 * Ruft die Bestätigungsseite auf.
	 *
	 * @param array<string, string> $override Abweichende Parameter
	 * @param string                $method   GET oder POST
	 * @param int                   $userId   Angemeldeter Benutzer (1 Redakteurin, 2 Admin)
	 *
	 * @return Response Die Antwort
	 */
	private function authorize(array $override = array(), string $method = 'GET', int $userId = 1): Response
	{
		$params = $override + array(
			'response_type' => 'code',
			'client_id' => self::CODE_CLIENT,
			'redirect_uri' => self::REDIRECT,
			'code_challenge' => self::CHALLENGE,
			'code_challenge_method' => 'S256',
			'state' => 'zustand-42',
			'scope' => 'contao:read',
			'resource' => self::RESOURCE,
		);

		$row = $this->connection->fetchAssociative('SELECT * FROM tl_user WHERE id = ?', array($userId));
		$user = (new \ReflectionClass(BackendUser::class))->newInstanceWithoutConstructor();
		$user->id = $userId;
		$user->name = $row['name'];
		$user->username = $row['username'];
		$user->admin = $row['admin'];

		$tokenStorage = new TokenStorage();
		$tokenStorage->setToken(new UsernamePasswordToken($user, 'contao_backend', array('ROLE_USER')));

		$loader = new FilesystemLoader();
		$loader->addPath(__DIR__.'/../../src/Resources/views', 'ContaoClaude');

		$csrf = $this->createMock(ContaoCsrfTokenManager::class);
		$csrf->method('getDefaultTokenValue')->willReturn('csrf-test');

		$controller = new AuthorizeController(
			$this->createMock(ContaoFramework::class),
			$this->settings(),
			$this->registry(),
			$this->endpoints(),
			$this->server,
			new ConnectionPolicy($this->connection, $this->settings()),
			$tokenStorage,
			new Environment($loader),
			$csrf,
		);

		$request = 'POST' === $method
			? Request::create('https://example.org/contao/mcp/authorize', 'POST', $params)
			: Request::create('https://example.org/contao/mcp/authorize', 'GET', $params);

		return $controller($request);
	}

	/**
	 * Schickt Formularfelder an den Token-Endpunkt.
	 *
	 * @param array<string, string> $params Die Felder
	 *
	 * @return Response Die Antwort
	 */
	private function token(array $params): Response
	{
		return $this->tokenController()->token(Request::create('https://example.org/mcp/token', 'POST', $params));
	}

	/**
	 * Baut den Token-Controller.
	 *
	 * @return TokenController Der Controller
	 */
	private function tokenController(): TokenController
	{
		return new TokenController($this->createMock(ContaoFramework::class), $this->settings(), $this->server, new NullLogger());
	}
}
