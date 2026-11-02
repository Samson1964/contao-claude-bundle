<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\OAuth;

use Schachbulle\ContaoClaudeBundle\Security\AuthenticatedClient;
use Schachbulle\ContaoClaudeBundle\Security\ConnectionPolicy;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;

/**
 * Die Regeln des schlanken OAuth-2.1-Servers.
 *
 * Bewusst ohne OAuth- oder JWT-Bibliothek, um Abhängigkeitskonflikte mit
 * Contao zu vermeiden. Schlüssel sind zufällig (32 Byte, base64url) und
 * liegen nur als SHA-256-Hash in tl_claude_token. Gültigkeit: Code
 * 10 Minuten, Zugriffsschlüssel 1 Stunde, Erneuerungsschlüssel 30 Tage ab
 * der letzten Erneuerung. Jede Erneuerung gibt beide Schlüssel neu aus, der
 * alte Erneuerungsschlüssel wird dabei ungültig (Rotation, OAuth 2.1 für
 * öffentliche Clients).
 *
 * Claude ist ein öffentlicher Client ohne Geheimnis; die Bindung an den
 * Client leisten PKCE (S256) und die geprüfte Rücksprungadresse.
 */
class AuthorizationServer
{
	public const CODE_TTL = 600;
	public const ACCESS_TTL = 3600;
	public const REFRESH_TTL = 30 * 86400;

	/**
	 * @param GrantRepository  $grants   Die Verbindungen
	 * @param ConnectionPolicy $policy   Wer sich verbinden darf
	 * @param Endpoints        $endpoints Die eigene Ressource
	 */
	public function __construct(
		private readonly GrantRepository $grants,
		private readonly ConnectionPolicy $policy,
		private readonly Endpoints $endpoints,
	) {
	}

	/**
	 * Stellt nach der Bestätigung einen Autorisierungscode aus.
	 *
	 * @param int          $userId        Der bestätigende Backend-Benutzer
	 * @param OAuthClient  $client        Der Client
	 * @param string       $redirectUri   Die geprüfte Rücksprungadresse
	 * @param string       $codeChallenge Die S256-Challenge
	 * @param list<string> $scopes        Die gewährten Scopes
	 * @param string       $resource      Die Ressource, für die der Schlüssel gelten soll
	 * @param int          $now           Aktuelle Zeit
	 *
	 * @return string Der Code im Klartext für den Rücksprung
	 */
	public function issueCode(int $userId, OAuthClient $client, string $redirectUri, string $codeChallenge, array $scopes, string $resource, int $now): string
	{
		$code = Settings::generateToken();

		$this->grants->insert(array(
			'userId' => $userId,
			'clientId' => $client->id,
			'clientName' => $client->name,
			'scope' => implode(' ', $scopes),
			'resource' => $resource,
			'redirectUri' => $redirectUri,
			'codeHash' => Settings::hashToken($code),
			'codeChallenge' => $codeChallenge,
			'codeExpires' => $now + self::CODE_TTL,
			'created' => $now,
		));

		return $code;
	}

	/**
	 * Tauscht einen Autorisierungscode gegen Schlüssel (grant_type=authorization_code).
	 *
	 * Ein Code gilt genau einmal. Wird ein schon eingelöster Code erneut
	 * vorgelegt, ist er vermutlich abgefangen worden; die ganze Verbindung
	 * wird dann widerrufen (RFC 6749 Abschnitt 4.1.2).
	 *
	 * @param array<string, string> $params Die Formularfelder der Token-Anfrage
	 * @param int                   $now    Aktuelle Zeit
	 *
	 * @return array<string, int|string> Die Antwort nach RFC 6749 Abschnitt 5.1
	 *
	 * @throws OAuthException Bei fehlenden Angaben oder ungültigem Code
	 */
	public function exchangeCode(array $params, int $now): array
	{
		foreach (array('code', 'redirect_uri', 'client_id', 'code_verifier') as $name) {
			if ('' === ($params[$name] ?? '')) {
				throw new OAuthException('invalid_request', sprintf('Der Parameter %s fehlt.', $name));
			}
		}

		$grant = $this->grants->findByHash('codeHash', Settings::hashToken($params['code']));

		if (null === $grant || '1' === (string) $grant['revoked']) {
			throw new OAuthException('invalid_grant', 'Der Code ist unbekannt oder ungültig.');
		}

		if ('1' === (string) $grant['codeUsed']) {
			$this->grants->revoke((int) $grant['id']);

			throw new OAuthException('invalid_grant', 'Der Code wurde schon eingelöst; die Verbindung ist widerrufen.');
		}

		if ((int) $grant['codeExpires'] < $now) {
			throw new OAuthException('invalid_grant', 'Der Code ist abgelaufen.');
		}

		if ($grant['clientId'] !== $params['client_id'] || $grant['redirectUri'] !== $params['redirect_uri']) {
			throw new OAuthException('invalid_grant', 'Client oder Rücksprungadresse passen nicht zum Code.');
		}

		if (!Pkce::verify($params['code_verifier'], (string) $grant['codeChallenge'])) {
			throw new OAuthException('invalid_grant', 'Der code_verifier passt nicht zur code_challenge.');
		}

		$this->assertResource($params['resource'] ?? '', (string) $grant['resource']);
		$this->assertPolicy((int) $grant['userId']);

		return $this->issueTokens((int) $grant['id'], Scopes::split((string) $grant['scope']), $now, array('codeUsed' => '1'));
	}

