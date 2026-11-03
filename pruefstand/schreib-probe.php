<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Spielt die schreibenden Werkzeuge durch und prüft jedes Ergebnis direkt
 * in der Datenbank.
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/schreib-probe.php http://127.0.0.1:8160/mcp pruefstand/zugang-413.local.md contao_test_413
 *
 * Voraussetzungen: testdaten.php ist gelaufen (Archiv „Claude-Test
 * Nachrichten“), der Testschlüssel gehört einem Administrator, und
 * schalter.php hat Schreiben, Veröffentlichen und Löschen eingeschaltet.
 *
 * Alles, was das Skript anlegt, heißt „Claude-Test: Schreibprobe …“ und
 * wird am Ende über record_delete wieder gelöscht (liegt danach in
 * tl_undo). Nur die hochgeladene Testdatei bleibt in der Dateiverwaltung.
 */

[, $url, $noteFile, $database] = $argv + array(null, null, null, null);

if (null === $url || null === $noteFile || null === $database || !preg_match('/^Schlüssel: (\S+)$/m', (string) file_get_contents($noteFile), $match)) {
	fwrite(STDERR, "Aufruf: schreib-probe.php <url> <zugang-datei> <datenbank>\n");

	exit(1);
}

$token = $match[1];
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$ok = 0;
$fail = 0;
$callId = 0;

/**
 * Ruft ein Werkzeug zustandslos auf (MCP 2026-07-28 braucht keinen
 * Handschlag; der SDK-Server nimmt tools/call auch mit 2025-11-25 ohne
 * Sitzung nicht an, deshalb hier mit Sitzung aus init()).
 *
 * @param string               $tool      Werkzeugname
 * @param array<string, mixed> $arguments Argumente
 *
 * @return array{error: bool, text: string, data: mixed}
 */
