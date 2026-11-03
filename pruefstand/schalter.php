<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Setzt Schalter des Bundles in der localconfig.php einer Testinstallation.
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/schalter.php F:/Claude/contao-test-413 claudeWriteEnabled=1 claudePublishEnabled=1 claudeDeleteEnabled=0
 *
 * Erlaubt sind nur Schlüssel, die mit „claude“ beginnen. 1 setzt true, 0
 * entfernt den Eintrag (wie ein leerer Haken im Backend). Die Einträge
 * stehen vor dem Endkennzeichen, damit Contao sie beim nächsten Speichern
 * im Backend weiter verwaltet.
 */

$installation = $argv[1] ?? null;
$switches = array_slice($argv, 2);

if (null === $installation || array() === $switches) {
	fwrite(STDERR, "Aufruf: schalter.php <installation> claudeSchalter=0|1 …\n");

	exit(1);
}

$file = rtrim($installation, '/\\').'/system/config/localconfig.php';

if (!is_file($file)) {
	fwrite(STDERR, "Keine localconfig.php in $installation\n");

	exit(1);
}

$code = (string) file_get_contents($file);
$marker = '### INSTALL SCRIPT STOP ###';

foreach ($switches as $switch) {
	if (!preg_match('/^(claude[A-Za-z]+)=([01])$/', $switch, $match)) {
		fwrite(STDERR, "Ungültig: $switch\n");

		exit(1);
	}

	[, $key, $value] = $match;
	$code = (string) preg_replace("/^\\\$GLOBALS\\['TL_CONFIG'\\]\\['".$key."'\\] = .*\\R/m", '', $code);

	if ('1' === $value) {
		$line = "\$GLOBALS['TL_CONFIG']['$key'] = true;\n";
		$code = str_contains($code, $marker) ? str_replace($marker, $line.$marker, $code) : $code.$line;
	}

	echo "$key = ".('1' === $value ? 'an' : 'aus')."\n";
}

file_put_contents($file, $code);
