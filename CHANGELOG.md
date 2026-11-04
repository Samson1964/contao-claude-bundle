# Changelog

## Version 0.5.2 (2026-11-04)

* Add: System → Claude-Verbindungen zeigt aufklappbar die Adresse für den Konnektor, die Schritte in claude.ai und die bekannten Fehlerbilder beim Verbinden
* Add: README-Abschnitt „Wenn die Verbindung nicht klappt“
* Fix: Das Claude-Symbol in der Backend-Kopfzeile hat die Linkfarbe wie Handbuch und Hinweise; in Contao 4.13 war es auf dem orangen Grund dunkel

## Version 0.5.1 (2026-11-04)

* Add: Bricht Contao beim Start für `/mcp` ab (etwa durch eine Weiterleitung zur Backend-Anmeldung aus einer anderen Erweiterung), antwortet `/mcp` mit 500 und nennt Datei und Zeile des Verursachers; der vollständige Ablauf steht im System-Log
* Add: Stellen im zusammengefassten Konfigurations-Cache (`var/cache/prod/contao/config/config.php`) werden auf die config.php des Bundles zurückgeführt

## Version 0.5.0 (2026-11-03)

* Add: Meisterlisten (schachbulle/contao-championslists-bundle) folgen beim Lesen den freigegebenen Listen der Benutzergruppe, wie im Backend
* Change: IBAN und BIC werden in keiner Tabelle ausgegeben oder geschrieben

## Version 0.4.0 (2026-11-03)

* Add: Claude-Button in der Backend-Kopfzeile (Contao 4.13 und 5.7) und in der Frontend-Vorschau, nur für Benutzer, die Claude verbinden dürfen
* Add: Eingabefeld „Was soll Claude tun?“; die Anfrage enthält Installation, Modul, Datensatz mit Namen und Elterndatensatz sowie die Adresse, kommt in die Zwischenablage und öffnet claude.ai/new
* Add: Skript ohne MooTools und Stimulus, per Ereignis-Delegation auch nach Turbo-Seitenwechseln in Contao 5; Stil für helles und dunkles Backend

## Version 0.3.0 (2026-11-03)

* Add: Schreibende Werkzeuge `content_create`, `content_update`, `news_create`, `news_update`, `record_save`, `record_restore`, `publish_toggle`, `record_delete`, `file_upload`, `file_meta_update`
* Add: Gespeichert wird über Contaos eigenes DC_Table wie im Backend: onload_callback, Widget-Prüfung, load/save_callback, onsubmit_callback, Versionen, Cache-Tags, tl_undo und unter Contao 5 die DataContainer-Voter; jeder Vorgang in einer Transaktion
* Add: Einstellungen „Schreibende Werkzeuge freigeben“, „Veröffentlichen erlauben“ und „Löschen erlauben“ (alle standardmäßig aus); Scope `contao:write` für Verbindungen, die nach dem Einschalten entstehen
* Add: Neues entsteht immer unveröffentlicht; Sichtbarkeit ändert nur `publish_toggle`
* Add: Verweissuche vor dem Löschen (Insert-Tags, Weiterleitungen, eingebundene Elemente, Kinddatensätze)
* Add: Backend-Modul System → Claude-Protokoll (`tl_claude_log`) mit allen Schreibversuchen, Aufbewahrung 180 Tage
* Change: `/mcp` gilt für Contao nach der Anmeldung als Backend-Anfrage, damit Insert-Tags beim Speichern nicht maskiert werden
* Fix: Feldrechte unter Contao 5: Felder ohne `exclude`-Angabe gelten dort als gesperrt (betraf auch `editable` in `dca_describe`)
* Fix: README nennt die Ausnahme vom HTTP-Basic-Login, ohne die claude.ai „Registrierung fehlgeschlagen“ meldet

## Version 0.2.0 (2026-11-02)

* Add: Anmeldung über OAuth 2.1 mit dem eigenen Backend-Zugang, für claude.ai, die Claude-Apps und Claude Code: Metadaten nach RFC 9728 und RFC 8414, Bestätigungsseite hinter dem Backend-Login, Code-Tausch mit PKCE (S256), Erneuerung mit Rotation, Widerruf nach RFC 7009
* Add: Zugelassen sind nur die Claude-Clients über ihre Client ID Metadata Documents bei claude.ai (Positivliste, keine Registrierung nötig)
* Add: Einstellungen „Erlaubte Benutzergruppen“ und „Administratoren dürfen sich verbinden“; die Regeln gelten bei jeder Anfrage
* Add: Backend-Modul System → Claude-Verbindungen zum Ansehen und Widerrufen der Verbindungen
* Add: Tabelle `tl_claude_token` (nur Hashes der Schlüssel) und täglicher Cronjob zum Aufräumen
* Change: `/mcp` antwortet ohne Schlüssel mit `WWW-Authenticate: Bearer resource_metadata="…", scope="contao:read"`, damit Claude die Anmeldung startet

## Version 0.1.0 (2026-11-02)

* Add: MCP-Endpunkt `/mcp` (Streamable HTTP) auf Basis des offiziellen PHP-SDK `mcp/sdk` 0.9, beide Protokollfassungen (2025-11-25 mit Handschlag, 2026-07-28 zustandslos)
* Add: Anmeldung über einen Testschlüssel aus den Einstellungen, gespeichert nur als SHA-256-Hash; Claude arbeitet mit den Rechten des gewählten Backend-Benutzers
* Add: Backend-Modul System → MCP-Server mit Notschalter, Hausregeln für Claude, zusätzlicher Sperrliste und Testschlüssel (nur Administratoren)
* Add: Lesende Werkzeuge `site_info`, `page_tree`, `article_list`, `content_list`, `news_search`, `news_get`, `file_list`, `dca_describe`, `record_list`, `record_get`, `record_history`, `preview_url`
* Add: Rechteprüfung wie im Backend (Modulrechte, Seiten-, Datei- und Archivfreigaben) und feste Sperrliste für Benutzer, Mitglieder, Einstellungen, Versionen, Protokolle und Formular-Einsendungen
* Add: Lauffähig unter Contao 4.13 und Contao 5.7 mit einem Code-Stand
