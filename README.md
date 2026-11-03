# Claude-Bundle für Contao

Stellt eine Contao-Installation als **MCP-Server** bereit. Claude – claude.ai im
Browser, die Desktop- und Handy-App und Claude Code – liest und pflegt dann Seiten,
Artikel, Inhaltselemente, Nachrichten, Dateien und beliebige Datensätze über
eine Schnittstelle statt über die Backend-Oberfläche. Jeder Redakteur meldet
sich mit seinem eigenen Backend-Zugang an, und Claude bekommt genau dessen
Rechte, nie mehr.

Läuft mit **einem** Code-Stand unter Contao 4.13 und Contao 5.7.

> **Stand: Fassung 0.5.** Lesende und schreibende Werkzeuge, Anmeldung über
> OAuth 2.1 mit dem eigenen Backend-Zugang, Protokoll aller Schreibvorgänge und
> ein Claude-Button in Backend und Frontend-Vorschau.

## Voraussetzungen

- Contao 4.13 oder 5.3 bis 5.7
- PHP 8.1 oder neuer
- Für claude.ai, Desktop und Handy: die Installation muss **öffentlich per
  HTTPS** erreichbar sein, denn diese Anfragen kommen aus Anthropics
  Rechenzentrum (`160.79.104.0/21`). Claude Code verbindet sich direkt vom
  eigenen Rechner. Deshalb keine IP-Sperre auf Anthropic-Adressen.
- Der Webserver muss `/.well-known/oauth-protected-resource…` und
  `/.well-known/oauth-authorization-server` an Contao durchreichen. Manche
  Konfigurationen sperren Pfade mit Punkt am Anfang.
- Liegt die Seite hinter einem HTTP-Basic-Login (`AuthType Basic`), müssen
  `/mcp`, `/mcp/token`, `/mcp/revoke` und die beiden `/.well-known/…`-Adressen
  davon ausgenommen sein. Claudes Server kann keinen Basic-Login beantworten;
  er bekommt sonst statt der OAuth-Metadaten nur ein 401 des Webservers, und
  claude.ai meldet „Registrierung beim Anmeldedienst fehlgeschlagen“. Die
  Bestätigungsseite unter `/contao/…` darf geschützt bleiben, sie öffnet der
  Browser. Für Apache 2.4 (`THE_REQUEST`, weil Contao intern auf `index.php`
  umleitet):

  ```apache
  <If "%{THE_REQUEST} =~ m#^[A-Z]+ /(mcp(/token|/revoke)?|\.well-known/oauth-(protected-resource(/mcp)?|authorization-server))[ ?]#">
      Require all granted
  </If>
  ```

Das Bundle bringt das offizielle MCP-SDK für PHP (`mcp/sdk`) mit. Es beherrscht
beide Protokollfassungen auf demselben Endpunkt: das Verfahren mit
`initialize`-Handschlag (bis 2025-11-25) und das zustandslose ab 2026-07-28.

## Installation

Über den Contao Manager oder per Composer:

```bash
composer require schachbulle/contao-claude-bundle
```

Danach die Datenbank aktualisieren (Contao Manager bzw. Installtool, oder
`contao:migrate`). Das Bundle legt die Tabelle `tl_claude_token` an.

## Einrichtung

Im Backend unter **System → MCP-Server** (nur für Administratoren):

| Einstellung | Bedeutung |
| --- | --- |
| MCP-Server eingeschaltet | Notschalter. Ohne Haken antworten `/mcp` und alle OAuth-Endpunkte mit 503. Nach der Installation ist er aus. |
| Erlaubte Benutzergruppen | Nur Mitglieder dieser Gruppen dürfen Claude verbinden. Ohne Auswahl dürfen alle. |
| Administratoren dürfen sich verbinden | Ohne Haken weist die Anmeldung Administrator-Zugänge ab. |
| Schreibende Werkzeuge freigeben | Claude darf anlegen und ändern, soweit der Backend-Zugang das darf. Ohne Haken bleibt die Installation lesend. |
| Veröffentlichen erlauben | Schaltet `publish_toggle` frei. Ohne Haken bleibt Veröffentlichen Handarbeit im Backend. |
| Löschen erlauben | Schaltet `record_delete` frei (wiederherstellbar über System → Wiederherstellen). |
| Hausregeln für Claude | Text, den Claude beim Verbindungsaufbau als Anweisung bekommt, etwa „E-Mail-Adressen immer als `{{email::adresse}}`“. Auch der Ort für Wissen über eigene Erweiterungen: welche Tabelle was enthält (etwa „Meisterlisten stehen in tl_championslists_items, ein Eintrag je Jahr“), damit Claude sie mit den allgemeinen Werkzeugen richtig nutzt. |
| Zusätzlich gesperrte Tabellen | Durch Komma getrennt; `tl_foo_*` sperrt alle Tabellen mit diesem Anfang. |
| Testschlüssel | Ein fester Schlüssel für einen gewählten Benutzer, zum Ausprobieren mit Claude Code und dem MCP Inspector (siehe unten). |

