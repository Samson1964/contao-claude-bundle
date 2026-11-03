<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Prüft die Werkzeuge für Meisterlisten und Adressen (Ausbaustufe 5).
 *
 * Aufruf (Testschlüssel eines Administrators, Schreiben und Löschen an):
 * C:/xampp/php/php.exe pruefstand/stufe5-probe.php http://127.0.0.1:8161/mcp pruefstand/zugang-57.local.md contao_test
 *
 * Gibt es in der Installation keine Meistereinträge, werden die Proben
 * dafür übersprungen. Der Testeintrag „Claude-Test Meister“ wird am Ende
 * wieder gelöscht (liegt danach in tl_undo).
 */

[, $url, $noteFile, $database] = $argv + array(null, null, null, null);

if (null === $url || null === $noteFile || null === $database || !preg_match('/^Schlüssel: (\S+)$/m', (string) file_get_contents($noteFile), $match)) {
	fwrite(STDERR, "Aufruf: stufe5-probe.php <url> <zugang-datei> <datenbank>\n");

	exit(1);
}

require __DIR__.'/mcp-client.inc.php';

$client = new ProbeClient($url, $match[1]);
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$client->connect();
$tools = $client->tools();

echo "Werkzeugliste\n";
check(array() === array_diff(array('address_search', 'championslist_search', 'championslist_add'), $tools), 'neue Werkzeuge angeboten', implode(', ', array_intersect(array('address_search', 'championslist_search', 'championslist_add'), $tools)));

echo "Meisterlisten\n";
$entries = (int) $pdo->query('SELECT COUNT(*) FROM tl_championslists_items')->fetchColumn();
$r = $client->call('championslist_search');
$lists = $r['data']['lists'] ?? array();
check(!$r['error'] && count($lists) === (int) $pdo->query('SELECT COUNT(*) FROM tl_championslists')->fetchColumn(), 'Listen ohne Angaben', count($lists).' Listen');

if ($entries > 0) {
	$sample = $pdo->query("SELECT i.name, i.year, i.pid FROM tl_championslists_items i WHERE i.name <> '' AND i.year <> '' ORDER BY i.id LIMIT 1")->fetch();
	$part = mb_substr(preg_replace('/^(GM|IM|FM|WGM|WIM|WFM|CM)\s+/', '', (string) $sample['name']), 0, 6);
	$r = $client->call('championslist_search', array('query' => $part));
	$names = array_column($r['data']['entries'] ?? array(), 'name');
	check(!$r['error'] && array() !== $names && array() === array_filter($names, static fn ($name): bool => false === mb_stripos((string) $name, $part) && true), 'Suche nach „'.$part.'“', count($names).' Treffer');

	$r = $client->call('championslist_search', array('listId' => (int) $sample['pid'], 'year' => (string) $sample['year']));
	check(!$r['error'] && ($r['data']['count'] ?? 0) > 0 && array() === array_diff(array_column($r['data']['entries'], 'year'), array((string) $sample['year'])), 'Liste und Jahr '.$sample['year'], $r['text']);

	$r = $client->call('championslist_add', array('listId' => (int) $sample['pid'], 'year' => '2099', 'name' => 'Claude-Test Meister', 'values' => array('place' => 'Prüfstand')));
	$newId = (int) ($r['data']['id'] ?? 0);
	$row = $pdo->query('SELECT * FROM tl_championslists_items WHERE id = '.$newId)->fetch() ?: array();
	check(!$r['error'] && 'Claude-Test Meister' === ($row['name'] ?? '') && 'Prüfstand' === ($row['place'] ?? '') && !($row['published'] ?? true), 'championslist_add legt unveröffentlicht an', $r['text']);

	$r = $client->call('championslist_add', array('listId' => (int) $sample['pid'], 'year' => '', 'name' => 'Ohne Jahr'));
	check($r['error'] && str_contains($r['text'], 'year'), 'Pflichtfeld Jahr', $r['text']);

	$r = $client->call('record_delete', array('table' => 'tl_championslists_items', 'id' => $newId));
	check(!$r['error'] && false === $pdo->query('SELECT id FROM tl_championslists_items WHERE id = '.$newId)->fetch(), 'Testeintrag wieder gelöscht', $r['text']);
} else {
	echo "  (keine Meistereinträge in dieser Installation, Proben übersprungen)\n";
}

echo "Adressen\n";
$person = $pdo->query("SELECT id, nachname, funktionen FROM tl_adressen WHERE aktiv = 1 AND nachname <> '' AND funktionen LIKE 'a:%' ORDER BY id LIMIT 1")->fetch();
$r = $client->call('address_search', array('query' => (string) $person['nachname']));
$found = array_column($r['data']['addresses'] ?? array(), 'id');
check(!$r['error'] && in_array((int) $person['id'], $found, true), 'Suche nach „'.$person['nachname'].'“', count($found).' Treffer');

$text = $r['text'];
check(!str_contains($text, '"iban"') && !str_contains($text, '"bic"'), 'IBAN und BIC nicht in der Ausgabe');

$address = array_values(array_filter($r['data']['addresses'] ?? array(), static fn (array $a): bool => (int) $a['id'] === (int) $person['id']))[0] ?? array();
$functions = (array) ($address['funktionen'] ?? array());
check(array() !== $functions && array() === array_filter($functions, 'is_numeric'), 'Funktionen mit Namen statt Nummern', implode(', ', $functions));

if (array() !== $functions) {
	$part = mb_substr((string) $functions[0], 0, 8);
	$r = $client->call('address_search', array('function' => $part, 'limit' => 100));
	check(!$r['error'] && ($r['data']['count'] ?? 0) > 0 && in_array($functions[0], $r['data']['functions'] ?? array(), true), 'Suche nach Funktion „'.$part.'“', ($r['data']['count'] ?? 0).' Treffer');
}

$r = $client->call('record_get', array('table' => 'tl_adressen', 'id' => (int) $person['id'], 'includeEmpty' => true));
check(!$r['error'] && !array_key_exists('iban', $r['data']['record'] ?? array()) && !array_key_exists('bic', $r['data']['record'] ?? array()), 'record_get ohne IBAN und BIC');

$r = $client->call('record_save', array('table' => 'tl_adressen', 'id' => (int) $person['id'], 'values' => array('iban' => 'DE00')));
check($r['error'] && str_contains($r['text'], 'gesperrt'), 'IBAN lässt sich nicht schreiben', $r['text']);

echo "\n$ok bestanden, $fail fehlgeschlagen\n";

exit($fail > 0 ? 1 : 0);
