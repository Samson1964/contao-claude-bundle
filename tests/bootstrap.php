<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Bootstrap für die Unit-Tests.
 *
 * Die Tests brauchen Contaos Hilfsklassen (StringUtil, Validator), das
 * MCP-SDK und Symfony aus dem eigenen vendor/. Die Zeitzone wird fest auf
 * Berlin gesetzt, weil die Erwartungswerte der Datumsaufbereitung sonst von
 * der php.ini abhingen.
 */

$autoload = __DIR__.'/../vendor/autoload.php';

if (!is_file($autoload)) {
	fwrite(STDERR, "vendor/autoload.php fehlt. Bitte zuerst im Bundle-Verzeichnis ausführen:\n/c/xampp/php/php.exe C:/Users/samso/bin/composer.phar install --no-plugins --no-security-blocking\n");

	exit(1);
}

date_default_timezone_set('Europe/Berlin');

require $autoload;