Die Maske zeigt oben die Adresse des Endpunkts, etwa `https://www.example.org/mcp`.

**Empfehlung:** Für Claude eine eigene Benutzergruppe mit eingeschränkten Rechten
anlegen (etwa nur Artikel, Nachrichten und Dateien) und nur diese erlauben.

## Claude verbinden

### claude.ai, Desktop, Handy

In Claude unter **Einstellungen → Connectors → Benutzerdefinierten Connector
hinzufügen** die Adresse `https://www.example.org/mcp` eintragen. Eine Client-ID
ist nicht nötig. Claude öffnet die Anmeldung von Contao. Wer noch nicht im
Backend angemeldet ist, meldet sich dort an, auch mit Zwei-Faktor-Abfrage.
Anschließend bestätigt er auf einer Seite, die Anwendung, Rücksprung, Zugang
und Rechte nennt.

### Claude Code

```bash
claude mcp add --transport http contao https://www.example.org/mcp
```

Danach in Claude Code `/mcp` aufrufen und die Anmeldung im Browser bestätigen.

### Verbindungen verwalten

Unter **System → Claude-Verbindungen** stehen die Verbindungen mit Anwendung,
Zeitpunkten und Status; dort lassen sie sich widerrufen. Benutzer sehen ihre
eigenen Verbindungen, Administratoren alle. Das Modul folgt den normalen
Modulrechten, also der Gruppe der Claude-Nutzer das Modul freigeben.

### Testschlüssel (ohne OAuth)

Zum Ausprobieren ohne Anmeldung: In den Einstellungen einen Benutzer wählen und
„Neuen Testschlüssel erzeugen“ anhaken. Der Schlüssel wird nach dem Speichern
**einmal** angezeigt und nur als SHA-256-Hash gespeichert.

```bash
claude mcp add --transport http contao-test https://www.example.org/mcp --header "Authorization: Bearer <Testschlüssel>"
```

Prüfen mit dem MCP Inspector (`npx @modelcontextprotocol/inspector`):
Transport „Streamable HTTP“, Adresse des Endpunkts, Header
`Authorization: Bearer <Testschlüssel>`.

## Anmeldung über OAuth 2.1

Das Bundle bringt einen eigenen, schlanken Autorisierungsserver mit, ohne
zusätzliche OAuth- oder JWT-Bibliothek.

| Adresse | Zweck |
| --- | --- |
| `/mcp` | MCP-Endpunkt. Ohne Schlüssel `401` mit `WWW-Authenticate: Bearer resource_metadata="…", scope="contao:read"` |
| `/.well-known/oauth-protected-resource/mcp` (auch ohne `/mcp`) | Metadaten der Ressource (RFC 9728) |
| `/.well-known/oauth-authorization-server` | Metadaten des Autorisierungsservers (RFC 8414) |
| `/contao/mcp/authorize` | Bestätigungsseite hinter dem Backend-Login |
| `/mcp/token` | Code-Tausch (`authorization_code`) und Erneuerung (`refresh_token`) |
| `/mcp/revoke` | Widerruf (RFC 7009) |

- **Clients:** Zugelassen sind nur die Claude-Apps und Claude Code. Beide weisen
  sich mit einem *Client ID Metadata Document* von Anthropic aus
  (`https://claude.ai/oauth/mcp-oauth-client-metadata`,
  `https://claude.ai/oauth/claude-code-client-metadata`). Andere Clients werden
  abgewiesen; fremde Adressen ruft der Server nie ab.
- **Rücksprung:** `https://claude.ai/api/mcp/auth_callback` exakt; für Claude
  Code `http://localhost/callback` bzw. `http://127.0.0.1/callback` mit
  beliebigem Port.
- **PKCE** mit S256 ist Pflicht, ein Geheimnis gibt es nicht (öffentlicher Client).
- **Schlüssel** sind zufällig (32 Byte) und liegen nur als SHA-256-Hash in der
  Datenbank. Code 10 Minuten, Zugriffsschlüssel 1 Stunde, Erneuerungsschlüssel
  30 Tage ab der letzten Erneuerung. Jede Erneuerung gibt beide Schlüssel neu
  aus. Ein zweimal vorgelegter Code widerruft die Verbindung.
- Jeder Schlüssel gilt nur für die Ressource (Domain), für die er ausgestellt
  wurde.
- Die Regeln, wer sich verbinden darf, gelten bei jeder Anfrage. Wer aus der
  erlaubten Gruppe fällt, verliert den Zugang sofort.

