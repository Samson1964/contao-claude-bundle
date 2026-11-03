<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Kleiner MCP-Client und Zähler für die Prüfskripte.
 */

$ok = 0;
$fail = 0;

/**
 * Spricht /mcp mit Handschlag nach Protokoll 2025-11-25.
 */
final class ProbeClient
{
	private ?string $session = null;

	private int $id = 0;

	/**
	 * @param string $url   Adresse von /mcp
	 * @param string $token Bearer-Schlüssel
	 */
	public function __construct(private readonly string $url, private readonly string $token)
	{
	}

	/**
	 * Baut die Sitzung auf (initialize und notifications/initialized).
	 */
	public function connect(): void
	{
		$this->session = null;
		[, $headers] = $this->post(array('jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => 'initialize', 'params' => array('protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array('name' => 'probe', 'version' => '1.0'))));

		foreach ($headers as $line) {
			if (0 === stripos($line, 'mcp-session-id:')) {
				$this->session = trim(substr($line, 15));
			}
		}

		$this->post(array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'));
	}

	/**
	 * Liefert die Namen der angebotenen Werkzeuge.
	 *
	 * @return list<string> Werkzeugnamen aus tools/list
	 */
	public function tools(): array
	{
		[$body] = $this->post(array('jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => 'tools/list'));

		return array_column(json_decode($body, true)['result']['tools'] ?? array(), 'name');
	}

	/**
	 * Ruft ein Werkzeug auf.
	 *
	 * @param string               $tool      Werkzeugname
	 * @param array<string, mixed> $arguments Argumente
	 *
	 * @return array{error: bool, text: string, data: mixed}
	 */
	public function call(string $tool, array $arguments = array()): array
	{
		[$body] = $this->post(array('jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => 'tools/call', 'params' => array('name' => $tool, 'arguments' => (object) $arguments)));
		$json = json_decode($body, true);
		$result = $json['result'] ?? null;
		$text = (string) ($result['content'][0]['text'] ?? json_encode($json['error'] ?? $json, JSON_UNESCAPED_UNICODE));

		return array('error' => (bool) ($result['isError'] ?? true), 'text' => $text, 'data' => json_decode($text, true));
	}

	/**
	 * Schickt eine JSON-RPC-Nachricht.
	 *
	 * @param array<string, mixed> $message Die Nachricht
	 *
	 * @return array{0: string, 1: list<string>} Rumpf und Antwort-Header
	 */
	private function post(array $message): array
	{
		$headers = array('Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer '.$this->token, 'MCP-Protocol-Version: 2025-11-25');

		if (null !== $this->session) {
			$headers[] = 'Mcp-Session-Id: '.$this->session;
		}

		$body = file_get_contents($this->url, false, stream_context_create(array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'ignore_errors' => true, 'timeout' => 300))));

		return array((string) $body, $http_response_header ?? array());
	}
}

/**
 * Gibt ein Prüfergebnis aus und zählt mit.
 *
 * @param bool   $passed Bestanden?
 * @param string $label  Bezeichnung
 * @param string $detail Zusatzangabe
 */
function check(bool $passed, string $label, string $detail = ''): void
{
	global $ok, $fail;

	$passed ? ++$ok : ++$fail;
	echo ($passed ? '  OK    ' : '  FEHLER ').$label.('' === $detail ? '' : ' – '.mb_substr(str_replace("\n", ' | ', $detail), 0, 220))."\n";
}
