<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Prüft den Endpunkt mit dem Client des offiziellen PHP-SDK, einmal in der
 * zustandslosen Fassung 2026-07-28 und einmal in 2025-11-25.
 *
 * Damit ist auch die neuere Protokollfassung abgedeckt, deren Nachrichten
 * mcp-probe.php nicht nachbaut.
 *
 * Aufruf (aus dem Bundle-Verzeichnis, braucht vendor/):
 * C:/xampp/php/php.exe pruefstand/sdk-client.php http://127.0.0.1:8160/mcp pruefstand/zugang-413.local.md
 */

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;

require __DIR__.'/../vendor/autoload.php';

[, $url, $noteFile] = $argv + array(null, null, null);

if (null === $url || null === $noteFile || !preg_match('/^Schlüssel: (\S+)$/m', (string) file_get_contents($noteFile), $match)) {
	fwrite(STDERR, "Aufruf: sdk-client.php <url> <zugang-datei>\n");

	exit(1);
}

$failed = false;

foreach (array(ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_11_25) as $version) {
	echo "Protokoll {$version->value}\n";

	try {
		$client = Client::builder()
			->setClientInfo('sdk-client-pruefung', '1.0')
			->setProtocolVersion($version)
			->build();

		$client->connect(new HttpTransport($url, array('Authorization' => 'Bearer '.$match[1])));

		$tools = $client->listTools();
		echo '  tools/list: '.\count($tools->tools)." Werkzeuge\n";

		$info = $client->callTool('site_info', array());
		$text = $info->content[0]->text ?? '';
		$data = json_decode($text, true);
		echo '  site_info: Contao '.($data['installation']['contao'] ?? '?').', Benutzer '.($data['user']['username'] ?? '?').($info->isError ? ' (isError)' : '')."\n";

		$denied = $client->callTool('record_list', array('table' => 'tl_user'));
		echo '  Sperrliste: '.($denied->isError ? 'abgewiesen' : 'NICHT abgewiesen')."\n";

		$failed = $failed || $info->isError || !$denied->isError || 12 !== \count($tools->tools);

		$client->disconnect();
	} catch (\Throwable $exception) {
		$failed = true;
		echo '  FEHLER: '.\get_class($exception).': '.$exception->getMessage()."\n";
	}
}

exit($failed ? 1 : 0);
