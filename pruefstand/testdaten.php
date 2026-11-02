<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Legt Testdaten für die Werkzeuge an und schreibt die passenden Proben.
 *
 * Alles trägt „Claude-Test“ im Namen und wird bei einem zweiten Lauf
 * wiederverwendet statt neu angelegt; gelöscht wird nichts. Angelegt werden:
 *
 * - ein Nachrichtenarchiv mit zwei Nachrichten (eine veröffentlicht mit zwei
 *   Inhaltselementen, ein Entwurf) – die Testdatenbanken hatten keine,
 * - eine Benutzergruppe „Claude-Test Redaktion“ mit Artikeln, Nachrichten
 *   und Dateien, eingeschränkt auf eine Seite, das Testarchiv und einen Ordner,
 * - ein Backend-Benutzer „claude-redakteur“ in dieser Gruppe (kein Admin,
 *   zufälliges Kennwort, Anmeldung nur über den Testschlüssel vorgesehen).
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/testdaten.php contao_test_413 413
 *   1. Argument: Datenbankname (MariaDB auf 127.0.0.1, Benutzer root ohne Kennwort)
 *   2. Argument: Kurzname; die Proben landen in pruefstand/mcp-probe-<name>.local.json
 */

[, $database, $name] = $argv + array(null, null, null);

if (null === $database || null === $name) {
	fwrite(STDERR, "Aufruf: testdaten.php <datenbank> <kurzname>\n");

	exit(1);
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$now = time();

/**
 * Liefert die ID eines Datensatzes oder legt ihn an.
 *
 * @param PDO                  $pdo    Verbindung
 * @param string               $table  Tabelle
 * @param string               $column Spalte, über die wiedererkannt wird
 * @param string               $value  Wert dieser Spalte
 * @param array<string, mixed> $data   Weitere Spalten beim Anlegen
 *
 * @return int Die ID
 */
function findOrCreate(PDO $pdo, string $table, string $column, string $value, array $data): int
{
	$statement = $pdo->prepare("SELECT id FROM $table WHERE $column = ? LIMIT 1");
	$statement->execute(array($value));
	$id = $statement->fetchColumn();

	if (false !== $id) {
		return (int) $id;
	}

	$data[$column] = $value;
	$columns = array_keys($data);
	$statement = $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))));
	$statement->execute(array_values($data));

	return (int) $pdo->lastInsertId();
}

