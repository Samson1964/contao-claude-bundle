<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Prüft die Rechte beim Schreiben mit dem eingeschränkten Redakteur.
 *
 * Aufruf (der Testschlüssel muss vorher auf claude-redakteur zeigen):
 * C:/xampp/php/php.exe pruefstand/einrichten.php F:/Claude/contao-test-413 413-redakteur 990234
 * C:/xampp/php/php.exe pruefstand/schreib-probe-redakteur.php http://127.0.0.1:8160/mcp pruefstand/zugang-413-redakteur.local.md contao_test_413
 *
 * Zwei Durchgänge mit der Gruppe „Claude-Test Redaktion“:
 *
 * 1. Ohne Feldfreigaben und Dateirechte: Anlegen, Ändern und Hochladen
 *    müssen abgewiesen werden, wie im Backend.
 * 2. Mit Freigaben für Überschrift, Teaser und Text, dem Recht zum
 *    Hochladen und unter Contao 5 den Tabellenrechten für tl_news und
 *    tl_content: Anlegen klappt, aber nur im freigegebenen Archiv und nicht
 *    das Veröffentlichen (published bleibt gesperrt).
 *
 * Danach bekommt die Gruppe ihre vorherigen Rechte zurück.
 */

[, $url, $noteFile, $database] = $argv + array(null, null, null, null);

if (null === $url || null === $noteFile || null === $database || !preg_match('/^Schlüssel: (\S+)$/m', (string) file_get_contents($noteFile), $match)) {
	fwrite(STDERR, "Aufruf: schreib-probe-redakteur.php <url> <zugang-datei> <datenbank>\n");

	exit(1);
}

require __DIR__.'/mcp-client.inc.php';

$client = new ProbeClient($url, $match[1]);
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$hasElements = false !== $pdo->query("SHOW COLUMNS FROM tl_user_group LIKE 'elements'")->fetch();
// Tabellenrechte (Anlegen, Bearbeiten, Löschen) gibt es erst in Contao 5
$hasCud = false !== $pdo->query("SHOW COLUMNS FROM tl_user_group LIKE 'cud'")->fetch();
$group = $pdo->query("SELECT * FROM tl_user_group WHERE name = 'Claude-Test Redaktion'")->fetch();
$archive = (int) $pdo->query("SELECT id FROM tl_news_archive WHERE title = 'Claude-Test Nachrichten'")->fetchColumn();
$otherArchive = (int) $pdo->query('SELECT id FROM tl_news_archive WHERE id <> '.$archive.' ORDER BY id LIMIT 1')->fetchColumn();
$mount = (string) $pdo->query("SELECT path FROM tl_files WHERE type = 'folder' AND pid IS NULL ORDER BY path LIMIT 1")->fetchColumn();
$outside = (string) $pdo->query("SELECT path FROM tl_files WHERE type = 'folder' AND pid IS NULL AND path <> ".$pdo->quote($mount).' ORDER BY path LIMIT 1')->fetchColumn();
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$stamp = date('H:i:s');

/**
 * Setzt die Rechte der Testgruppe.
 *
 * @param array<string, mixed> $rights Spalte => Wert
 */
function setRights(array $rights): void
{
	global $pdo, $group;

	$sets = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($rights)));
	$pdo->prepare('UPDATE tl_user_group SET '.$sets.' WHERE id = ?')->execute(array_merge(array_values($rights), array($group['id'])));
}

