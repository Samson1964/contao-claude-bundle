<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Dbafs;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Mcp\Exception\ToolCallException;
use Schachbulle\ContaoClaudeBundle\Security\Permissions;
use Schachbulle\ContaoClaudeBundle\Settings\Settings;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lädt Dateien hoch und pflegt ihre Metadaten.
 *
 * Es gelten dieselben Regeln wie beim Hochladen im Backend: Modul
 * „Dateiverwaltung“, Recht „Dateien hochladen“ bzw. „Dateien bearbeiten“,
 * Dateifreigaben, erlaubte Dateitypen (uploadTypes) und Höchstgröße
 * (maxFileSize) aus den Einstellungen, Dateiname wie beim Upload bereinigt.
 * Strenger als das Backend:
 *
 * - Vorhandene Dateien werden nie überschrieben.
 * - SVG ist ausgeschlossen, weil SVG Skripte enthalten kann und Contao 4.13
 *   SVGs beim Upload nicht bereinigt.
 * - Beim Abruf über eine Adresse nur HTTPS und nur öffentliche Adressen
 *   (NoPrivateNetworkHttpClient): Eine untergeschobene Anweisung könnte
 *   sonst Dateien aus dem internen Netz des Servers in /files holen.
 * - Große Bilder werden nicht verkleinert wie beim Upload im Backend,
 *   sondern so abgelegt, wie sie kommen.
 */
class FileWriter
{
	/**
	 * Endungen, die Claude nie hochladen darf.
	 */
	private const FORBIDDEN_EXTENSIONS = array('svg', 'svgz', 'php', 'phtml', 'phar', 'htm', 'html', 'js', 'htaccess');

	/**
	 * Zeitlimit für das Herunterladen, in Sekunden.
	 */
	private const DOWNLOAD_TIMEOUT = 20;