## Werkzeuge

### Lesen

Als lesend gekennzeichnet (`readOnlyHint`), immer verfügbar.

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

Die Ausgabe ist für Claude aufbereitet:

- Dateifelder als `{uuid, path}`
- Datumsfelder als ISO 8601
- Checkboxen als `true`/`false`
- serialisierte Felder als Listen
- Contaos Ersatzschreibweisen (`[nbsp]`, `[lt]` …) als echte Entities

Volle Datensätze zeigen nur die Felder der Palette, die das Backend für den
jeweiligen Typ zeigt.

### Schreiben

Nur bei gesetztem Haken „Schreibende Werkzeuge freigeben“ und nur für
Verbindungen mit dem Recht `contao:write`. Wer schon vor dem Einschalten
verbunden war, muss die Verbindung einmal trennen und neu verbinden.

| Werkzeug | Tut |
| --- | --- |
| `content_create` | Inhaltselement in einem Artikel, einer Nachricht, einem Termin … anlegen, am Ende, am Anfang oder hinter einem Element |
| `content_update` | Felder eines Inhaltselements ändern, auch den Typ |
| `news_create` | Nachricht anlegen, auf Wunsch gleich mit Textelement |
| `news_update` | Felder einer Nachricht ändern |
| `record_save` | Datensatz einer beliebigen Tabelle anlegen (ohne `id`) oder ändern (mit `id`), auch in eigenen Erweiterungen |
| `record_restore` | Frühere Version wiederherstellen (Versionen liefert `record_history`) |
| `file_upload` | Neue Datei in einen vorhandenen Ordner legen, als Base64 oder von einer öffentlichen https-Adresse, auf Wunsch mit Titel und Alternativtext |
| `file_meta_update` | Titel, Alternativtext, Bildunterschrift, Link einer Datei für eine Sprache setzen |
| `publish_toggle` | Veröffentlichen oder verstecken – nur mit Haken „Veröffentlichen erlauben“ |
| `record_delete` | Löschen samt Kinddatensätzen, wiederherstellbar über System → Wiederherstellen – nur mit Haken „Löschen erlauben“ |

**Gespeichert wird wie im Backend.** Die Werkzeuge nutzen Contaos eigenes
`DC_Table` (Anlegen, Speichern, Löschen). Dadurch laufen dieselben Schritte wie
beim Klick auf „Speichern“:

- `onload_callback` mit den Rechteprüfungen, auch denen eigener Erweiterungen
- Prüfung jedes Wertes durch sein Widget: Pflichtfeld, Format, Optionen, Länge
- `load_callback` und `save_callback`, etwa die Alias-Erzeugung
- `onsubmit_callback`, neue Version, Leeren der Cache-Tags
- unter Contao 5 die DataContainer-Voter

Was Contao ablehnt, kommt mit Contaos Meldung bei Claude an („Bitte füllen Sie
das Feld "Titel" aus!“). Jeder Vorgang läuft in einer Datenbank-Transaktion:
Scheitert ein Feld, bleibt der Datensatz, wie er war.

**Regeln, die strenger sind als das Backend:**

- Neues entsteht immer unveröffentlicht. Inhaltselemente unter einer
  unveröffentlichten Nachricht oder einem unveröffentlichten Artikel entstehen
  sichtbar (sie erscheinen mit dem Elternelement), unter einem veröffentlichten
  versteckt.
- `published` und `invisible` ändert nur `publish_toggle`.
- Nur Felder der Palette, die für den Datensatz gilt; Typ- und Auswahlfelder
  werden zuerst gesetzt, damit die Palette stimmt.
- Vor dem Löschen sucht das Bundle nach Verweisen (Insert-Tags,
  Weiterleitungen, eingebundene Elemente, mitgelöschte Kinddatensätze) und
  löscht nur nach ausdrücklicher Bestätigung.
- Dateien: nie überschreiben, kein SVG, nur die Dateitypen und die Größe aus
  den Einstellungen, Download nur über https und nur von öffentlichen Adressen.
  Neue Ordner legt ein Mensch an. Große Bilder werden nicht verkleinert.
- Werte kommen in der Form von `record_get` zurück: ISO-Datum, Dateipfad statt
  UUID, `true`/`false`. Das Bundle wandelt sie in das Format der Installation.

**Protokoll:** Jeder Schreibversuch, auch ein abgewiesener, steht unter
**System → Claude-Protokoll**: Zeit, Benutzer, Verbindung, Werkzeug, Tabelle,
ID, Feldnamen, Ergebnis. Inhalte nicht; die zeigen die Versionen. Wer nicht
Administrator ist, sieht nur die eigenen Einträge. Einträge werden nach
180 Tagen gelöscht.

