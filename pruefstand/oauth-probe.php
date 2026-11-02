<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Spielt die OAuth-Anmeldung so durch, wie Claude Code sie macht.
 *
 * 1. /mcp ohne Schlüssel → 401 mit resource_metadata
 * 2. Metadaten der Ressource und des Autorisierungsservers lesen
 * 3. PKCE erzeugen, Bestätigungsadresse ausgeben (Datei + Konsole)
 * 4. Auf dem eigenen Rechner auf den Rücksprung warten (Browser klickt „Erlauben“)
 * 5. Code tauschen, /mcp mit dem Zugriffsschlüssel nutzen
 * 6. Erneuern (Rotation), widerrufen, Gegenproben
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/oauth-probe.php http://127.0.0.1:8160 [verbindung] [port]
 *   1. Argument: Adresse der Installation, wie Claude sie sieht
 *   2. Argument: Adresse für die Verbindung, wenn PHP den Host nicht auflöst
 *      (etwa „127.0.0.1:8161“ für http://pruef.localhost:8161)
 *   3. Argument: Port für den Rücksprung, Vorgabe 53682
 *   4. Argument: „behalten“ lässt die Verbindung am Ende bestehen (für den
 *      Widerruf im Backend); dann entfallen Widerruf und Doppeltausch
 *
 * Die Bestätigungsadresse steht danach in pruefstand/oauth-url.local.txt;
 * das Skript wartet bis zu fünf Minuten auf den Rücksprung.
 */

[, $base, $connect, $port, $keep] = $argv + array(null, null, null, '53682', '');

if (null === $base) {
	fwrite(STDERR, "Aufruf: oauth-probe.php <adresse> [verbindung] [port]\n");

	exit(1);
}

$base = rtrim($base, '/');
$host = (string) parse_url($base, PHP_URL_HOST).(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');
$connect = null === $connect || '' === $connect ? $host : $connect;
$clientId = 'https://claude.ai/oauth/claude-code-client-metadata';
$redirectUri = 'http://127.0.0.1:'.$port.'/callback';
$fail = 0;

/**
 * Schickt eine Anfrage, auf Wunsch an eine andere Verbindungsadresse.
 *
 * @param string        $method  HTTP-Methode
 * @param string        $url     Adresse, wie Claude sie sieht
 * @param array<string> $headers Zusätzliche Header
 * @param string        $body    Rumpf
 *
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function call(string $method, string $url, array $headers = array(), string $body = ''): array
{
	global $host, $connect;

	// ${1} statt $1: Sonst liest PCRE „$1127.0.0.1“ als Rückverweis 11
	$target = preg_replace('#^(https?://)'.preg_quote($host, '#').'#', '${1}'.$connect, $url);
	$context = stream_context_create(array('http' => array(
		'method' => $method,
		'header' => implode("\r\n", array_merge(array('Host: '.$host), $headers)),
		'content' => $body,
		'ignore_errors' => true,
		'timeout' => 60,
	)));

	$response = (string) @file_get_contents($target, false, $context);
	$status = 0;
	$parsed = array();

	foreach ($http_response_header ?? array() as $line) {
		if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
			$status = (int) $m[1];
		} elseif (str_contains($line, ':')) {
			[$name, $value] = explode(':', $line, 2);
			$parsed[strtolower(trim($name))] = trim($value);
		}
	}

	return array('status' => $status, 'headers' => $parsed, 'body' => $response, 'json' => json_decode($response, true));
}

/**
 * Gibt ein Prüfergebnis aus und zählt Fehlschläge.
 *
 * @param bool   $passed Bestanden?
 * @param string $label  Bezeichnung
 * @param string $detail Zusatzangabe
 */
function check(bool $passed, string $label, string $detail = ''): void
{
	global $fail;

	$passed || ++$fail;
	echo ($passed ? '  OK    ' : '  FEHLER ').$label.('' === $detail ? '' : ' – '.$detail)."\n";
}

/**
 * Schickt eine JSON-RPC-Nachricht an /mcp.
 *
 * @param string               $token   Zugriffsschlüssel
 * @param array<string, mixed> $message Die Nachricht
 * @param string|null          $session Mcp-Session-Id
 *
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function mcp(string $token, array $message, ?string $session = null): array
{
	global $base;

	$headers = array('Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-11-25', 'Authorization: Bearer '.$token);

	if (null !== $session) {
		$headers[] = 'Mcp-Session-Id: '.$session;
	}

	return call('POST', $base.'/mcp', $headers, (string) json_encode($message));
}

/**
 * Schickt Formularfelder an einen OAuth-Endpunkt.
 *
 * @param string                $url    Adresse
 * @param array<string, string> $fields Felder
 *
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function form(string $url, array $fields): array
{
	return call('POST', $url, array('Content-Type: application/x-www-form-urlencoded'), http_build_query($fields));
}

echo "Entdeckung\n";

$challenge401 = call('POST', $base.'/mcp', array('Content-Type: application/json'), '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
$wwwAuth = $challenge401['headers']['www-authenticate'] ?? '';
check(401 === $challenge401['status'] && preg_match('/resource_metadata="([^"]+)"/', $wwwAuth, $m) === 1, '401 mit resource_metadata', $wwwAuth);
$prmUrl = $m[1] ?? $base.'/.well-known/oauth-protected-resource/mcp';

$prm = call('GET', $prmUrl);
check(200 === $prm['status'] && ($prm['json']['resource'] ?? '') === $base.'/mcp', 'Metadaten der Ressource', (string) ($prm['json']['resource'] ?? $prm['status']));
$issuer = (string) ($prm['json']['authorization_servers'][0] ?? $base);

$as = call('GET', $issuer.'/.well-known/oauth-authorization-server');
$meta = $as['json'] ?? array();
check(200 === $as['status'] && true === ($meta['client_id_metadata_document_supported'] ?? false) && \in_array('none', $meta['token_endpoint_auth_methods_supported'] ?? array(), true) && \in_array('S256', $meta['code_challenge_methods_supported'] ?? array(), true), 'Metadaten des Autorisierungsservers (CIMD, none, S256)', (string) ($meta['issuer'] ?? $as['status']));

$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(8));

$authorizeUrl = $meta['authorization_endpoint'].'?'.http_build_query(array(
	'response_type' => 'code',
	'client_id' => $clientId,
	'redirect_uri' => $redirectUri,
	'code_challenge' => $challenge,
	'code_challenge_method' => 'S256',
	'state' => $state,
	'scope' => 'contao:read',
	'resource' => $base.'/mcp',
), '', '&', PHP_QUERY_RFC3986);

file_put_contents(__DIR__.'/oauth-url.local.txt', $authorizeUrl);
echo "\nBestätigung im Browser öffnen:\n$authorizeUrl\n\nWarte auf den Rücksprung an $redirectUri …\n";

$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);

if (false === $server) {
	fwrite(STDERR, "Port $port nicht frei: $errstr\n");

	exit(1);
}

$connection = @stream_socket_accept($server, 300);

if (false === $connection) {
	fwrite(STDERR, "Kein Rücksprung innerhalb von fünf Minuten.\n");

	exit(1);
}

$requestLine = (string) fgets($connection);
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: close\r\n\r\n<p>Prüfskript: Rücksprung empfangen, das Fenster kann zu.</p>");
fclose($connection);
fclose($server);

preg_match('#^GET (\S+)#', $requestLine, $m);
parse_str((string) parse_url($m[1] ?? '', PHP_URL_QUERY), $query);

echo "\nRücksprung\n";
check(($query['state'] ?? '') === $state, 'state unverändert zurück');
check(($query['iss'] ?? '') === $issuer, 'iss nennt den Herausgeber', (string) ($query['iss'] ?? '–'));

if (isset($query['error'])) {
	echo '  Antwort: error='.$query['error'].' ('.($query['error_description'] ?? '').")\n";
	echo "\n$fail Fehlschläge\n";

	exit('access_denied' === $query['error'] ? 2 : 1);
}

check(isset($query['code']), 'Code erhalten');

echo "\nSchlüsseltausch\n";

$token = form($meta['token_endpoint'], array('grant_type' => 'authorization_code', 'code' => (string) $query['code'], 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier, 'resource' => $base.'/mcp'));
$tokens = $token['json'] ?? array();
check(200 === $token['status'] && isset($tokens['access_token'], $tokens['refresh_token']), 'authorization_code → Schlüssel', $token['status'].' '.($tokens['scope'] ?? $token['body']));
check(str_contains($token['headers']['cache-control'] ?? '', 'no-store'), 'Token-Antwort no-store');

$access = (string) ($tokens['access_token'] ?? '');
$refresh = (string) ($tokens['refresh_token'] ?? '');

echo "\nMCP mit Zugriffsschlüssel\n";

$init = mcp($access, array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array('protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array('name' => 'oauth-probe', 'version' => '1'))));
$session = $init['headers']['mcp-session-id'] ?? null;
check(200 === $init['status'], 'initialize', (string) ($init['json']['result']['protocolVersion'] ?? $init['status']));
mcp($access, array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'), $session);

$info = mcp($access, array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array('name' => 'site_info', 'arguments' => new stdClass())), $session);
$infoData = json_decode((string) ($info['json']['result']['content'][0]['text'] ?? ''), true);
check(200 === $info['status'] && isset($infoData['user']['username']), 'site_info', 'Benutzer '.($infoData['user']['username'] ?? '?').', Zugang '.($infoData['access']['client'] ?? '?').', Scopes '.implode(' ', $infoData['access']['scopes'] ?? array()));

echo "\nErneuerung\n";

$renewed = form($meta['token_endpoint'], array('grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId));
$newTokens = $renewed['json'] ?? array();
check(200 === $renewed['status'] && isset($newTokens['access_token']) && $newTokens['refresh_token'] !== $refresh, 'refresh_token → neue Schlüssel');
check(401 === mcp($access, array('jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'), $session)['status'], 'alter Zugriffsschlüssel abgewiesen');
check(200 === mcp((string) ($newTokens['access_token'] ?? ''), array('jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'), $session)['status'], 'neuer Zugriffsschlüssel gilt');

$oldRefresh = form($meta['token_endpoint'], array('grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId));
check(400 === $oldRefresh['status'] && 'invalid_grant' === ($oldRefresh['json']['error'] ?? ''), 'alter Erneuerungsschlüssel → invalid_grant');

if ('behalten' === $keep) {
	echo "\nVerbindung bleibt bestehen (Widerruf im Backend prüfen).\n\n$fail Fehlschläge\n";

	exit($fail > 0 ? 1 : 0);
}

echo "\nWiderruf\n";

$revoke = form($meta['revocation_endpoint'], array('token' => (string) ($newTokens['refresh_token'] ?? ''), 'client_id' => $clientId));
check(200 === $revoke['status'], 'Widerruf');
check(401 === mcp((string) ($newTokens['access_token'] ?? ''), array('jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping'), $session)['status'], 'Zugriffsschlüssel nach Widerruf abgewiesen');

$again = form($meta['token_endpoint'], array('grant_type' => 'authorization_code', 'code' => (string) $query['code'], 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier));
check(400 === $again['status'] && 'invalid_grant' === ($again['json']['error'] ?? ''), 'eingelöster Code ein zweites Mal abgewiesen');

echo "\n$fail Fehlschläge\n";

exit($fail > 0 ? 1 : 0);
