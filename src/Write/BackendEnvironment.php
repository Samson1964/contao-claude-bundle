<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Write;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\Input;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Lässt die laufende Anfrage für Contao wie eine Backend-Aktion aussehen.
 *
 * DC_Table und die Callbacks der DCA-Dateien lesen ihre Aufgabe aus GET
 * (act, id, pid, mode, do) und den Wert jedes Feldes aus POST. Diese Klasse
 * setzt beides für die Dauer eines Schreibvorgangs über Input::setGet() und
 * Input::setPost() und räumt danach wieder auf.
 *
 * Dazu kommt, was eine echte Backend-Anfrage mitbrächte:
 *
 * - Eine Sitzung. /mcp läuft ohne Sitzung; Contao 5 verlangt aber eine an
 *   der Anfrage (Ablage, neue Datensätze). Sie bekommt eine Sitzung im
 *   Speicher, die mit der Anfrage verfällt und kein Cookie setzt.
 * - Das Request-Token in „rt“. Contao 4.13 prüft es im Konstruktor von
 *   DC_Table bei jedem GET-Parameter „act“.
 * - Die Konstante CURRENT_ID. Contao 4.13 setzt sie im Backend-Modul auf
 *   den Elterndatensatz, und manche onload_callback (tl_content) setzen sie
 *   voraus.
 */
class BackendEnvironment
{
	/**
	 * @param RequestStack           $requestStack Die laufende Anfrage
	 * @param ContaoCsrfTokenManager $csrf         Liefert ein gültiges Request-Token
	 */
	public function __construct(
		private readonly RequestStack $requestStack,
		private readonly ContaoCsrfTokenManager $csrf,
	) {
	}

	/**
	 * Führt eine Aktion mit gesetzten GET- und POST-Werten aus.
	 *
	 * @param array<string, mixed> $get       GET-Parameter, etwa act, id, do
	 * @param array<string, mixed> $post      POST-Werte; FORM_SUBMIT wird ergänzt
	 * @param string               $table     Tabelle für FORM_SUBMIT
	 * @param int|null             $currentId Wert für CURRENT_ID unter 4.13,
	 *                                        meist der Elterndatensatz
	 * @param callable(): mixed    $action    Die Aktion
	 *
	 * @return mixed Das Ergebnis der Aktion
	 */
	public function run(array $get, array $post, string $table, ?int $currentId, callable $action)
	{
		$request = $this->requestStack->getCurrentRequest();

		if (null !== $request && !$request->hasSession()) {
			$request->setSession(new Session(new MockArraySessionStorage()));
		}

		if (isset($get['act'])) {
			$get['rt'] = $this->csrf->getDefaultTokenValue();
		}

		$post['FORM_SUBMIT'] = $table;

		if (null !== $currentId && !\defined('CURRENT_ID')) {
			\define('CURRENT_ID', $currentId);
		}

		$this->apply($get, $post);

		try {
			return $action();
		} finally {
			$this->apply(array_fill_keys(array_keys($get), null), array_fill_keys(array_keys($post), null));
		}
	}

	/**
	 * Legt einen weiteren POST-Wert ab, etwa vor jedem einzelnen Feld.
	 *
	 * @param string $name  Feldname
	 * @param mixed  $value Wert wie aus einem Formular, null entfernt ihn
	 */
	public function setPost(string $name, $value): void
	{
		Input::setPost($name, $value);
	}

	/**
	 * Setzt oder entfernt GET- und POST-Werte.
	 *
	 * @param array<string, mixed> $get  GET-Werte, null entfernt
	 * @param array<string, mixed> $post POST-Werte, null entfernt
	 */
	private function apply(array $get, array $post): void
	{
		foreach ($get as $name => $value) {
			Input::setGet($name, null === $value ? null : (string) $value);
		}

		foreach ($post as $name => $value) {
			Input::setPost($name, $value);
		}
	}
}