try {
	echo "Durchgang 1: ohne Feldfreigaben\n";
	setRights(array('alexf' => serialize(array()), 'fop' => ''));
	$client->connect();

	$r = $client->call('news_create', array('archiveId' => $archive, 'headline' => 'Claude-Test: Redakteur '.$stamp));
	check($r['error'] && (str_contains($r['text'], 'Feldfreigabe') || str_contains($r['text'], 'Tabellenrecht')), 'news_create ohne Feld- bzw. Tabellenrecht abgewiesen', $r['text']);

	$element = (int) $pdo->query("SELECT id FROM tl_content WHERE cssID LIKE '%claude-test2%'")->fetchColumn();
	$r = $client->call('content_update', array('id' => $element, 'values' => array('text' => '<p>x</p>')));
	check($r['error'] && (str_contains($r['text'], 'Feldfreigabe') || str_contains($r['text'], 'Tabellenrecht')), 'content_update ohne Feld- bzw. Tabellenrecht abgewiesen', $r['text']);

	$r = $client->call('file_upload', array('folder' => $mount, 'filename' => 'claude-test-redakteur.png', 'contentBase64' => $png));
	check($r['error'] && str_contains($r['text'], 'hochladen'), 'file_upload ohne Recht „Dateien hochladen“ abgewiesen', $r['text']);

	$r = $client->call('record_save', array('table' => 'tl_page', 'id' => 1, 'values' => array('title' => 'x')));
	check($r['error'], 'tl_page ohne Modul „Seitenstruktur“ abgewiesen', $r['text']);

	echo "Durchgang 2: mit Freigaben\n";
	$rights = array(
		'alexf' => serialize(array('tl_news::headline', 'tl_news::teaser', 'tl_content::type', 'tl_content::text', 'tl_content::headline')),
		'fop' => serialize(array('f1')),
	);

	if ($hasElements) {
		$rights['elements'] = serialize(array('text', 'headline'));
	}

	if ($hasCud) {
		$rights['cud'] = serialize(array('tl_news::create', 'tl_news::update', 'tl_news::delete', 'tl_content::create', 'tl_content::update', 'tl_content::delete'));
	}

	setRights($rights);

	$r = $client->call('news_create', array('archiveId' => $archive, 'headline' => 'Claude-Test: Redakteur '.$stamp, 'values' => array('teaser' => '<p>Teaser</p>'), 'text' => '<p>Text vom Redakteur.</p>'));
	$newsId = (int) ($r['data']['id'] ?? 0);
	check(!$r['error'] && $newsId > 0 && isset($r['data']['contentId']), 'news_create im freigegebenen Archiv', $r['text']);

	if ($otherArchive > 0) {
		$r = $client->call('news_create', array('archiveId' => $otherArchive, 'headline' => 'Claude-Test: fremdes Archiv'));
		check($r['error'] && str_contains($r['text'], 'nicht freigegeben'), 'news_create im fremden Archiv abgewiesen', $r['text']);
	}

	$r = $client->call('publish_toggle', array('table' => 'tl_news', 'id' => $newsId, 'published' => true));
	check($r['error'] && str_contains($r['text'], 'Feldfreigabe'), 'publish_toggle ohne Freigabe für published abgewiesen', $r['text']);

	if ($hasElements) {
		$r = $client->call('content_create', array('parentTable' => 'tl_news', 'parentId' => $newsId, 'type' => 'html'));
		check($r['error'], 'Elementtyp „html“ nicht unter den erlaubten Elementen', $r['text']);
	}

	$r = $client->call('file_upload', array('folder' => $mount, 'filename' => 'claude-test-redakteur-'.date('His').'.png', 'contentBase64' => $png));
	check(!$r['error'], 'file_upload in die Dateifreigabe', $r['text']);

	if ('' !== $outside) {
		$r = $client->call('file_upload', array('folder' => $outside, 'filename' => 'claude-test-redakteur.png', 'contentBase64' => $png));
		check($r['error'] && str_contains($r['text'], 'Dateifreigaben'), 'file_upload außerhalb der Dateifreigabe abgewiesen', $r['text']);
	}

	$r = $client->call('record_delete', array('table' => 'tl_news', 'id' => $newsId, 'ignoreReferences' => true));
	check(!$r['error'] && false === $pdo->query('SELECT id FROM tl_news WHERE id = '.$newsId)->fetch(), 'eigene Nachricht gelöscht', $r['text']);

	$log = $pdo->query("SELECT COUNT(*) FROM tl_claude_log WHERE username = 'claude-redakteur' AND tstamp >= ".(time() - 600))->fetchColumn();
	check((int) $log >= 8, 'Protokoll führt den Redakteur', $log.' Einträge');
} finally {
	$restore = array('alexf' => $group['alexf'], 'fop' => $group['fop']);

	if ($hasElements) {
		$restore['elements'] = $group['elements'];
	}

	if ($hasCud) {
		$restore['cud'] = $group['cud'];
	}

	setRights($restore);
	echo "Rechte der Gruppe zurückgesetzt\n";
}

echo "\n$ok bestanden, $fail fehlgeschlagen\n";

exit($fail > 0 ? 1 : 0);
