<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Router für den eingebauten PHP-Webserver im Prüfstand.
 *
 * Vorhandene Dateien liefert der Server selbst aus, alles Übrige geht an
 * Contaos index.php – auch /mcp, dessen Pfad keine echte Datei ist.
 *
 * Start (Beispiel 4.13):
 * C:/xampp/php/php.exe -S 127.0.0.1:8160 -t F:/Claude/contao-test-413/public pruefstand/router.php
 */

$root = $_SERVER['DOCUMENT_ROOT'] ?? '';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ('' !== $root && '/' !== $path && is_file($root.$path)) {
	return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/index.php';

require $root.'/index.php';
