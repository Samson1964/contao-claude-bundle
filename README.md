# Claude-Bundle für Contao

Stellt eine Contao-Installation als **MCP-Server** bereit. Claude (Claude Code,
später auch claude.ai und die Claude-Apps) liest dann Seiten, Artikel,
Inhaltselemente, Nachrichten, Dateien und beliebige Datensätze über eine
Schnittstelle statt über die Backend-Oberfläche – immer mit den Rechten eines
Backend-Benutzers, nie mit mehr.

Läuft mit **einem** Code-Stand unter Contao 4.13 und Contao 5.7.

> **Stand: Ausbaustufe 1.** Nur lesende Werkzeuge, Anmeldung über einen
> Testschlüssel. Anmeldung über OAuth (für claude.ai), schreibende Werkzeuge
> und der Claude-Button im Backend folgen in den nächsten Stufen.

## Voraussetzungen

- Contao 4.13 oder 5.3 bis 5.7
- PHP 8.1 oder neuer
- Die Installation muss für die gewünschten Clients erreichbar sein
  (Claude Code verbindet sich direkt vom eigenen Rechner).

Das Bundle bringt das offizielle MCP-SDK für PHP (`mcp/sdk`) mit. Es beherrscht
beide Protokollfassungen auf demselben Endpunkt: das Verfahren mit
`initialize`-Handschlag (bis 2025-11-25) und das zustandslose ab 2026-07-28.

## Installation

Über den Contao Manager oder per Composer:

```bash
composer require schachbulle/contao-claude-bundle
```

Datenbankänderungen gibt es in dieser Stufe keine.

## Einrichtung

Im Backend unter **System → MCP-Server** (nur für Administratoren):

| Einstellung | Bedeutung |
| --- | --- |
| MCP-Server eingeschaltet | Notschalter. Ohne Haken antwortet `/mcp` mit 503. Nach der Installation ist er aus. |
| Hausregeln für Claude | Text, den Claude beim Verbindungsaufbau als Anweisung bekommt, etwa „E-Mail-Adressen immer als `{{email::adresse}}`“. |
| Zusätzlich gesperrte Tabellen | Durch Komma getrennt; `tl_foo_*` sperrt alle Tabellen mit diesem Anfang. |
| Backend-Benutzer des Testschlüssels | Mit dessen Rechten arbeitet Claude. Leer lassen schaltet den Testschlüssel ab. |
| Neuen Testschlüssel erzeugen | Beim Speichern entsteht ein neuer Schlüssel; er wird **einmal** angezeigt und nur als SHA-256-Hash gespeichert. Der vorige wird ungültig. |

Die Maske zeigt oben die Adresse des Endpunkts, etwa `https://www.example.org/mcp`.

**Empfehlung:** Für Claude einen eigenen Backend-Benutzer mit einer eingeschränkten
Gruppe anlegen (etwa nur Artikel, Nachrichten und Dateien) statt eines Administrators.

### Anbindung in Claude Code

```bash
claude mcp add --transport http contao https://www.example.org/mcp --header "Authorization: Bearer <Testschlüssel>"
```

### Prüfen mit dem MCP Inspector

```bash
npx @modelcontextprotocol/inspector
```

Transport „Streamable HTTP“, Adresse des Endpunkts, Header
`Authorization: Bearer <Testschlüssel>`.

## Werkzeuge

Alle Werkzeuge sind als lesend gekennzeichnet (`readOnlyHint`).

