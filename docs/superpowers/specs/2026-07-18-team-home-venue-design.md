# Venue-Zuweisung robuster machen — Team-Home-Court + Event-Court

Status: Approved
Datum: 2026-07-18

## Problem

`bbb-sportspress-sync` legt Venues bisher nur direkt am `sp_event`-Post an
(`maybe_sync_venue()` in `includes/class-bbb-sync-engine.php`), und nur wenn
die BBB-API bereits ein Ergebnis für das Spiel liefert (`$result_str !== null`).
Für zukünftige Spiele ohne Ergebnis bleibt der Court leer — SportsPress' eigener
"Automatisch"-Mechanismus (Court vom Heimteam übernehmen) kann nicht greifen,
weil das Team selbst nie einen Home-Court gepflegt bekommt.

## Technischer Hintergrund (verifiziert gegen SportsPress-Core)

Das Team-Feld "Home" im SportsPress-Team-Editor ist eine Zuordnung zur
`sp_venue`-Taxonomie direkt am `sp_team`-Post, mit `multiple => true`
(`SP_Meta_Box_Team_Details::output()`,
`includes/admin/post-types/meta-boxes/class-sp-meta-box-team-details.php`
in SportsPress Core). Intern: `wp_set_object_terms( $team_wp_id, $venue_term_ids,
'sp_venue', true )` — ein Team kann mehrere Home-Venues haben, Terms werden
addiert (`$append = true`), nicht ersetzt.

## Verifizierter Ist-Zustand im Plugin

- `maybe_sync_venue( array $match, int $event_wp_id, ?array $boxscore_data )`
  (Zeile 1311 ff.) bricht für Spiele ohne Ergebnis vorzeitig ab:
  ```php
  if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
      if ( $result_str === null ) return;   // Zeile 1339
      ...
  }
  ```
- `sync_event()` (Zeile 808 ff.) kennt `$home_wp_id` bereits (Zeile 839), gibt
  es aber nicht an `maybe_sync_venue()` weiter (Aufruf Zeile 1075: nur
  `$match, $wp_id, $boxscore_data`).
- `find_venue_by_spielfeld_id()`, `build_venue_address()`,
  `set_venue_meta()`, `maybe_geocode_venue()` bleiben unverändert.
- Bestehendes Transient-Muster im Plugin (`bbb_sync_progress`,
  `bbb_github_update_*`) — neues Caching folgt diesem Stil.
- Kein PHPUnit-/Composer-Setup im Repo vorhanden — wird in diesem Feature neu
  eingeführt (siehe Testkonzept).

## Entschiedene offene Fragen

1. **Team-Scope für Home-Venue:** Gilt für **alle** Heimteams (nicht nur
   `_bbb_is_own_team = 1`). Begründung: auch gegnerische Teams profitieren von
   einem gepflegten Home-Court, falls SportsPress später deren Spiele ohne
   API-Daten automatisch auflöst.
2. **Retry-Intervall für Early-Return-Fix:** 24 Stunden Transient-Cache pro
   `match_id`, wenn die matchInfo-API (noch) keinen Spielort liefert. Verhindert
   wiederholte erfolglose API-Calls bei jedem Sync-Lauf.
3. **Test-Ansatz:** Brain Monkey (WP-Funktions-Mocking ohne echte
   WP-Installation/DB) wird neu ins Projekt eingeführt, um TDD für die neue
   Logik zu ermöglichen.

## Design

### 1. Signaturänderung `maybe_sync_venue()`

```php
private function maybe_sync_venue(
    array $match, int $event_wp_id, int $home_wp_id, ?array $boxscore_data = null
): void
```

Aufrufstelle in `sync_event()` (aktuell Zeile 1075) übergibt zusätzlich
`$home_wp_id` (dort bereits als Variable vorhanden, Zeile 839).

### 2. Early-Return-Fix + Caching

Ersetzt den Guard bei `$result_str === null` durch einen Transient-Check
*vor* dem `get_match_info()`-Call:

```php
$cache_key = "bbb_venue_lookup_failed_{$match_id}";
if ( get_transient( $cache_key ) ) return;

$match_info = $this->api->get_match_info( $match_id );
$this->stats['api_calls']++;
if ( is_wp_error( $match_info ) ) {
    $this->stats['errors']++;
    return;
}
$this->api->throttle();

// ... Dual-Format-Parsing wie bisher ...

if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
    set_transient( $cache_key, true, DAY_IN_SECONDS );
    return;
}
```

Kein `delete_transient()` bei Erfolg nötig: der Cache-Key wird nur gesetzt,
wenn kein Spielfeld gefunden wurde; ist eines gefunden, läuft die Methode
regulär weiter und der (nie gesetzte oder abgelaufene) Transient bleibt
irrelevant.