	/**
	 * @param ContaoFramework               $framework    Für Config, FilesModel und Dbafs
	 * @param Connection                    $connection   Schreibt die Metadaten
	 * @param Permissions                   $permissions  Modul- und Dateifreigaben
	 * @param AuthorizationCheckerInterface $authorization Recht zum Hochladen und Bearbeiten
	 * @param Settings                      $settings     Schalter für das Schreiben
	 * @param AuditLog                      $log          Protokoll
	 * @param string                        $projectDir   Projektverzeichnis
	 * @param string                        $uploadPath   Upload-Ordner, meist „files“
	 * @param HttpClientInterface|null      $httpClient   Für Downloads; ohne Dienst wird einer erzeugt
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly Connection $connection,
		private readonly Permissions $permissions,
		private readonly AuthorizationCheckerInterface $authorization,
		private readonly Settings $settings,
		private readonly AuditLog $log,
		private readonly string $projectDir,
		private readonly string $uploadPath,
		private readonly ?HttpClientInterface $httpClient = null,
	) {
	}

	/**
	 * Legt eine neue Datei in einem vorhandenen Ordner ab.
	 *
	 * @param string      $folder   Zielordner, etwa „files/presse/2026“
	 * @param string      $filename Gewünschter Dateiname mit Endung
	 * @param string|null $base64   Inhalt als Base64, oder null
	 * @param string|null $url      HTTPS-Adresse zum Herunterladen, oder null
	 *
	 * @return array{path: string, uuid: string, size: int} Die neue Datei
	 *
	 * @throws ToolCallException Wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function upload(string $folder, string $filename, ?string $base64, ?string $url): array
	{
		$folder = trim(str_replace('\\', '/', $folder), '/');
		$path = '';

		try {
			$this->framework->initialize();
			$this->assertAllowed(ContaoCorePermissions::USER_CAN_UPLOAD_FILES, 'Dateien hochladen');
			$this->assertFolder($folder);

			$filename = $this->sanitizeFilename($filename);
			$path = $folder.'/'.$filename;
			$absolute = $this->projectDir.'/'.$path;

			if (file_exists($absolute)) {
				throw new ToolCallException(sprintf('Die Datei %s gibt es schon. Vorhandene Dateien werden nicht überschrieben; wähle einen anderen Namen.', $path));
			}

			$content = $this->fetchContent($base64, $url);

			if (false === file_put_contents($absolute, $content)) {
				throw new ToolCallException(sprintf('Die Datei %s ließ sich nicht schreiben.', $path));
			}

			@chmod($absolute, 0666 & ~umask());

			$model = $this->framework->getAdapter(Dbafs::class)->addResource($path);

			if (!$model instanceof FilesModel) {
				@unlink($absolute);

				throw new ToolCallException(sprintf('Contao hat die Datei %s nicht in die Dateiverwaltung aufgenommen.', $path));
			}
		} catch (ToolCallException $exception) {
			$this->log->add('file_upload', 'tl_files', 0, array($path ?: $folder.'/'.$filename), false, $exception->getMessage());

			throw $exception;
		}

		$this->log->add('file_upload', 'tl_files', (int) $model->id, array($path), true);

		return array('path' => $path, 'uuid' => StringUtil::binToUuid((string) $model->uuid), 'size' => \strlen($content));
	}

	/**
	 * Setzt Metadaten einer Datei für eine Sprache.
	 *
	 * Nur die übergebenen Angaben ändern sich, die übrigen Sprachen und
	 * Felder bleiben. Erlaubt sind die Felder des Metadaten-Widgets der
	 * Installation (title, alt, link, caption, in Contao 5 auch license).
	 * Vorher wird eine Version angelegt, wie beim Speichern im Backend.
	 *
	 * @param string                $path     Pfad der Datei
	 * @param string                $language Sprachkürzel, etwa „de“
	 * @param array<string, string> $meta     Felder und Werte; ein leerer Wert leert das Feld
	 *
	 * @return array<string, string> Die Metadaten der Sprache nach dem Speichern
	 *
	 * @throws ToolCallException Wenn etwas nicht erlaubt oder ungültig ist
	 */
	public function updateMeta(string $path, string $language, array $meta): array
	{
		$path = trim(str_replace('\\', '/', $path), '/');
		$id = 0;

		try {
			$this->framework->initialize();
			$this->assertAllowed(ContaoCorePermissions::USER_CAN_RENAME_FILE, 'Dateien bearbeiten');

			$model = $this->framework->getAdapter(FilesModel::class)->findByPath($path);

			if (null === $model || 'file' !== $model->type) {
				throw new ToolCallException(sprintf('Die Datei %s gibt es nicht in der Dateiverwaltung.', $path));
			}

			$id = (int) $model->id;

			if (!$this->permissions->canReadPath($path)) {
				throw new ToolCallException(sprintf('%s liegt außerhalb deiner Dateifreigaben.', $path));
			}

			if (!preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $language)) {
				throw new ToolCallException(sprintf('„%s“ ist kein Sprachkürzel wie „de“ oder „en“.', $language));
			}

			$this->framework->getAdapter(Controller::class)->loadDataContainer('tl_files');
			$allowed = array_keys((array) ($GLOBALS['TL_DCA']['tl_files']['fields']['meta']['eval']['metaFields'] ?? array()));

			if (!$this->permissions->canEditField('tl_files', 'meta', (array) ($GLOBALS['TL_DCA']['tl_files']['fields']['meta'] ?? array()))) {
				throw new ToolCallException('Die Metadaten darf dein Backend-Zugang nicht bearbeiten (Feldfreigabe der Benutzergruppe).');
			}

			$unknown = array_diff(array_keys($meta), $allowed);

			if (array() !== $unknown) {
				throw new ToolCallException(sprintf('Unbekannte Metadaten: %s. Erlaubt sind: %s.', implode(', ', $unknown), implode(', ', $allowed)));
			}

			$all = StringUtil::deserialize($model->meta, true);
			$current = (array) ($all[$language] ?? array());

			foreach ($meta as $key => $value) {
				$current[$key] = StringUtil::specialchars(trim((string) $value));
			}

			$all[$language] = array_filter($current, static fn ($value): bool => '' !== $value);

			if (array() === $all[$language]) {
				unset($all[$language]);
			}

			$versions = new Versions('tl_files', $id);
			$versions->initialize();

			$this->connection->update('tl_files', array('meta' => serialize($all), 'tstamp' => time()), array('id' => $id));

			$versions->create();
		} catch (ToolCallException $exception) {
			$this->log->add('file_meta_update', 'tl_files', $id, array_keys($meta), false, $exception->getMessage());

			throw $exception;
		}

		$this->log->add('file_meta_update', 'tl_files', $id, array_keys($meta), true);

