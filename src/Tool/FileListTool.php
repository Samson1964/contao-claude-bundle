<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Tool;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Data\RecordFormatter;
use Schachbulle\ContaoClaudeBundle\Data\TableAccess;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;

/**
 * file_list: der Inhalt eines Ordners der Dateiverwaltung.
 *
 * Gelesen wird aus dem DBAFS (tl_files), nicht vom Dateisystem: Nur was
 * Contao kennt, hat eine UUID und lässt sich in Inhaltselementen verwenden.
 * Die Dateigröße kommt dagegen vom Dateisystem, weil tl_files keine führt.
 */
class FileListTool extends AbstractReadTool
{
	/**
	 * Obergrenze an Einträgen je Ordner.
	 */
	private const MAX_ENTRIES = 500;

	/**
	 * @param TableAccess     $tableAccess Prüft das Modulrecht auf die Dateiverwaltung
	 * @param Permissions     $permissions Liefert die Dateifreigaben
	 * @param RecordFormatter $formatter   Bereitet die Metadaten auf
	 * @param Connection      $connection  Liest tl_files
	 * @param string          $projectDir  Projektverzeichnis für die Dateigröße
	 * @param string          $uploadPath  Wurzel der Dateiverwaltung, meist „files“
	 */
	public function __construct(
		private readonly TableAccess $tableAccess,
		private readonly Permissions $permissions,
		private readonly RecordFormatter $formatter,
		private readonly Connection $connection,
		private readonly string $projectDir,
		private readonly string $uploadPath,
	) {
	}

	/**
	 * Liefert den Namen, unter dem Claude das Werkzeug aufruft.
	 *
	 * @return string Der Werkzeugname in snake_case
	 */
	public function getName(): string
	{
		return 'file_list';
	}

	/**
	 * Liefert den Anzeigenamen für die Oberfläche von Claude.
	 *
	 * @return string Der Titel
	 */
	public function getTitle(): string
	{
		return 'Dateien eines Ordners';
	}

	/**
	 * Liefert die Beschreibung, nach der Claude entscheidet, wann es das
	 * Werkzeug nutzt.
	 *
	 * @return string Die Beschreibung
	 */
	public function getDescription(): string
	{
		return 'Listet einen Ordner der Dateiverwaltung: Unterordner und Dateien mit Name, Pfad, UUID, Dateiendung, Größe in Byte, Änderungszeit und den Metadaten je Sprache (Titel, Alt-Text, Bildunterschrift …). Ohne Pfad: die Wurzel bzw. die eigenen Dateifreigaben. Bilder in Inhaltselementen werden über die UUID oder den Pfad angegeben.';
	}

	/**
	 * Liefert das JSON-Schema der Argumente. Die Schlüssel entsprechen den
	 * Parameternamen von __invoke(); das SDK ordnet die Werte danach zu.
	 *
	 * @return array<string, mixed> Das Schema
	 */
	public function getInputSchema(): array
	{
		return array(
			'type' => 'object',
			'properties' => array(
				'path' => array('type' => 'string', 'description' => 'Ordnerpfad relativ zum Projekt, etwa „files/presse/2026“. Leer: Wurzel der Dateiverwaltung.'),
			),
		);
	}

	/**
	 * Liefert den Ordnerinhalt.
	 *
	 * @param string $path Ordnerpfad, leer für die Wurzel
	 *
	 * @return array<string, mixed> path, folders, files, truncated
	 */
	public function __invoke(string $path = ''): array
	{
		$this->tableAccess->assertReadable('tl_files');

		$root = trim($this->uploadPath, '/');
		$path = trim(str_replace('\\', '/', $path), '/');
		$path = '' === $path ? $root : $path;

		if (str_contains('/'.$path.'/', '/../') || ($path !== $root && !str_starts_with($path, $root.'/'))) {
			throw new ToolCallException(sprintf('Der Pfad muss in „%s“ liegen.', $root));
		}

		$mounts = $this->permissions->getFileMounts();

		// Wurzel ohne Recht auf alles: die eigenen Freigaben als Ordner zeigen
		if ($path === $root && null !== $mounts) {
			return array(
				'path' => $root,
				'folders' => array_map(
					fn (string $mount): array => $this->describeFolder($this->connection->fetchAssociative('SELECT * FROM tl_files WHERE path = ?', array($mount)) ?: array('path' => $mount, 'name' => basename($mount))),
					$mounts
				),
				'files' => array(),
				'truncated' => false,
				'note' => 'Du siehst nur deine Dateifreigaben.',
			);
		}

		if (!$this->permissions->canReadPath($path)) {
			throw new ToolCallException(sprintf('Der Ordner %s liegt außerhalb deiner Dateifreigaben.', $path));
		}

		if ($path === $root) {
			$rows = $this->connection->fetchAllAssociative("SELECT * FROM tl_files WHERE pid IS NULL ORDER BY type = 'folder' DESC, name LIMIT ".(self::MAX_ENTRIES + 1));
		} else {
			$folder = $this->connection->fetchAssociative('SELECT uuid, type FROM tl_files WHERE path = ?', array($path));

			if (false === $folder || 'folder' !== $folder['type']) {
				throw new ToolCallException(sprintf('Den Ordner %s kennt die Dateiverwaltung nicht. Eventuell muss er dort erst synchronisiert werden.', $path));
			}

			$rows = $this->connection->fetchAllAssociative("SELECT * FROM tl_files WHERE pid = ? ORDER BY type = 'folder' DESC, name LIMIT ".(self::MAX_ENTRIES + 1), array($folder['uuid']));
		}

		$folders = array();
		$files = array();

		foreach (\array_slice($rows, 0, self::MAX_ENTRIES) as $row) {
			if ('folder' === $row['type']) {
				$folders[] = $this->describeFolder($row);
			} else {
				$files[] = $this->describeFile($row);
			}
		}

		return array(
			'path' => $path,
			'folders' => $folders,
			'files' => $files,
			'truncated' => \count($rows) > self::MAX_ENTRIES,
		);
	}

	/**
	 * Beschreibt einen Ordner.
	 *
	 * @param array<string, mixed> $row Zeile aus tl_files
	 *
	 * @return array<string, mixed> name, path, uuid
	 */
	private function describeFolder(array $row): array
	{
		return array(
			'name' => (string) $row['name'],
			'path' => (string) $row['path'],
			'uuid' => isset($row['uuid']) ? StringUtil::binToUuid((string) $row['uuid']) : null,
		);
	}

	/**
	 * Beschreibt eine Datei.
	 *
	 * @param array<string, mixed> $row Zeile aus tl_files
	 *
	 * @return array<string, mixed> name, path, uuid, extension, size, modified, meta
	 */
	private function describeFile(array $row): array
	{
		$absolute = $this->projectDir.'/'.$row['path'];
		$meta = $this->formatter->formatValue('meta', $row['meta'] ?? null);

		return array(
			'name' => (string) $row['name'],
			'path' => (string) $row['path'],
			'uuid' => StringUtil::binToUuid((string) $row['uuid']),
			'extension' => (string) $row['extension'],
			'size' => is_file($absolute) ? filesize($absolute) : null,
			'modified' => RecordFormatter::formatDate($row['tstamp'] ?? null),
			'meta' => \is_array($meta) ? $meta : null,
		);
	}
}
