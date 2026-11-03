/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * Claude-Button: öffnet das Feld „Was soll Claude tun?“, setzt Kontext und
 * Eingabe zu einer Anfrage zusammen, kopiert sie in die Zwischenablage und
 * öffnet claude.ai in einem eigenen Fenster.
 *
 * Ein einziger Klick-Beobachter am Dokument (Ereignis-Delegation): Er
 * überlebt den Seitenwechsel durch Turbo im Backend von Contao 5, ohne auf
 * domready oder turbo:load warten zu müssen, und braucht weder MooTools
 * (Contao 4.13) noch Stimulus (Contao 5).
 *
 * @license LGPL-3.0-or-later
 */
(function () {
	'use strict';

	if (window.claudeButtonReady) {
		return;
	}

	window.claudeButtonReady = true;

	var WINDOW_NAME = 'claude-contao';

	/**
	 * Kopiert Text in die Zwischenablage.
	 *
	 * Bevorzugt die Clipboard-API; ohne sichere Verbindung (http) gibt es
	 * sie nicht, dann über ein unsichtbares Textfeld.
	 *
	 * @param {string} text Der Text
	 *
	 * @return {Promise<boolean>} true, wenn das Kopieren geklappt hat
	 */
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).then(function () {
				return true;
			}, function () {
				return copyFallback(text);
			});
		}

		return Promise.resolve(copyFallback(text));
	}

	/**
	 * Kopiert über ein unsichtbares Textfeld und execCommand.
	 *
	 * @param {string} text Der Text
	 *
	 * @return {boolean} true bei Erfolg
	 */
	function copyFallback(text) {
		var area = document.createElement('textarea');
		var ok = false;

		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();

		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}

		document.body.removeChild(area);

		return ok;
	}

	/**
	 * Schließt ein offenes Eingabefeld.
	 */
	function close() {
		var dialog = document.querySelector('.claude-dialog');

		if (dialog) {
			dialog.parentNode.removeChild(dialog);
		}
	}

	/**
	 * Baut ein Element mit Klasse und Text.
	 *
	 * @param {string} tag       Elementname
	 * @param {string} className CSS-Klasse
	 * @param {string} text      Textinhalt, leer für keinen
	 *
	 * @return {HTMLElement} Das Element
	 */
	function element(tag, className, text) {
		var node = document.createElement(tag);

		node.className = className;

		if (text) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * Öffnet das Eingabefeld für einen Button.
	 *
	 * Text wird nur über textContent und value gesetzt, nie als HTML: Der
	 * Kontext enthält Titel von Datensätzen.
	 *
	 * @param {HTMLElement} button Der angeklickte Button
	 */
	function open(button) {
		var labels = {};
		var context = button.getAttribute('data-claude-context') || '';

		try {
			labels = JSON.parse(button.getAttribute('data-claude-labels') || '{}');
		} catch (e) {
			labels = {};
		}

		close();

		var dialog = element('div', 'claude-dialog', '');
		var box = element('form', 'claude-dialog__box', '');
		var title = element('h2', 'claude-dialog__title', labels.title || 'Was soll Claude tun?');
		var input = element('textarea', 'claude-dialog__input', '');
		var details = element('details', 'claude-dialog__context', '');
		var summary = element('summary', '', labels.context || 'Kontext');
		var pre = element('pre', '', context);
		var actions = element('div', 'claude-dialog__actions', '');
		var send = element('button', 'claude-dialog__send', labels.send || 'An Claude senden');
		var cancel = element('button', 'claude-dialog__cancel', labels.cancel || 'Abbrechen');
		var note = element('p', 'claude-dialog__note', '');

		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-label', title.textContent);
		input.rows = 5;
		input.placeholder = labels.placeholder || '';
		send.type = 'submit';
		cancel.type = 'button';

		details.appendChild(summary);
		details.appendChild(pre);
		actions.appendChild(send);
		actions.appendChild(cancel);
		box.appendChild(title);
		box.appendChild(input);
		box.appendChild(details);
		box.appendChild(actions);
		box.appendChild(note);
		dialog.appendChild(box);
		document.body.appendChild(dialog);
		input.focus();

		cancel.addEventListener('click', close);

		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				close();
			}
		});

		dialog.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				close();
			} else if ('Enter' === event.key && (event.ctrlKey || event.metaKey)) {
				event.preventDefault();
				box.requestSubmit ? box.requestSubmit() : send.click();
			}
		});

		box.addEventListener('submit', function (event) {
			event.preventDefault();

			var task = input.value.trim();
			var prompt = context + (task ? '\n\n' + task : '');
			var url = (labels.url || 'https://claude.ai/new') + '?q=' + encodeURIComponent(prompt);

			// Erst öffnen, solange der Klick als Nutzeraktion gilt, sonst
			// blockiert der Browser das Fenster
			window.open(url, WINDOW_NAME);

			copy(prompt).then(function (ok) {
				if (ok) {
					note.textContent = labels.copied || '';
					send.disabled = true;
					window.setTimeout(close, 2500);
				} else {
					note.textContent = labels.notCopied || '';
					pre.textContent = prompt;
					details.open = true;
				}
			});
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-claude-button]') : null;

		if (!button) {
			return;
		}

		event.preventDefault();
		open(button);
	});
})();