## Claude-Button

Wer Claude verbinden darf (Server eingeschaltet, erlaubte Gruppe,
Administratoren nur mit Schalter), sieht einen Knopf **Claude**:

- im Backend in der Kopfzeile vor „Vorschau“,
- im Frontend unten rechts, solange die Vorschau läuft (`preview.php` mit
  angemeldetem Backend-Benutzer). Besucher sehen ihn nie.

Ein Klick öffnet das Feld „Was soll Claude tun?“. Beim Abschicken (oder mit
Strg+Enter) entsteht eine Anfrage aus dem Kontext und der Eingabe, etwa:

```text
Nutze den Contao-Konnektor der Installation www.example.org.
Backend-Modul: Nachrichten (do=news)
Wird gerade bearbeitet: tl_news 123 „Titel“ in tl_news_archive 3 „Aktuelles“
Adresse: https://www.example.org/contao?do=news&table=tl_news&id=123&act=edit

Schreib einen Teaser für diese Nachricht
```

Die Anfrage kommt in die Zwischenablage, und `claude.ai/new` öffnet sich in
einem eigenen Fenster, vorausgefüllt über `?q=`. Diesen Parameter hat Anthropic
nicht dokumentiert; übernimmt claude.ai ihn nicht, genügt Einfügen. Namen von
Datensätzen stehen nur im Kontext, wenn der Benutzer sie auch über die
Werkzeuge lesen dürfte. Das Request-Token der Backend-Adresse wird entfernt.

Skript und Stil liegen unter `public/bundles/contaoclaude/`. Nach einem Update
des Bundles legt Contao sie beim Cache-Leeren bzw. über
`contao-console assets:install` neu ab.

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
- **Schreiben:** Feldfreigaben („Erlaubte Felder“) wie im Backend. In Contao 5
  ist ein Feld ohne `exclude`-Angabe gesperrt, bis die Gruppe es freigibt; dort
  gelten zusätzlich die **Tabellenrechte** (Anlegen, Bearbeiten, Löschen) und
  die erlaubten Elementtypen. Für Dateien die Rechte „Dateien hochladen“ bzw.
  „Dateien bearbeiten“ der Dateiverwaltung.
- **Bankdaten:** Felder `iban` und `bic` gibt das Bundle in keiner Tabelle aus und
  lässt sie nicht schreiben, auch für Administratoren.
- **Meisterlisten** (`schachbulle/contao-championslists-bundle`): Wie im Backend
  nur die Listen, die die Benutzergruppe freigibt.
- **Sperrliste:** Immer gesperrt sind, auch für Administratoren:
  - Benutzer, Gruppen und Mitglieder
  - Einstellungen, Versionen, Wiederherstellung, Protokolle und Cronjobs
  - Newsletter-Empfänger und Formular-Einsendungen (`tl_lead*`)
  - der Suchindex und die Tabellen dieses Bundles

Beim Lesen kennen Tabellen eigener Erweiterungen nur Modulrecht und Sperrliste.
Eigene Rechte solcher Erweiterungen (etwa „nur Archiv A“) prüft Contao in deren
`onload_callback`; der läuft beim Schreiben mit, beim Lesen nicht. Solche
Tabellen bei Bedarf sperren.

## Hinweise zum Server

- Der Header `Authorization` muss PHP erreichen. Auf manchen
  Apache-Konfigurationen braucht es dafür in der `.htaccess`:
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
- Alle Antworten von `/mcp` und den OAuth-Endpunkten tragen `Cache-Control:
  no-store, private`; der Seitencache speichert sie nicht.
- Die Metadatendokumente von Claude ruft der Server einmal am Tag von claude.ai
  ab (ausgehendes HTTPS). Ist claude.ai nicht erreichbar, gelten die im Bundle
  hinterlegten Rücksprungadressen.
- Protokollsitzungen des älteren MCP-Verfahrens liegen als Dateien unter
  `var/claude/mcp-sessions` (nur Protokolldaten, keine Inhalte, 8 Stunden gültig).
- Ein täglicher Cronjob räumt verfallene Codes und alte, widerrufene oder
  abgelaufene Verbindungen ab.

## Entwicklung

```bash
/c/xampp/php/php.exe C:/Users/samso/bin/composer.phar install --no-plugins --no-security-blocking
```

```bash
/c/xampp/php/php.exe vendor/bin/phpunit
```

Prüfstand gegen eine Testinstallation in `pruefstand/`:

- Testschlüssel einrichten und Testdaten anlegen
- Proben als MCP-Client und Gegenprobe mit dem SDK-Client
- die OAuth-Anmeldung wie Claude Code (`oauth-probe.php`)

Die Aufrufe stehen jeweils im Dateikopf.

## Lizenz

LGPL-3.0-or-later