### 3. Neue Methode `sync_team_home_venue()`

```php
private function sync_team_home_venue( int $team_wp_id, int $venue_term_id ): void {
    $existing = wp_get_object_terms( $team_wp_id, 'sp_venue', [ 'fields' => 'ids' ] );
    if ( is_wp_error( $existing ) ) return;
    if ( in_array( $venue_term_id, $existing, true ) ) return;
    wp_set_object_terms( $team_wp_id, $venue_term_id, 'sp_venue', true ); // append
}
```

Aufruf in `maybe_sync_venue()` unmittelbar vor der bestehenden
Event-Zuweisungszeile (aktuell Zeile 1412):

```php
$this->sync_team_home_venue( $home_wp_id, $venue_term_id );
wp_set_object_terms( $event_wp_id, $venue_term_id, 'sp_venue', false );
```

Gilt für alle Heimteams, kein `is_own`-Filter.

### 4. Testkonzept (TDD, Brain Monkey)

Neues Setup:
- `composer.json` mit `brain/monkey`, `phpunit/phpunit` (dev-Dependencies)
- `phpunit.xml` (Unit-Test-Suite, kein DB-Bootstrap nötig)
- `tests/bootstrap.php` (Brain Monkey Setup/Teardown)
- `tests/unit/SyncTeamHomeVenueTest.php`
- `tests/unit/MaybeSyncVenueCachingTest.php` (oder gemeinsame Testklasse)

Testfälle, **vor** der Implementierung geschrieben:

*`sync_team_home_venue()`*
1. Team hat noch keinen `sp_venue`-Term → `wp_set_object_terms` wird mit
   `$append = true` aufgerufen.
2. Team hat bereits genau diesen Venue-Term → `wp_set_object_terms` wird
   **nicht** aufgerufen (kein unnötiger Write).
3. Team hat einen *anderen* Venue-Term → neuer Term wird per Append
   hinzugefügt, alter bleibt (Mock verifiziert `$append = true`, nie `false`).
4. `wp_get_object_terms` liefert `WP_Error` → Methode bricht ab, kein Write.

*Early-Return-Fix / Caching*
5. Kein Transient gesetzt, `$result_str === null`, API liefert kein
   `spielfeld.id` → `set_transient` wird mit `DAY_IN_SECONDS` aufgerufen,
   Methode kehrt zurück ohne Venue-Zuweisung.
6. Transient bereits gesetzt → `get_match_info()` wird **nicht** aufgerufen
   (kein API-Call).
7. `$result_str === null`, API liefert gültiges `spielfeld.id` → Venue wird
   wie gewohnt angelegt/zugewiesen (Regressionstest: Early-Return-Bug bleibt
   behoben).

Diese Tests decken nur die neue/geänderte Logik ab, nicht das gesamte
`class-bbb-sync-engine.php` (kein Big-Bang-Test-Rewrite).

### 5. Dokumentation

`docs/arc42-bbb-sportspress-sync.adoc` wird aktualisiert:
- Abschnitt "BBB_Sync_Engine" (Bausteinbeschreibung): Beschreibung der
  Venue-Sync-Logik um Team-Home-Court-Zuweisung ergänzen.
- Abschnitt "Datenschutz-Regeln": Append-only-Regel für Team-Venues ergänzen
  (analog zur bestehenden "Name nie überschreiben"-Regel für Venues).
- Neuer Abschnitt oder Ergänzung unter "Querschnittliche Konzepte": kurzer
  Hinweis auf das neue Test-Setup (Brain Monkey, `tests/`-Verzeichnis).
- "Versionsverlauf": Eintrag für die neue Version ergänzen.

## Out of Scope

- Kein vollständiger Test-Rewrite bestehender Methoden.
- Keine Änderung an `find_venue_by_spielfeld_id()`, Geocoding oder
  Dual-Format-Parsing-Logik.
- Keine Integrationstests gegen echte WordPress-/SportsPress-Installation
  (wp-phpunit) — nur Unit-Tests mit Brain Monkey.
- Kein UI-/Admin-Page-Feature für manuelle Team-Venue-Verwaltung.

## Test (manuell, nach Implementierung)

- Sync-Lauf für ein Spiel ohne Ergebnis (z. B. ASV Cham U12, aktuell Court
  leer) durchführen.
- Verifizieren: Event hat Court gesetzt, UND das Heimteam hat diesen
  Venue-Term jetzt in seinen `sp_venue`-Terms (Team-Editor, Feld "Home").
- Regressionstest: Team mit bereits manuell gesetztem Home-Court syncen —
  bestehender Wert bleibt erhalten, neuer Wert wird nur ergänzt.