	/**
	 * Erneuert die Schlüssel einer Verbindung (grant_type=refresh_token).
	 *
	 * @param array<string, string> $params Die Formularfelder der Token-Anfrage
	 * @param int                   $now    Aktuelle Zeit
	 *
	 * @return array<string, int|string> Die Antwort mit neuem Zugriffs- und Erneuerungsschlüssel
	 *
	 * @throws OAuthException Bei unbekanntem, abgelaufenem oder fremdem Erneuerungsschlüssel
	 */
	public function refresh(array $params, int $now): array
	{
		foreach (array('refresh_token', 'client_id') as $name) {
			if ('' === ($params[$name] ?? '')) {
				throw new OAuthException('invalid_request', sprintf('Der Parameter %s fehlt.', $name));
			}
		}

		$grant = $this->grants->findByHash('refreshHash', Settings::hashToken($params['refresh_token']));

		if (null === $grant || '1' === (string) $grant['revoked'] || (int) $grant['refreshExpires'] < $now || $grant['clientId'] !== $params['client_id']) {
			throw new OAuthException('invalid_grant', 'Der Erneuerungsschlüssel ist ungültig oder abgelaufen.');
		}

		$scopes = Scopes::split((string) $grant['scope']);

		if ('' !== ($params['scope'] ?? '')) {
			$requested = Scopes::split($params['scope']);

			if (array() !== array_diff($requested, $scopes)) {
				throw new OAuthException('invalid_scope', 'Eine Erneuerung kann keine zusätzlichen Rechte gewähren.');
			}

			$scopes = $requested;
		}

		$this->assertResource($params['resource'] ?? '', (string) $grant['resource']);
		$this->assertPolicy((int) $grant['userId']);

		return $this->issueTokens((int) $grant['id'], $scopes, $now);
	}

	/**
	 * Widerruft die Verbindung zu einem Schlüssel (RFC 7009).
	 *
	 * Ob Zugriffs- oder Erneuerungsschlüssel: Widerrufen wird die ganze
	 * Verbindung. Unbekannte Schlüssel sind kein Fehler; die Antwort ist in
	 * jedem Fall dieselbe, damit sie nichts über gültige Schlüssel verrät.
	 *
	 * @param string $token    Der Schlüssel
	 * @param string $clientId Die client_id der Anfrage, leer wenn keine mitkam
	 */
	public function revoke(string $token, string $clientId): void
	{
		$hash = Settings::hashToken($token);
		$grant = $this->grants->findByHash('accessHash', $hash) ?? $this->grants->findByHash('refreshHash', $hash);

		if (null !== $grant && ('' === $clientId || $grant['clientId'] === $clientId)) {
			$this->grants->revoke((int) $grant['id']);
		}
	}

	/**
	 * Prüft einen Zugriffsschlüssel für eine Anfrage an /mcp.
	 *
	 * @param string $token Der Bearer-Schlüssel
	 * @param int    $now   Aktuelle Zeit
	 *
	 * @return AuthenticatedClient|null Der Zugang, oder null, wenn der
	 *                                  Schlüssel unbekannt, abgelaufen, widerrufen
	 *                                  oder für eine andere Ressource ausgestellt ist
	 */
	public function validateAccessToken(string $token, int $now): ?AuthenticatedClient
	{
		$grant = $this->grants->findByHash('accessHash', Settings::hashToken($token));

		if (null === $grant || '1' === (string) $grant['revoked'] || (int) $grant['accessExpires'] < $now) {
			return null;
		}

		// Ein Schlüssel für eine andere Ressource (andere Domain) gilt hier nicht
		if (!$this->endpoints->isOwnResource((string) $grant['resource'])) {
			return null;
		}

		$this->grants->touch((int) $grant['id'], $now);

		return new AuthenticatedClient((int) $grant['userId'], Scopes::split((string) $grant['scope']), (string) $grant['clientName'], (int) $grant['id']);
	}

	/**
	 * Gibt neue Schlüssel für eine Verbindung aus.
	 *
	 * @param int                       $grantId ID der Verbindung
	 * @param list<string>              $scopes  Die gewährten Scopes
	 * @param int                       $now     Aktuelle Zeit
	 * @param array<string, int|string> $extra   Weitere Spalten, die mitgeschrieben werden
	 *
	 * @return array<string, int|string> Die Token-Antwort
	 */
	private function issueTokens(int $grantId, array $scopes, int $now, array $extra = array()): array
	{
		$access = Settings::generateToken();
		$refresh = Settings::generateToken();

		$this->grants->update($grantId, $extra + array(
			'accessHash' => Settings::hashToken($access),
			'accessExpires' => $now + self::ACCESS_TTL,
			'refreshHash' => Settings::hashToken($refresh),
			'refreshExpires' => $now + self::REFRESH_TTL,
			'lastUsed' => $now,
		));

		return array(
			'access_token' => $access,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope' => implode(' ', $scopes),
		);
	}

	/**
	 * Prüft den optionalen Parameter resource (RFC 8707).
	 *
	 * @param string $requested Der Parameter aus der Anfrage, leer wenn keiner kam
	 * @param string $granted   Die bei der Bestätigung gespeicherte Ressource
	 *
	 * @throws OAuthException Mit invalid_target, wenn die Ressource abweicht
	 */
	private function assertResource(string $requested, string $granted): void
	{
		if ('' !== $requested && Endpoints::normalize($requested) !== Endpoints::normalize($granted)) {
			throw new OAuthException('invalid_target', 'Die Ressource passt nicht zur Verbindung.');
		}
	}

	/**
	 * Prüft, ob der Benutzer der Verbindung sich noch verbinden darf.
	 *
	 * @param int $userId ID des Benutzers
	 *
	 * @throws OAuthException Mit invalid_grant, damit Claude nicht weiter erneuert
	 */
	private function assertPolicy(int $userId): void
	{
		$reason = $this->policy->check($userId);

		if (null !== $reason) {
			throw new OAuthException('invalid_grant', $reason);
		}
	}
}
