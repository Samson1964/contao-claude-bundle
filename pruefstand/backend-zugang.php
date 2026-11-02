<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Setzt einem Backend-Benutzer des Prüfstands ein neues Kennwort, für die
 * Anmeldung im Browser.
 *
 * Ohne drittes Argument geht es um den Administrator „claude-admin“, der
 * bei Bedarf angelegt wird (Prüfung der Einstellungsmaske). Mit
 * „claude-redakteur“ bekommt der von testdaten.php angelegte Redakteur ein
 * Kennwort (Prüfung der OAuth-Anmeldung als Nicht-Admin); andere Benutzer
 * werden nicht angelegt.
 *
 * Das Kennwort wird zufällig erzeugt und nur in
 * pruefstand/backend-<name>.local.md abgelegt (nicht versioniert).
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/backend-zugang.php contao_test_413 413 [benutzername]
 */

[, $database, $name, $username] = $argv + array(null, null, null, 'claude-admin');

if (null === $database || null === $name) {
	fwrite(STDERR, "Aufruf: backend-zugang.php <datenbank> <kurzname> [benutzername]\n");

	exit(1);
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$password = bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_DEFAULT);
$now = time();

$statement = $pdo->prepare('SELECT id FROM tl_user WHERE username = ?');
$statement->execute(array($username));
$id = $statement->fetchColumn();

if (false === $id && 'claude-admin' !== $username) {
	fwrite(STDERR, "Den Benutzer $username gibt es nicht; erst testdaten.php laufen lassen.\n");

	exit(1);
}

if (false === $id) {
	$pdo->prepare("INSERT INTO tl_user (tstamp, username, name, email, language, password, admin, dateAdded, pwChange) VALUES (?, 'claude-admin', 'Claude-Test Administrator', 'claude-admin@example.org', 'de', ?, '1', ?, '')")->execute(array($now, $hash, $now));
	$id = (int) $pdo->lastInsertId();
} else {
	$pdo->prepare("UPDATE tl_user SET password = ?, pwChange = '' WHERE id = ?")->execute(array($hash, $id));

	// Sperre nach Fehlversuchen gibt es nur in Contao 4.13
	if (false !== $pdo->query("SHOW COLUMNS FROM tl_user LIKE 'locked'")->fetch()) {
		$pdo->prepare('UPDATE tl_user SET locked = 0, loginAttempts = 0 WHERE id = ?')->execute(array($id));
	}
}

$file = 'claude-admin' === $username ? 'backend-'.$name.'.local.md' : 'backend-'.$name.'-'.$username.'.local.md';

file_put_contents(__DIR__.'/'.$file, sprintf("# Backend-Zugang %s (nicht versioniert)\n\nDatenbank: %s\nBenutzer: %s (ID %d)\nKennwort: %s\n", $name, $database, $username, $id, $password));

echo "$username (ID $id) bereit, Zugang in pruefstand/$file\n";
