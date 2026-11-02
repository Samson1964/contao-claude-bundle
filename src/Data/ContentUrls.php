<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Data;

use Contao\ArticleModel;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Events;
use Contao\Model;
use Contao\News;
use Contao\NewsModel;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Erzeugt Live- und Vorschauadressen für Seiten, Artikel, Nachrichten und
 * Termine – die einzige Stelle des Bundles mit einer Weiche nach
 * Contao-Fassung.
 *
 * Contao 5 hat dafür den Dienst contao.routing.content_url_generator; die
 * alten Wege (PageModel::getAbsoluteUrl(), News::generateNewsUrl()) sind
 * dort ab 5.3 veraltet. Contao 4.13 kennt den Dienst nicht und braucht die
 * alten Wege. Der Dienst wird optional eingespeist und ist unter 4.13 null.
 */
class ContentUrls
{
	/**
	 * Tabellen, für die Adressen erzeugt werden können, mit ihrer Modelklasse.
	 */
	public const TABLES = array(
		'tl_page' => PageModel::class,
		'tl_article' => ArticleModel::class,
		'tl_news' => NewsModel::class,
		'tl_calendar_events' => CalendarEventsModel::class,
	);

	/**
	 * @param ContaoFramework $framework           Für die Model-Adapter
	 * @param string          $previewScript       Parameter contao.preview_script, etwa „/preview.php“
	 * @param object|null     $contentUrlGenerator contao.routing.content_url_generator (nur Contao 5)
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly string $previewScript,
		private readonly ?object $contentUrlGenerator = null,
	) {
	}

	/**
	 * Lädt den Datensatz als Contao-Model.
	 *
	 * @param string $table Eine Tabelle aus self::TABLES
	 * @param int    $id    ID des Datensatzes
	 *
	 * @return Model|null Das Model, oder null, wenn es den Datensatz nicht gibt
	 */
	public function findModel(string $table, int $id): ?Model
	{
		$this->framework->initialize();

		$class = self::TABLES[$table] ?? null;

		if (null === $class || !class_exists($class)) {
			return null;
		}

		return $this->framework->getAdapter($class)->findByPk($id);
	}

	/**
	 * Erzeugt die öffentliche Adresse eines Datensatzes.
	 *
	 * @param Model $model Seite, Artikel, Nachricht oder Termin
	 *
	 * @return string Die absolute Adresse
	 *
	 * @throws \RuntimeException Wenn Contao keine Adresse bilden kann, etwa
	 *                           weil das Archiv keine Weiterleitungsseite hat
	 */
	public function getLiveUrl(Model $model): string
	{
		if (null !== $this->contentUrlGenerator) {
			return (string) $this->contentUrlGenerator->generate($model, array(), UrlGeneratorInterface::ABSOLUTE_URL);
		}

		if ($model instanceof PageModel) {
			$model->loadDetails();

			return $model->getAbsoluteUrl();
		}

		if ($model instanceof ArticleModel) {
			$page = $this->framework->getAdapter(PageModel::class)->findWithDetails((int) $model->pid);

			if (null === $page) {
				throw new \RuntimeException('Die Seite des Artikels fehlt.');
			}

			return $page->getAbsoluteUrl('/articles/'.($model->alias ?: $model->id));
		}

		if ($model instanceof NewsModel) {
			return (string) $this->framework->getAdapter(News::class)->generateNewsUrl($model, false, true);
		}

		if ($model instanceof CalendarEventsModel) {
			return (string) $this->framework->getAdapter(Events::class)->generateEventUrl($model, true);
		}

		throw new \RuntimeException('Für diesen Datensatz kann keine Adresse erzeugt werden.');
	}

	/**
	 * Setzt das Vorschauskript in eine Live-Adresse ein.
	 *
	 * Aus „https://example.org/news/titel.html“ wird
	 * „https://example.org/preview.php/news/titel.html“ – so baut auch das
	 * Contao-Backend seine Vorschauverweise. Die Vorschau zeigt
	 * Unveröffentlichtes nur, wenn der Browser im Backend angemeldet ist.
	 *
	 * @param string $liveUrl Die absolute Live-Adresse
	 *
	 * @return string|null Die Vorschauadresse, oder null, wenn die
	 *                     Installation kein Vorschauskript hat
	 */
	public function getPreviewUrl(string $liveUrl): ?string
	{
		$script = '/'.ltrim($this->previewScript, '/');

		if ('/' === $script || !preg_match('#^(https?://[^/]+)(/.*)?$#i', $liveUrl, $match)) {
			return null;
		}

		$path = $match[2] ?? '/';

		if (str_starts_with($path, $script.'/') || $path === $script) {
			return $liveUrl;
		}

		return $match[1].$script.$path;
	}
}
