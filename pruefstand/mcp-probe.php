<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Spricht /mcp wie ein MCP-Client an und gibt die Antworten knapp aus.
 *
 * Prüft die Fehlerfälle (kein Schlüssel, falscher Schlüssel, GET), den
 * Handschlag nach Protokoll 2025-11-25 mit Mcp-Session-Id, tools/list und
 * jeden Werkzeugaufruf aus der Datei mit den Proben.
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/mcp-probe.php http://127.0.0.1:8160/mcp pruefstand/zugang-413.local.md pruefstand/mcp-probe-413.local.json [werkzeug]
 *
 * Zugangsdatei und Proben erzeugen einrichten.php und testdaten.php. Mit dem
 * optionalen vierten Argument laufen nur die Proben dieses Werkzeugs, und
 * ihre Antworten werden vollständig ausgegeben.
 */

[, $url, $noteFile, $probeFile] = $argv + array(null, null, null, null);
$only = $argv[4] ?? null;

if (null === $url || null === $noteFile || null === $probeFile || !preg_match('/^Schlüssel: (\S+)$/m', (string) file_get_contents($noteFile), $match)) {
	fwrite(STDERR, "Aufruf: mcp-probe.php <url> <zugang-datei> <proben-datei> [werkzeug]\n");

	exit(1);
}

$token = $match[1];

/**
 * Schickt eine Anfrage und liefert Status, Header und Rumpf.
 *
 * @param string               $method  HTTP-Methode
 * @param string               $url     Adresse
 * @param array<string>        $headers Zusätzliche Header
 * @param string|null          $body    Rumpf
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function send(string $method, string $url, array $headers = array(), ?string $body = null): array
{
	$context = stream_context_create(array('http' => array(
		'method' => $method,
		'header' => implode("\r\n", array_merge(array('Content-Type: application/json', 'Accept: application/json, text/event-stream'), $headers)),
		'content' => $body ?? '',
		'ignore_errors' => true,
		'timeout' => 60,
	)));

	$response = file_get_contents($url, false, $context);
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

	return array('status' => $status, 'headers' => $parsed, 'body' => (string) $response);
}

/**
 * Schickt eine JSON-RPC-Nachricht mit Schlüssel und Sitzung.
 *
 * @param string               $url     Adresse
 * @param string               $token   Bearer-Schlüssel
 * @param string|null          $session Mcp-Session-Id
 * @param array<string, mixed> $message Die Nachricht
 *
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function rpc(string $url, string $token, ?string $session, array $message): array
{
	$headers = array('Authorization: Bearer '.$token, 'MCP-Protocol-Version: 2025-11-25');

	if (null !== $session) {
		$headers[] = 'Mcp-Session-Id: '.$session;
	}

	$result = send('POST', $url, $headers, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	$result['json'] = json_decode($result['body'], true);

	return $result;
}

$ok = 0;
$fail = 0;

/**
 * Gibt ein Prüfergebnis aus und zählt mit.
 *
 * @param bool   $passed Bestanden?
 * @param string $label  Bezeichnung der Probe
 * @param string $detail Zusatzangabe
 */
function check(bool $passed, string $label, string $detail = ''): void
{
	global $ok, $fail;

	$passed ? ++$ok : ++$fail;
	echo ($passed ? '  OK    ' : '  FEHLER ').$label.('' === $detail ? '' : ' – '.$detail)."\n";
}

if (null === $only) {
	echo "Fehlerfälle\n";

	$r = send('POST', $url, array(), '{"jsonrpc":"2.0","id":1,"method":"ping"}');
	check(401 === $r['status'] && str_starts_with($r['headers']['www-authenticate'] ?? '', 'Bearer'), 'ohne Schlüssel → 401', $r['status'].' '.($r['headers']['www-authenticate'] ?? ''));

	$r = send('POST', $url, array('Authorization: Bearer falsch'), '{"jsonrpc":"2.0","id":1,"method":"ping"}');
	check(401 === $r['status'] && str_contains($r['headers']['www-authenticate'] ?? '', 'invalid_token'), 'falscher Schlüssel → 401 invalid_token', (string) $r['status']);

	$r = send('GET', $url, array('Authorization: Bearer '.$token));
	check(405 === $r['status'], 'GET → 405', (string) $r['status']);

	check(str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'Cache-Control no-store', $r['headers']['cache-control'] ?? '-');
}

echo "Handschlag\n";

$init = rpc($url, $token, null, array(
	'jsonrpc' => '2.0',
	'id' => 1,
	'method' => 'initialize',
	'params' => array(
		'protocolVersion' => '2025-11-25',
		'capabilities' => new stdClass(),
		'clientInfo' => array('name' => 'mcp-probe', 'version' => '1.0'),
	),
));

$session = $init['headers']['mcp-session-id'] ?? null;
check(200 === $init['status'] && isset($init['json']['result']['protocolVersion']), 'initialize', $init['status'].' Protokoll '.($init['json']['result']['protocolVersion'] ?? '?').', Sitzung '.($session ? 'ja' : 'nein'));

if (null === $only) {
	echo '  Anweisungen: '.str_replace("\n", ' | ', (string) ($init['json']['result']['instructions'] ?? '–'))."\n";
}

$note = rpc($url, $token, $session, array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'));
check(202 === $note['status'], 'notifications/initialized → 202', (string) $note['status']);

$list = rpc($url, $token, $session, array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'));
$names = array_column($list['json']['result']['tools'] ?? array(), 'name');
check(12 === \count($names), 'tools/list', implode(', ', $names));

$probes = json_decode((string) file_get_contents($probeFile), true);
$id = 10;

echo "Werkzeuge\n";

foreach ($probes as $probe) {
	if (null !== $only && $probe['tool'] !== $only) {
		continue;
	}

	$call = rpc($url, $token, $session, array(
		'jsonrpc' => '2.0',
		'id' => ++$id,
		'method' => 'tools/call',
		'params' => array('name' => $probe['tool'], 'arguments' => (object) ($probe['arguments'] ?? array())),
	));

	$result = $call['json']['result'] ?? null;
	$isError = (bool) ($result['isError'] ?? false);
	$text = (string) ($result['content'][0]['text'] ?? json_encode($call['json']['error'] ?? $call['body'], JSON_UNESCAPED_UNICODE));
	$expectError = (bool) ($probe['expectError'] ?? false);

	check(200 === $call['status'] && null !== $result && $isError === $expectError, $probe['label'] ?? $probe['tool'], ($isError ? 'isError: ' : '').mb_substr(str_replace("\n", ' ', $text), 0, null === $only ? 160 : null));
}

echo "\n$ok bestanden, $fail fehlgeschlagen\n";

exit($fail > 0 ? 1 : 0);