| Werkzeug | Liefert |
| --- | --- |
| `site_info` | Installation, Contao- und PHP-Version, angemeldeter Benutzer mit Gruppen und Modulrechten, erreichbare Wurzelseiten, Nachrichtenarchive und Ordner, Sperrliste |
| `page_tree` | Seitenbaum ab einer Seite mit Alias, Typ, Veröffentlichung, Sichtbarkeit im Menü und Anzeigezeitraum |
| `article_list` | Artikel einer Seite mit Spalte, Status und Zahl der Elemente |
| `content_list` | Inhaltselemente eines Artikels, einer Nachricht oder eines Termins in Reihenfolge; Kurzfassung oder alle Felder |
| `news_search` | Nachrichten nach Archiv, Zeitraum, Suchtext und Status |
| `news_get` | Eine Nachricht mit allen Feldern, Archiv, Autor, Adresse und Inhaltselementen |
| `file_list` | Ordnerinhalt der Dateiverwaltung mit UUID, Größe und Metadaten |
| `dca_describe` | Aufbau einer Tabelle: Felder, Eingabetypen, Optionen, Pflichtangaben, Paletten |
| `record_list` | Datensätze einer beliebigen Tabelle filtern, durchsuchen, sortieren, blättern |
| `record_get` | Ein Datensatz mit den Feldern seiner Palette |
| `record_history` | Versionen eines Datensatzes aus `tl_version`, auch den Inhalt einer Fassung |
| `preview_url` | Öffentliche Adresse und Vorschauadresse von Seite, Artikel, Nachricht oder Termin |

Die Ausgabe ist für Claude aufbereitet: Dateifelder als `{uuid, path}`,
Datumsfelder als ISO 8601, Checkboxen als `true`/`false`, serialisierte Felder
als Listen, Contaos Ersatzschreibweisen (`[nbsp]`, `[lt]` …) als echte Entities.
Volle Datensätze zeigen nur die Felder der Palette, die das Backend für den
jeweiligen Typ zeigt.

## Rechte

Es gelten die Rechte des Backend-Benutzers und seiner Gruppen, wie im Backend:

- **Modulrecht:** Eine Tabelle ist nur lesbar, wenn sie zu einem Backend-Modul
  gehört, das der Benutzer öffnen darf. Den Seitenbaum sieht auch, wer nur
  Artikel pflegen darf.
- **Datensatzrechte:** Seiten und Artikel nur innerhalb der Seitenfreigaben,
  Dateien innerhalb der Dateifreigaben, Nachrichten, Termine, FAQ, Newsletter
  und Formulare nur aus freigegebenen Archiven. Inhaltselemente folgen ihrem
  Elternelement.
- **Felder:** sichtbar ist, was Contaos Detailansicht (`act=show`) zeigt – alle
  Felder außer Kennwörtern und Feldern mit `doNotShow` oder `hideInput`. Die
  Feldfreigaben einer Gruppe regeln nur das Bearbeiten; `dca_describe` meldet sie
  als `editable`.
- **Sperrliste:** Benutzer, Gruppen, Mitglieder, Einstellungen, Versionen,
  Wiederherstellung, Protokolle, Cronjobs, Newsletter-Empfänger,
  Formular-Einsendungen (`tl_lead*`), Suchindex und die Tabellen dieses Bundles
  sind immer gesperrt, auch für Administratoren.

Tabellen eigener Erweiterungen kennen nur Modulrecht und Sperrliste. Eigene
Rechte solcher Erweiterungen (etwa „nur Archiv A“) prüft Contao in deren
`onload_callback`, der hier nicht läuft; solche Tabellen bei Bedarf sperren.

## Hinweise zum Server

- Der Header `Authorization` muss PHP erreichen. Auf manchen
  Apache-Konfigurationen braucht es dafür in der `.htaccess`:
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
- Alle Antworten von `/mcp` tragen `Cache-Control: no-store, private`; der
  Seitencache speichert sie nicht.
- Protokollsitzungen des älteren Verfahrens liegen als Dateien unter
  `var/claude/mcp-sessions` (nur Protokolldaten, keine Inhalte, 8 Stunden gültig).

## Entwicklung

```bash
/c/xampp/php/php.exe C:/Users/samso/bin/composer.phar install --no-plugins --no-security-blocking
```

```bash
/c/xampp/php/php.exe vendor/bin/phpunit
```

Prüfstand gegen eine Testinstallation in `pruefstand/` (Testschlüssel einrichten,
Testdaten anlegen, Proben als MCP-Client, Gegenprobe mit dem SDK-Client); die
Aufrufe stehen jeweils im Dateikopf.

## Lizenz

LGPL-3.0-or-later
