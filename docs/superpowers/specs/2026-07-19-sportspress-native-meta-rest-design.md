# SportsPress-native Meta REST-Registrierung – Design

## Kontext

`BBB_Meta_Registration` (v1.1.10) macht bereits die plugineigenen `_bbb_*`-Meta-Felder
per `register_post_meta()`/`register_term_meta()` REST-sichtbar. Dieses Vorhaben
erweitert dieselbe Idee auf die **nativen SportsPress-Felder**, die dieses Plugin
selbst schreibt (Team-Zuordnung, Spielergebnisse, Spieler-Performance, Team-Stammdaten)
— diese sind ohne explizite Registrierung ebenfalls nicht im REST-`meta`-Objekt
sichtbar, unabhängig vom fehlenden Unterstrich-Prefix (seit WP 4.7 muss jedes
Meta-Feld explizit registriert werden, um in der REST-API zu erscheinen).

## Investigationsergebnisse

Alle nativen `sp_*`-Meta-Key-Verwendungen wurden per `grep` in `includes/`
identifiziert und im Kontext gelesen (`class-bbb-sync-engine.php`,
`class-bbb-player-sync.php`, `class-bbb-admin-page.php`):

- **Spieler-Performance-Stats sind nicht dynamisch als Einzel-Keys gespeichert.**
  Sie liegen verschachtelt in einem einzigen Meta-Key `sp_players` auf `sp_event`
  (`class-bbb-player-sync.php:355-396`), Format
  `[team_wp_id => [player_wp_id => [stat_slug => wert]]]`. Eine Registrierung
  mit `type => 'object'` deckt sämtliche dynamisch konfigurierten Stat-Spalten
  automatisch ab — keine Notwendigkeit, einzelne Stat-Slugs zu kennen oder zu
  registrieren.
- **Venue-Zusatzfelder (`sp_address`, `sp_latitude`, `sp_longitude`) werden von
  SportsPress überwiegend über eine WP-Option `taxonomy_{term_id}` gespeichert**
  (`class-bbb-sync-engine.php:1598-1600, 1729`), nicht als echtes Term-Meta.
  Ein einzelner Fallback-Lesezugriff über `get_term_meta( ..., 'sp_address', ... )`
  existiert zwar (`class-bbb-sync-engine.php:1872`), ist aber inkonsistent mit dem
  primären Schreibpfad. `register_term_meta()` kann Daten aus einer Option nicht
  erfassen. **User-Entscheidung: Venue-Geo-Felder bleiben in diesem Vorhaben
  ausgeschlossen** (separates späteres Vorhaben mit anderer Technik –
  `register_rest_field()` + eigener Options-Getter).
- **Vollständige Feldliste** (aus `grep -rnoE "'sp_[a-z_]+'" includes/`, klassifiziert
  nach Post-Type-Kontext):

  | Post Type | Meta-Key | Typ | single |
  |---|---|---|---|
  | `sp_event` | `sp_team` | integer | false |
  | `sp_event` | `sp_player` | integer | false |
  | `sp_event` | `sp_players` | object | true |
  | `sp_event` | `sp_results` | object | true |
  | `sp_event` | `sp_status` | string | true |
  | `sp_event` | `sp_main_result` | integer | true |
  | `sp_event` | `sp_day` | integer | true |
  | `sp_event` | `sp_format` | string | true |
  | `sp_team` | `sp_abbreviation` | string | true |
  | `sp_team` | `sp_short_name` | string | true |
  | `sp_team` | `sp_url` | string | true |

  Kein zusätzliches Term-Meta in diesem Batch. `sp_result`-Post-Type-Meta
  (`sp_format`/`sp_precision` auf `sp_result`-Posts, d.h. Konfiguration der
  Ergebnis-Spalten selbst, nicht Spieldaten) ist bewusst **nicht** enthalten —
  das ist Admin-Konfiguration, keine für externe Konsumenten relevante Spieldaten.

## User-Entscheidungen

1. **Umfang: auch dynamische Performance-Werte einschließen** — gelöst durch
   Registrierung von `sp_players` als `object`-Typ (keine Einzelregistrierung
   nötig, siehe oben).
2. **Venue-Geo-/Adressfelder: ausgeschlossen** — anderer Speichermechanismus,
   eigenes künftiges Vorhaben.
