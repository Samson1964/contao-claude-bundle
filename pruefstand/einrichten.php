<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Richtet den MCP-Server in einer Testinstallation ein, ohne Backend.
 *
 * Schaltet den Server ein, setzt den Testbenutzer und erzeugt einen neuen
 * Testschlüssel. In die localconfig.php kommt nur dessen Hash; der Schlüssel
 * selbst wird in pruefstand/zugang-<name>.local.md abgelegt (nicht
 * versioniert, siehe .gitignore).
 *
 * Aufruf:
 * C:/xampp/php/php.exe pruefstand/einrichten.php F:/Claude/contao-test-413 413 2
 *   1. Argument: Installationsverzeichnis
 *   2. Argument: Kurzname für die Zugangsdatei
 *   3. Argument: ID des Backend-Benutzers
 */

if ($argc < 4) {
	fwrite(STDERR, "Aufruf: einrichten.php <Installationsverzeichnis> <Kurzname> <Benutzer-ID>\n");

	exit(1);
}

[, $installation, $name, $userId] = $argv;

$file = rtrim($installation, '/\\').'/system/config/localconfig.php';

if (!is_file($file)) {
	fwrite(STDERR, "Keine localconfig.php in $installation\n");

	exit(1);
}

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

$settings = array(
	'claudeMcpEnabled' => 'true',
	'claudeTestUser' => (string) (int) $userId,
	'claudeTestTokenHash' => var_export(hash('sha256', $token), true),
	'claudeTestTokenCreated' => (string) time(),
);

// Vorhandene Einträge des Bundles entfernen, neue vor das Endkennzeichen setzen,
// damit Contao sie beim nächsten Speichern im Backend weiter verwaltet
$lines = preg_grep("/^\\\$GLOBALS\\['TL_CONFIG'\\]\\['claude(McpEnabled|TestUser|TestTokenHash|TestTokenCreated)'\\]/", file($file), PREG_GREP_INVERT);
$code = implode('', $lines);
$block = '';

foreach ($settings as $key => $value) {
	$block .= "\$GLOBALS['TL_CONFIG']['$key'] = $value;\n";
}

$marker = '### INSTALL SCRIPT STOP ###';
$code = str_contains($code, $marker) ? str_replace($marker, $block.$marker, $code) : $code.$block;

file_put_contents($file, $code);

$note = __DIR__.'/zugang-'.$name.'.local.md';

file_put_contents($note, sprintf(
	"# Testschlüssel %s (nicht versioniert)\n\nInstallation: %s\nBackend-Benutzer-ID: %d\nErzeugt: %s\n\nSchlüssel: %s\n",
	$name,
	$installation,
	(int) $userId,
	date('Y-m-d H:i'),
	$token
));

echo "Eingerichtet. Schlüssel steht in $note\n";