function call(string $tool, array $arguments = array()): array
{
	global $url, $token, $session, $callId;

	$body = json_encode(array('jsonrpc' => '2.0', 'id' => ++$callId, 'method' => 'tools/call', 'params' => array('name' => $tool, 'arguments' => (object) $arguments)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$json = json_decode(post($body), true);
	$result = $json['result'] ?? null;
	$text = (string) ($result['content'][0]['text'] ?? json_encode($json['error'] ?? $json, JSON_UNESCAPED_UNICODE));

	return array('error' => (bool) ($result['isError'] ?? true), 'text' => $text, 'data' => json_decode($text, true));
}

/**
 * Schickt einen JSON-RPC-Rumpf an /mcp.
 *
 * @param string $body Der Rumpf
 *
 * @return string Die Antwort
 */
function post(string $body): string
{
	global $url, $token, $session, $headersOut;

	$headers = array('Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer '.$token, 'MCP-Protocol-Version: 2025-11-25');

	if (null !== $session) {
		$headers[] = 'Mcp-Session-Id: '.$session;
	}

	$response = file_get_contents($url, false, stream_context_create(array('http' => array('method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true, 'timeout' => 120))));
	$headersOut = $http_response_header ?? array();

	return (string) $response;
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

/**
 * Liest eine Zeile aus der Datenbank.
 *
 * @param string     $sql    Abfrage
 * @param list<mixed> $params Werte
 *
 * @return array<string, mixed>|false Die Zeile
 */
function row(string $sql, array $params = array())
{
	global $pdo;

	$statement = $pdo->prepare($sql);
	$statement->execute($params);

	return $statement->fetch();
}

// Handschlag
$session = null;
post(json_encode(array('jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => array('protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array('name' => 'schreib-probe', 'version' => '1.0')))));

foreach ($headersOut as $line) {
	if (0 === stripos($line, 'mcp-session-id:')) {
		$session = trim(substr($line, 15));
	}
}

post('{"jsonrpc":"2.0","method":"notifications/initialized"}');

$list = json_decode(post('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'), true);
$names = array_column($list['result']['tools'] ?? array(), 'name');

echo "Werkzeugliste\n";
$writeTools = array('content_create', 'content_update', 'news_create', 'news_update', 'record_save', 'record_restore', 'publish_toggle', 'record_delete', 'file_upload', 'file_meta_update');
check(array() === array_diff($writeTools, $names), 'tools/list mit allen zehn schreibenden Werkzeugen', count($names).' Werkzeuge');

$archive = (int) row("SELECT id FROM tl_news_archive WHERE title = 'Claude-Test Nachrichten'")['id'];
$stamp = date('H:i:s');
$email = '{{email::presse@example.org}}';

echo "Nachricht anlegen\n";
$r = call('news_create', array(
	'archiveId' => $archive,
	'headline' => 'Claude-Test: Schreibprobe '.$stamp,
	'values' => array('teaser' => '<p>Teaser mit '.$email.'</p>', 'date' => '2026-11-03', 'time' => '2026-11-03 18:30'),
	'text' => '<p>Text mit <strong>Max Mustermann</strong> und '.$email.'.</p><script>alert(1)</script>',
));
$newsId = (int) ($r['data']['id'] ?? 0);
$contentId = (int) ($r['data']['contentId'] ?? 0);
check(!$r['error'] && $newsId > 0 && $contentId > 0, 'news_create mit Textelement', $r['text']);

$news = row('SELECT * FROM tl_news WHERE id = ?', array($newsId)) ?: array();
check(isset($news['id']) && !$news['published'], 'Nachricht unveröffentlicht', 'published='.var_export($news['published'] ?? null, true));
check(str_contains((string) ($news['teaser'] ?? ''), $email), 'Insert-Tag im Teaser unverändert', (string) ($news['teaser'] ?? ''));
check('' !== (string) ($news['alias'] ?? ''), 'Alias erzeugt (save_callback ohne übergebenen Wert)', (string) ($news['alias'] ?? ''));
// Contao setzt date beim Speichern aus Datum und Uhrzeit zusammen
check((int) ($news['date'] ?? 0) === mktime(18, 30, 0, 11, 3, 2026), 'Datum und Uhrzeit aus ISO umgewandelt', date('Y-m-d H:i', (int) ($news['date'] ?? 0)));
check((int) ($news['tstamp'] ?? 0) > 0, 'tstamp gesetzt', (string) ($news['tstamp'] ?? ''));
check((int) ($news['author'] ?? 0) > 0, 'Autor vorbelegt', (string) ($news['author'] ?? ''));

$content = row('SELECT * FROM tl_content WHERE id = ?', array($contentId)) ?: array();
check('tl_news' === ($content['ptable'] ?? '') && (int) ($content['pid'] ?? 0) === $newsId, 'Textelement unter der Nachricht', ($content['ptable'] ?? '?').'.'.($content['pid'] ?? '?'));
check(!($content['invisible'] ?? true), 'Element sichtbar (Nachricht unveröffentlicht)', 'invisible='.var_export($content['invisible'] ?? null, true));
check(str_contains((string) ($content['text'] ?? ''), $email) && str_contains((string) ($content['text'] ?? ''), '<strong>'), 'Insert-Tag und <strong> im Text', (string) ($content['text'] ?? ''));
check(!str_contains((string) ($content['text'] ?? ''), '<script'), '<script> unschädlich wie im Backend', (string) ($content['text'] ?? ''));

echo "Inhaltselemente\n";
$r = call('content_create', array('parentTable' => 'tl_news', 'parentId' => $newsId, 'type' => 'headline', 'values' => array('headline' => array('value' => 'Zwischentitel', 'unit' => 'h2')), 'position' => 'start'));
$headlineId = (int) ($r['data']['id'] ?? 0);
check(!$r['error'] && $headlineId > 0, 'content_create headline am Anfang', $r['text']);
$sorting = row('SELECT (SELECT sorting FROM tl_content WHERE id = ?) < (SELECT sorting FROM tl_content WHERE id = ?) AS before_text', array($headlineId, $contentId));
check((bool) ($sorting['before_text'] ?? false), 'Überschrift steht vor dem Text');
check(str_contains((string) (row('SELECT headline FROM tl_content WHERE id = ?', array($headlineId))['headline'] ?? ''), 'Zwischentitel'), 'inputUnit gespeichert');

$versionsBefore = (int) row("SELECT COUNT(*) AS n FROM tl_version WHERE fromTable = 'tl_content' AND pid = ?", array($contentId))['n'];
$r = call('content_update', array('id' => $contentId, 'values' => array('text' => '<p>Geänderter Text.</p>')));
check(!$r['error'] && str_contains((string) row('SELECT text FROM tl_content WHERE id = ?', array($contentId))['text'], 'Geänderter Text'), 'content_update Text', $r['text']);
$versionsAfter = (int) row("SELECT COUNT(*) AS n FROM tl_version WHERE fromTable = 'tl_content' AND pid = ?", array($contentId))['n'];
check($versionsAfter > $versionsBefore, 'Version angelegt', $versionsBefore.' → '.$versionsAfter);

$r = call('content_update', array('id' => $contentId, 'values' => array('invisible' => true)));
check($r['error'] && str_contains($r['text'], 'publish_toggle'), 'invisible über content_update abgewiesen', $r['text']);
$r = call('content_update', array('id' => $contentId, 'values' => array('gibtsnicht' => 'x')));
check($r['error'] && str_contains($r['text'], 'gibtsnicht'), 'unbekanntes Feld abgewiesen', $r['text']);
$r = call('content_update', array('id' => $headlineId, 'values' => array('text' => '<p>x</p>')));
check($r['error'] && str_contains($r['text'], 'Palette'), 'Feld außerhalb der Palette abgewiesen', $r['text']);
$r = call('content_update', array('id' => $contentId, 'values' => array('type' => 'gibtsnicht')));
check($r['error'] && str_contains($r['text'], 'type: ') && !str_ends_with(trim($r['text']), 'type:'), 'unbekannter Elementtyp abgewiesen, mit Meldung', $r['text']);
$r = call('news_update', array('id' => $newsId, 'values' => array('date' => 'irgendwann', 'teaser' => '<p>soll nicht bleiben</p>')));
$teaser = (string) row('SELECT teaser FROM tl_news WHERE id = ?', array($newsId))['teaser'];
check($r['error'] && !str_contains($teaser, 'soll nicht bleiben'), 'ungültiges Datum abgewiesen, nichts gespeichert (Transaktion)', $r['text']);
$r = call('news_update', array('id' => $newsId, 'values' => array('headline' => '')));
check($r['error'] && str_contains($r['text'], 'headline'), 'leeres Pflichtfeld abgewiesen', $r['text']);

echo "Version wiederherstellen\n";
$first = (int) (row("SELECT MIN(version) AS v FROM tl_version WHERE fromTable = 'tl_content' AND pid = ?", array($contentId))['v'] ?? 0);
$r = call('record_restore', array('table' => 'tl_content', 'id' => $contentId, 'version' => $first));
check(!$r['error'] && str_contains((string) row('SELECT text FROM tl_content WHERE id = ?', array($contentId))['text'], 'Max Mustermann'), 'record_restore auf Version '.$first, $r['text']);

echo "record_save\n";
$r = call('record_save', array('table' => 'tl_news', 'id' => $newsId, 'values' => array('subheadline' => 'Unterzeile '.$stamp)));
check(!$r['error'] && str_contains((string) row('SELECT subheadline FROM tl_news WHERE id = ?', array($newsId))['subheadline'], $stamp), 'record_save ändert tl_news', $r['text']);
$r = call('record_save', array('table' => 'tl_user', 'id' => 1, 'values' => array('name' => 'x')));
check($r['error'] && str_contains($r['text'], 'gesperrt'), 'tl_user gesperrt', $r['text']);
$r = call('record_save', array('table' => 'tl_files', 'values' => array('name' => 'x')));
check($r['error'] && str_contains($r['text'], 'file_upload'), 'tl_files nur über die Dateiwerkzeuge', $r['text']);

echo "Veröffentlichen\n";
$r = call('publish_toggle', array('table' => 'tl_news', 'id' => $newsId, 'published' => true));
check(!$r['error'] && (bool) row('SELECT published FROM tl_news WHERE id = ?', array($newsId))['published'], 'publish_toggle Nachricht an', $r['text']);
$r = call('publish_toggle', array('table' => 'tl_news', 'id' => $newsId, 'published' => false));
check(!$r['error'] && !row('SELECT published FROM tl_news WHERE id = ?', array($newsId))['published'], 'publish_toggle Nachricht aus', $r['text']);
$r = call('publish_toggle', array('table' => 'tl_content', 'id' => $headlineId, 'published' => false));
check(!$r['error'] && (bool) row('SELECT invisible FROM tl_content WHERE id = ?', array($headlineId))['invisible'], 'publish_toggle Element versteckt (invisible)', $r['text']);

echo "Inhaltselement in einem veröffentlichten Artikel\n";
$article = row("SELECT a.id FROM tl_article a JOIN tl_news_archive n ON n.jumpTo = a.pid WHERE n.id = ? AND a.published = '1' ORDER BY a.id LIMIT 1", array($archive));

if (false === $article) {
	$article = row("SELECT id FROM tl_article WHERE published = '1' ORDER BY id LIMIT 1");
}

$r = call('content_create', array('parentTable' => 'tl_article', 'parentId' => (int) $article['id'], 'type' => 'text', 'values' => array('text' => '<p>Claude-Test: Schreibprobe '.$stamp.'</p>')));
$articleContentId = (int) ($r['data']['id'] ?? 0);
$articleContent = row('SELECT * FROM tl_content WHERE id = ?', array($articleContentId)) ?: array();
check(!$r['error'] && (bool) ($articleContent['invisible'] ?? false) && 'tl_article' === ($articleContent['ptable'] ?? ''), 'Element im Artikel '.$article['id'].' entsteht versteckt', $r['text']);

echo "Dateien\n";
$folder = (string) row("SELECT path FROM tl_files WHERE type = 'folder' AND pid IS NULL ORDER BY path LIMIT 1")['path'];
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$file = 'claude-test-schreibprobe-'.date('His').'.png';
$r = call('file_upload', array('folder' => $folder, 'filename' => $file, 'contentBase64' => $png, 'title' => 'Claude-Test Bild', 'alt' => 'Ein Pixel'));
$uploaded = row('SELECT * FROM tl_files WHERE path = ?', array($folder.'/'.$file));
check(!$r['error'] && false !== $uploaded && str_contains((string) $uploaded['meta'], 'Ein Pixel'), 'file_upload mit Metadaten', $r['text']);
$r = call('file_upload', array('folder' => $folder, 'filename' => $file, 'contentBase64' => $png));
check($r['error'] && str_contains($r['text'], 'gibt es schon'), 'vorhandene Datei nicht überschrieben', $r['text']);
$r = call('file_upload', array('folder' => $folder, 'filename' => 'x.svg', 'contentBase64' => base64_encode('<svg/>')));
check($r['error'], 'SVG abgewiesen', $r['text']);
$r = call('file_upload', array('folder' => $folder, 'filename' => 'x.png', 'url' => 'https://127.0.0.1/x.png'));
check($r['error'], 'Adresse im eigenen Netz abgewiesen', $r['text']);
$r = call('file_upload', array('folder' => 'system/config', 'filename' => 'x.png', 'contentBase64' => $png));
check($r['error'] && str_contains($r['text'], 'files'), 'Ordner außerhalb von files abgewiesen', $r['text']);
$r = call('file_meta_update', array('path' => $folder.'/'.$file, 'language' => 'de', 'meta' => array('caption' => 'Bildunterschrift '.$stamp)));
check(!$r['error'] && str_contains((string) row('SELECT meta FROM tl_files WHERE path = ?', array($folder.'/'.$file))['meta'], 'Bildunterschrift'), 'file_meta_update', $r['text']);
$r = call('content_update', array('id' => $headlineId, 'values' => array('type' => 'image', 'singleSRC' => $folder.'/'.$file)));
$image = row('SELECT type, HEX(singleSRC) AS uuid FROM tl_content WHERE id = ?', array($headlineId)) ?: array();
check(!$r['error'] && 'image' === ($image['type'] ?? '') && strtoupper(bin2hex((string) $uploaded['uuid'])) === ($image['uuid'] ?? ''), 'Typwechsel zu image mit Dateipfad (UUID gespeichert)', $r['text']);

echo "Löschen\n";
$r = call('record_delete', array('table' => 'tl_news', 'id' => $newsId));
check($r['error'] && str_contains($r['text'], 'mitgelöscht'), 'Nachricht mit Elementen: erst Rückfrage', $r['text']);
$r = call('record_delete', array('table' => 'tl_content', 'id' => $articleContentId));
check(!$r['error'] && false === row('SELECT id FROM tl_content WHERE id = ?', array($articleContentId)), 'Element im Artikel gelöscht', $r['text']);
$r = call('record_delete', array('table' => 'tl_news', 'id' => $newsId, 'ignoreReferences' => true));
check(!$r['error'] && false === row('SELECT id FROM tl_news WHERE id = ?', array($newsId)) && false === row('SELECT id FROM tl_content WHERE id = ?', array($contentId)), 'Nachricht samt Elementen gelöscht', $r['text']);
$undo = row("SELECT * FROM tl_undo WHERE fromTable = 'tl_news' ORDER BY id DESC LIMIT 1");
check(false !== $undo && str_contains((string) $undo['query'], 'id='.$newsId), 'tl_undo-Eintrag vorhanden', (string) ($undo['query'] ?? '-'));

echo "Protokoll\n";
$log = $pdo->query('SELECT result, COUNT(*) AS n FROM tl_claude_log WHERE tstamp >= '.(time() - 600).' GROUP BY result')->fetchAll(PDO::FETCH_KEY_PAIR);
check(($log['ok'] ?? 0) > 10 && ($log['abgewiesen'] ?? 0) > 5, 'tl_claude_log mit erfolgreichen und abgewiesenen Einträgen', json_encode($log));

echo "\n$ok bestanden, $fail fehlgeschlagen\n";

exit($fail > 0 ? 1 : 0);
