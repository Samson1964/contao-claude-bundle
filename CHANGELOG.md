# Changelog

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
