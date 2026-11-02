<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Legt den Backend-Administrator „claude-admin“ für die Prüfung der
 * Einstellungsmaske an bzw. setzt ihm ein neues Kennwort.
 *
 * Das Kennwort wird zufällig erzeugt und nur in
 * pruefstand/backend-<name>.local.md abgelegt (nicht versioniert).
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/backend-zugang.php contao_test_413 413
 */

[, $database, $name] = $argv + array(null, null, null);

if (null === $database || null === $name) {
	fwrite(STDERR, "Aufruf: backend-zugang.php <datenbank> <kurzname>\n");

	exit(1);
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$password = bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_DEFAULT);
$now = time();

$statement = $pdo->prepare('SELECT id FROM tl_user WHERE username = ?');
$statement->execute(array('claude-admin'));
$id = $statement->fetchColumn();

if (false === $id) {
	$pdo->prepare("INSERT INTO tl_user (tstamp, username, name, email, language, password, admin, dateAdded, pwChange) VALUES (?, 'claude-admin', 'Claude-Test Administrator', 'claude-admin@example.org', 'de', ?, '1', ?, '')")->execute(array($now, $hash, $now));
	$id = (int) $pdo->lastInsertId();
} else {
	$pdo->prepare("UPDATE tl_user SET password = ?, pwChange = '', locked = 0, loginAttempts = 0 WHERE id = ?")->execute(array($hash, $id));
}

file_put_contents(__DIR__.'/backend-'.$name.'.local.md', sprintf("# Backend-Zugang %s (nicht versioniert)\n\nDatenbank: %s\nBenutzer: claude-admin (ID %d)\nKennwort: %s\n", $name, $database, $id, $password));

echo "claude-admin (ID $id) bereit, Zugang in pruefstand/backend-$name.local.md\n";