// Eine normale Seite mit Artikel und Elementen dient als Ziel der Freigaben
$page = $pdo->query("SELECT p.id, a.id AS article FROM tl_page p JOIN tl_article a ON a.pid = p.id WHERE p.type = 'regular' AND EXISTS (SELECT 1 FROM tl_content c WHERE c.pid = a.id AND c.ptable IN ('tl_article', '')) ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$otherPage = (int) $pdo->query('SELECT id FROM tl_page WHERE id <> '.(int) $page['id']." AND type = 'regular' ORDER BY id DESC LIMIT 1")->fetchColumn();
$root = (int) $pdo->query("SELECT id FROM tl_page WHERE type = 'root' ORDER BY sorting LIMIT 1")->fetchColumn();
$folder = $pdo->query("SELECT uuid, path FROM tl_files WHERE type = 'folder' AND pid IS NULL ORDER BY path LIMIT 1")->fetch(PDO::FETCH_ASSOC);
// Nur Kerntabellen (Versionen von tl_user wiese das Werkzeug zu Recht ab) und
// nur Datensätze, die es noch gibt (bei gelöschten fehlt die Rechtegrundlage)
$version = array('fromTable' => 'tl_page', 'pid' => $root);

foreach ($pdo->query("SELECT fromTable, pid FROM tl_version WHERE fromTable IN ('tl_content', 'tl_article', 'tl_page', 'tl_news') GROUP BY fromTable, pid ORDER BY COUNT(*) DESC LIMIT 50") as $candidate) {
	if (false !== $pdo->query(sprintf('SELECT 1 FROM %s WHERE id = %d', $candidate['fromTable'], (int) $candidate['pid']))->fetchColumn()) {
		$version = $candidate;
		break;
	}
}

$archive = findOrCreate($pdo, 'tl_news_archive', 'title', 'Claude-Test Nachrichten', array('tstamp' => $now, 'jumpTo' => (int) $page['id']));

$published = findOrCreate($pdo, 'tl_news', 'headline', 'Claude-Test: Veröffentlichte Nachricht', array(
	'pid' => $archive,
	'tstamp' => $now,
	'alias' => 'claude-test-veroeffentlicht',
	'author' => 1,
	'date' => $now - 86400,
	'time' => $now - 86400,
	'teaser' => '<p>Ein Teaser mit geschütztem[nbsp]Leerzeichen und &lt;Zeichen[gt].</p>',
	'published' => 1,
));

$draft = findOrCreate($pdo, 'tl_news', 'headline', 'Claude-Test: Entwurf', array(
	'pid' => $archive,
	'tstamp' => $now,
	'alias' => 'claude-test-entwurf',
	'author' => 1,
	'date' => $now,
	'time' => $now,
	'teaser' => '<p>Noch nicht veröffentlicht.</p>',
	'published' => 0,
));

$headline = serialize(array('value' => 'Claude-Test Zwischenüberschrift', 'unit' => 'h2'));
findOrCreate($pdo, 'tl_content', 'cssID', 'a:2:{i:0;s:0:"";i:1;s:12:"claude-test1";}', array('pid' => $published, 'ptable' => 'tl_news', 'sorting' => 128, 'tstamp' => $now, 'type' => 'headline', 'headline' => $headline));
findOrCreate($pdo, 'tl_content', 'cssID', 'a:2:{i:0;s:0:"";i:1;s:12:"claude-test2";}', array('pid' => $published, 'ptable' => 'tl_news', 'sorting' => 256, 'tstamp' => $now, 'type' => 'text', 'text' => '<p>Der Text der Claude-Testnachricht mit <strong>Max Mustermann</strong>.</p>'));
findOrCreate($pdo, 'tl_content', 'cssID', 'a:2:{i:0;s:0:"";i:1;s:12:"claude-test3";}', array('pid' => $draft, 'ptable' => 'tl_news', 'sorting' => 128, 'tstamp' => $now, 'type' => 'text', 'text' => '<p>Entwurfstext.</p>'));

$group = findOrCreate($pdo, 'tl_user_group', 'name', 'Claude-Test Redaktion', array(
	'tstamp' => $now,
	'modules' => serialize(array('article', 'news', 'files')),
	'pagemounts' => serialize(array((int) $page['id'])),
	'news' => serialize(array($archive)),
	'filemounts' => serialize(array($folder['uuid'])),
	'alexf' => serialize(array()),
));

$editor = findOrCreate($pdo, 'tl_user', 'username', 'claude-redakteur', array(
	'tstamp' => $now,
	'name' => 'Claude-Test Redakteur',
	'email' => 'claude-redakteur@example.org',
	'language' => 'de',
	'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
	'admin' => '',
	'groups' => serialize(array((string) $group)),
	'inherit' => 'group',
	'dateAdded' => $now,
));

$probes = array(
	array('tool' => 'site_info', 'label' => 'site_info'),
	array('tool' => 'page_tree', 'label' => 'page_tree Wurzel, Tiefe 2', 'arguments' => array('depth' => 2)),
	array('tool' => 'page_tree', 'label' => 'page_tree ab Wurzelseite', 'arguments' => array('parentId' => $root, 'depth' => 1)),
	array('tool' => 'article_list', 'label' => 'article_list', 'arguments' => array('pageId' => (int) $page['id'])),
	array('tool' => 'content_list', 'label' => 'content_list Artikel', 'arguments' => array('parentId' => (int) $page['article'])),
	array('tool' => 'content_list', 'label' => 'content_list Nachricht, voll', 'arguments' => array('parentTable' => 'tl_news', 'parentId' => $published, 'full' => true)),
	array('tool' => 'news_search', 'label' => 'news_search alle', 'arguments' => new stdClass()),
	array('tool' => 'news_search', 'label' => 'news_search Text + Entwürfe', 'arguments' => array('query' => 'Entwurfstext', 'status' => 'unpublished')),
	array('tool' => 'news_get', 'label' => 'news_get', 'arguments' => array('id' => $published)),
	array('tool' => 'file_list', 'label' => 'file_list Wurzel'),
	array('tool' => 'file_list', 'label' => 'file_list Ordner', 'arguments' => array('path' => $folder['path'])),
	array('tool' => 'dca_describe', 'label' => 'dca_describe tl_news', 'arguments' => array('table' => 'tl_news')),
	array('tool' => 'record_list', 'label' => 'record_list tl_article mit Filter', 'arguments' => array('table' => 'tl_article', 'filter' => array('pid' => (int) $page['id']))),
	array('tool' => 'record_list', 'label' => 'record_list Suche tl_news', 'arguments' => array('table' => 'tl_news', 'search' => 'Claude-Test', 'order' => 'date desc')),
	array('tool' => 'record_get', 'label' => 'record_get tl_news', 'arguments' => array('table' => 'tl_news', 'id' => $published)),
	array('tool' => 'record_history', 'label' => 'record_history', 'arguments' => array('table' => $version['fromTable'], 'id' => (int) $version['pid'])),
	array('tool' => 'preview_url', 'label' => 'preview_url Seite', 'arguments' => array('table' => 'tl_page', 'id' => (int) $page['id'])),
	array('tool' => 'preview_url', 'label' => 'preview_url Nachricht', 'arguments' => array('table' => 'tl_news', 'id' => $draft)),
	array('tool' => 'record_list', 'label' => 'Sperrliste: tl_user abgewiesen', 'arguments' => array('table' => 'tl_user'), 'expectError' => true),
	array('tool' => 'record_get', 'label' => 'unbekannte Tabelle abgewiesen', 'arguments' => array('table' => 'tl_gibtsnicht', 'id' => 1), 'expectError' => true),
	array('tool' => 'record_list', 'label' => 'unbekanntes Feld abgewiesen', 'arguments' => array('table' => 'tl_page', 'filter' => array('gibtsnicht' => 1)), 'expectError' => true),
);

// Proben für den eingeschränkten Redakteur: Freigegebenes geht, anderes nicht
$editorProbes = array(
	array('tool' => 'site_info', 'label' => 'Redakteur: site_info'),
	array('tool' => 'page_tree', 'label' => 'Redakteur: page_tree nur Freigabe', 'arguments' => array('depth' => 2)),
	array('tool' => 'article_list', 'label' => 'Redakteur: freigegebene Seite', 'arguments' => array('pageId' => (int) $page['id'])),
	array('tool' => 'article_list', 'label' => 'Redakteur: fremde Seite abgewiesen', 'arguments' => array('pageId' => $otherPage), 'expectError' => true),
	array('tool' => 'news_search', 'label' => 'Redakteur: news_search', 'arguments' => new stdClass()),
	array('tool' => 'news_get', 'label' => 'Redakteur: news_get', 'arguments' => array('id' => $published)),
	array('tool' => 'file_list', 'label' => 'Redakteur: file_list zeigt Freigaben'),
	array('tool' => 'record_list', 'label' => 'Redakteur: tl_content nur Freigegebenes', 'arguments' => array('table' => 'tl_content', 'fields' => array('ptable', 'pid', 'type'), 'limit' => 100)),
	array('tool' => 'record_list', 'label' => 'Redakteur: tl_layout ohne Modulrecht abgewiesen', 'arguments' => array('table' => 'tl_layout'), 'expectError' => true),
	array('tool' => 'record_get', 'label' => 'Redakteur: fremde Seite abgewiesen', 'arguments' => array('table' => 'tl_page', 'id' => $otherPage), 'expectError' => true),
);

file_put_contents(__DIR__.'/mcp-probe-'.$name.'.local.json', json_encode($probes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents(__DIR__.'/mcp-probe-'.$name.'-redakteur.local.json', json_encode($editorProbes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode(array(
	'page' => (int) $page['id'],
	'article' => (int) $page['article'],
	'otherPage' => $otherPage,
	'archive' => $archive,
	'news' => array($published, $draft),
	'group' => $group,
	'editor' => $editor,
	'folder' => $folder['path'],
), JSON_UNESCAPED_SLASHES), "\n";