		return array_map(static fn ($value): string => html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $all[$language] ?? array());
	}

	/**
	 * Prüft Schalter, Modul und das Recht der Dateiverwaltung.
	 *
	 * @param string $permission Konstante aus ContaoCorePermissions
	 * @param string $label      Name des Rechts für die Meldung
	 *
	 * @throws ToolCallException Wenn etwas davon fehlt
	 */
	private function assertAllowed(string $permission, string $label): void
	{
		if (!$this->settings->isWriteEnabled()) {
			throw new ToolCallException('Schreiben ist in dieser Installation für Claude nicht freigegeben.');
		}

		if (!$this->permissions->canAccessModule('files')) {
			throw new ToolCallException('Dein Backend-Zugang darf die Dateiverwaltung nicht öffnen.');
		}

		if (!$this->authorization->isGranted($permission)) {
			throw new ToolCallException(sprintf('Deinem Backend-Zugang fehlt das Recht „%s“.', $label));
		}
	}

	/**
	 * Prüft den Zielordner.
	 *
	 * @param string $folder Pfad relativ zum Projektverzeichnis
	 *
	 * @throws ToolCallException Bei einem Ordner außerhalb von /files, ohne
	 *                           Freigabe oder einem, den es nicht gibt
	 */
	private function assertFolder(string $folder): void
	{
		if ($folder !== $this->uploadPath && !str_starts_with($folder, $this->uploadPath.'/')) {
			throw new ToolCallException(sprintf('Hochladen geht nur nach %s/…', $this->uploadPath));
		}

		if (str_contains('/'.$folder.'/', '/../') || str_contains($folder, "\0")) {
			throw new ToolCallException('Der Ordnername ist ungültig.');
		}

		if (!is_dir($this->projectDir.'/'.$folder)) {
			throw new ToolCallException(sprintf('Den Ordner %s gibt es nicht. Neue Ordner legt ein Mensch in der Dateiverwaltung an.', $folder));
		}

		if (!$this->permissions->canReadPath($folder)) {
			throw new ToolCallException(sprintf('%s liegt außerhalb deiner Dateifreigaben.', $folder));
		}
	}

	/**
	 * Bereinigt einen Dateinamen wie beim Upload und prüft die Endung.
	 *
	 * @param string $filename Gewünschter Name
	 *
	 * @return string Der bereinigte Name
	 *
	 * @throws ToolCallException Bei fehlender oder nicht erlaubter Endung
	 */
	private function sanitizeFilename(string $filename): string
	{
		$filename = (string) $this->framework->getAdapter(StringUtil::class)->sanitizeFileName(basename(str_replace('\\', '/', $filename)));
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		if ('' === $extension || '' === pathinfo($filename, PATHINFO_FILENAME) || str_starts_with($filename, '.')) {
			throw new ToolCallException('Der Dateiname braucht einen Namen und eine Endung, etwa „bericht.pdf“.');
		}

		$allowed = StringUtil::trimsplit(',', strtolower((string) $this->framework->getAdapter(Config::class)->get('uploadTypes')));

		if (\in_array($extension, self::FORBIDDEN_EXTENSIONS, true) || !\in_array($extension, $allowed, true)) {
			throw new ToolCallException(sprintf('Dateien mit der Endung .%s darf Claude nicht hochladen.', $extension));
		}

		return $filename;
	}

	/**
	 * Holt den Inhalt aus Base64 oder von einer Adresse.
	 *
	 * @param string|null $base64 Inhalt als Base64
	 * @param string|null $url    HTTPS-Adresse
	 *
	 * @return string Der Inhalt
	 *
	 * @throws ToolCallException Bei fehlender, doppelter, ungültiger oder zu großer Angabe
	 */
	private function fetchContent(?string $base64, ?string $url): string
	{
		$base64 = null === $base64 ? '' : trim($base64);
		$url = null === $url ? '' : trim($url);

		if (('' === $base64) === ('' === $url)) {
			throw new ToolCallException('Gib entweder contentBase64 oder url an, nicht beides und nicht keins.');
		}

		$maxSize = (int) $this->framework->getAdapter(Config::class)->get('maxFileSize');

		if ('' !== $base64) {
			// Data-URLs („data:image/png;base64,…“) gleich mit annehmen
			$base64 = (string) preg_replace('/^data:[^,]*,/', '', $base64);
			$content = base64_decode(preg_replace('/\s+/', '', $base64), true);

			if (false === $content || '' === $content) {
				throw new ToolCallException('contentBase64 ist kein gültiges Base64.');
			}
		} else {
			if (!str_starts_with(strtolower($url), 'https://')) {
				throw new ToolCallException('Heruntergeladen wird nur über https://.');
			}

			$content = $this->download($url, $maxSize);
		}

		if ($maxSize > 0 && \strlen($content) > $maxSize) {
			throw new ToolCallException(sprintf('Die Datei ist %d Bytes groß; erlaubt sind höchstens %d (maxFileSize).', \strlen($content), $maxSize));
		}

		return $content;
	}

	/**
	 * Lädt eine Datei von einer öffentlichen HTTPS-Adresse.
	 *
	 * @param string $url     Die Adresse
	 * @param int    $maxSize Höchstgröße in Bytes, 0 ohne Grenze
	 *
	 * @return string Der Inhalt
	 *
	 * @throws ToolCallException Wenn der Abruf scheitert
	 */
	private function download(string $url, int $maxSize): string
	{
		$client = new NoPrivateNetworkHttpClient($this->httpClient ?? HttpClient::create());

		try {
			$response = $client->request('GET', $url, array(
				'timeout' => self::DOWNLOAD_TIMEOUT,
				'max_duration' => self::DOWNLOAD_TIMEOUT * 2,
				'max_redirects' => 3,
				'headers' => array('User-Agent' => 'Contao-Claude-Bundle'),
			));

			if (200 !== $response->getStatusCode()) {
				throw new ToolCallException(sprintf('Die Adresse antwortet mit HTTP %d.', $response->getStatusCode()));
			}

			$content = '';

			foreach ($client->stream($response) as $chunk) {
				$content .= $chunk->getContent();

				if ($maxSize > 0 && \strlen($content) > $maxSize) {
					$response->cancel();

					throw new ToolCallException(sprintf('Die Datei ist größer als die erlaubten %d Bytes (maxFileSize).', $maxSize));
				}
			}

			return $content;
		} catch (ToolCallException $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new ToolCallException('Herunterladen fehlgeschlagen: '.$exception->getMessage());
		}
	}
}