3. **Code-Struktur: eigene neue Klasse** `BBB_SportsPress_Meta_Registration`,
   nicht als zusätzliche Methode in `BBB_Meta_Registration` — klare
   Verantwortungsgrenze zwischen "unsere `_bbb_*`-IDs" und "SportsPress-eigene
   Felder, die wir zusätzlich REST-sichtbar machen".
4. **Feldliste bestätigt** wie oben, keine weiteren nativen Felder ergänzt.

## Design

**Neue Datei:** `includes/class-bbb-sportspress-meta-registration.php`,
Klasse `BBB_SportsPress_Meta_Registration`. Gleiches Grundmuster wie
`BBB_Meta_Registration`: `defined('ABSPATH') || exit;`-Guard,
`class_exists()`-Guard, Konstruktor hängt sich an `init`:

```php
public function __construct() {
    add_action( 'init', [ $this, 'register_meta' ] );
}
```

**`register_meta()`** registriert die 11 (Post Type, Meta-Key)-Paare aus der
Tabelle oben via `register_post_meta()`. Gemeinsamer `auth_callback` für alle:

```php
'auth_callback' => static function (): bool {
    return current_user_can( 'edit_posts' );
},
```

Lesen bleibt öffentlich sichtbar (WP prüft `auth_callback` nur beim Schreiben
über REST, nicht beim Lesen) — vorausgesetzt der jeweilige Post ist öffentlich
abrufbar (abhängig von SportsPress' eigener `show_in_rest`-Konfiguration für
`sp_event`/`sp_team`, wie bereits in v1.1.10 als Caveat dokumentiert).

**Bekannte Einschränkung für `sp_players`/`sp_results` (`object`-Typ ohne
Schema):** WordPress' REST-Meta-Mechanismus liest `object`-Typ-Meta ohne festes
Schema zuverlässig, verlangt aber beim **Schreiben** über REST i.d.R. ein
`schema` mit `properties` zur Validierung. Da die Stat-Struktur dynamisch pro
Liga/Performance-Konfiguration ist, wird hier bewusst **kein starres Schema**
vorgegeben (YAGNI). Konsequenz: Schreibversuche über REST für genau diese zwei
Felder können von WP abgelehnt werden – das wird im Klassen-Docblock als
bekannte WP-Einschränkung dokumentiert, nicht "gelöst" (kein Custom-Sanitizer,
keine Schema-Erfindung für dynamische Daten).

**Kein Daten-Migrationsschritt** — Werte existieren bereits, nur REST/Schema-
Sichtbarkeit ändert sich.

**Versionsbump:** `1.1.11`.

## Dateien

- **Neu:** `includes/class-bbb-sportspress-meta-registration.php`
- **Neu:** `tests/unit/SportsPressMetaRegistrationTest.php` — mirrort
  `MetaRegistrationTest.php`: `Functions\expect('register_post_meta')->times(11)`,
  Erfassung aller Aufrufe, Assertions je Feld: korrekter `type`, korrektes
  `single` (false für `sp_team`/`sp_player`, sonst true), `show_in_rest === true`,
  `auth_callback` ruft `current_user_can('edit_posts')` korrekt auf.
- **Ändern:** `bbb-sportspress-sync.php` — require + instantiate im
  Core-Classes-Block (gleicher Abschnitt wie `BBB_Meta_Registration`).
- **Ändern:** `tests/bootstrap.php` — require der neuen Klasse.
- **Ändern:** Plugin-Header `Version:` + `BBB_SYNC_VERSION` → `1.1.11`.
- **Ändern:** `README.adoc` — Changelog-Eintrag v1.1.11.
- **Ändern:** `docs/arc42-bbb-sportspress-sync.adoc` — Datenmodell-Mapping-
  Abschnitt um die 11 nativen Felder erweitert, inkl. Hinweis auf die
  Object-Schema-Einschränkung beim Schreiben.

## Verifikation

1. `php -l includes/class-bbb-sportspress-meta-registration.php`
2. `vendor/bin/phpcs` — 0 Violations projektweit.
3. `vendor/bin/phpunit` — komplette Suite grün inkl. neuem Test.
4. Manueller Hinweis (nicht automatisierbar ohne Live-WP): nach Deploy
   `GET /wp-json/wp/v2/sp_event/<id>` prüfen — `meta` sollte jetzt `sp_team`,
   `sp_players`, `sp_results` etc. enthalten, sofern SportsPress selbst
   `show_in_rest` für `sp_event`/`sp_team` gesetzt hat.
