# Team-Home-Venue Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Home-Court eines Teams wird beim Sync automatisch gepflegt (append-only) und der Early-Return-Bug, der die Venue-Ermittlung für Spiele ohne Ergebnis blockiert, wird behoben — inklusive 24h-Cache gegen wiederholte erfolglose API-Calls.

**Architecture:** Zwei neue, isoliert testbare private Methoden in `BBB_Sync_Engine`
(`sync_team_home_venue()`, `is_venue_lookup_cached_as_failed()` /
`cache_venue_lookup_failure()`) werden in die bestehende `maybe_sync_venue()`
eingebaut. `sync_event()` reicht zusätzlich `$home_wp_id` (bereits vorhanden)
an `maybe_sync_venue()` durch.

**Tech Stack:** PHP 8.1+, WordPress/SportsPress Taxonomien (`sp_venue`), Brain
Monkey + PHPUnit für Unit-Tests (neu im Projekt), AsciiDoc für Doku.

**Spec:** `docs/superpowers/specs/2026-07-18-team-home-venue-design.md`

---

## File Structure

- Create: `composer.json` — Dev-Dependencies (Brain Monkey, PHPUnit)
- Create: `phpunit.xml` — Testsuite-Konfiguration
- Create: `tests/bootstrap.php` — Lädt Autoloader, definiert `ABSPATH`/`DAY_IN_SECONDS`, lädt Klasse
- Create: `tests/unit/SmokeTest.php` — Verifiziert, dass Brain Monkey WP-Funktionen mockt
- Create: `tests/unit/SyncTeamHomeVenueTest.php` — Tests für `sync_team_home_venue()`
- Create: `tests/unit/VenueLookupCacheTest.php` — Tests für die Caching-Helper
- Modify: `includes/class-bbb-sync-engine.php` — neue Methoden + Wiring in `maybe_sync_venue()`/`sync_event()`
- Modify: `docs/arc42-bbb-sportspress-sync.adoc` — Bausteinsicht, Datenschutz-Regeln, Querschnittliche Konzepte, Versionsverlauf
- Modify: `README.adoc` — Changelog-Eintrag
- Modify: `bbb-sportspress-sync.php` — Versionsbump `1.1.4` → `1.1.5`
- Modify: `.gitignore` — `vendor/` ausschließen

---

### Task 1: Brain Monkey / PHPUnit Test-Setup

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `tests/unit/SmokeTest.php`
- Modify: `.gitignore`

- [ ] **Step 1: `composer.json` anlegen**

```json
{
    "name": "bbb/sportspress-sync",
    "description": "BBB SportsPress Sync – Dev/Test Dependencies",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require-dev": {
        "php": ">=8.1",
        "brain/monkey": "^2.6",
        "phpunit/phpunit": "^9.6"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] **Step 2: Dependencies installieren**

Run: `composer install`
Expected: `vendor/` wird angelegt, `Installed 2 packages` (oder ähnlich, brain/monkey + phpunit + Transitive) ohne Fehler.

- [ ] **Step 3: `vendor/` in `.gitignore` aufnehmen**

Prüfe zuerst den bestehenden Inhalt der Datei:

Run: `cat .gitignore`

Füge am Ende hinzu (falls nicht schon vorhanden):
```
vendor/
```

- [ ] **Step 4: `phpunit.xml` anlegen**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: `tests/bootstrap.php` anlegen**

```php
<?php
/**
 * PHPUnit Bootstrap für BBB SportsPress Sync Unit-Tests.
 *
 * Lädt Brain Monkey (mockt globale WP-Funktionen) statt einer echten
 * WordPress-Installation. Deckt nur die neue/geänderte Venue-Sync-Logik ab,
 * kein vollständiger Test der gesamten class-bbb-sync-engine.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

require_once __DIR__ . '/../includes/class-bbb-sync-engine.php';
```

- [ ] **Step 6: Smoke-Test schreiben**

```php
<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_brain_monkey_mocks_wp_functions(): void {
        Functions\when( 'get_option' )->justReturn( 'mocked' );

        $this->assertSame( 'mocked', get_option( 'anything' ) );
    }

    public function test_bbb_sync_engine_class_is_loaded(): void {
        $this->assertTrue( class_exists( \BBB_Sync_Engine::class ) );
    }
}
```

- [ ] **Step 7: Smoke-Test laufen lassen**

Run: `vendor/bin/phpunit`
Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock phpunit.xml tests/bootstrap.php tests/unit/SmokeTest.php .gitignore
git commit -m "test: Brain Monkey/PHPUnit Setup für Unit-Tests"
```

---

### Task 2: TDD `sync_team_home_venue()`

**Files:**
- Modify: `includes/class-bbb-sync-engine.php:1310` (neue Methode vor `maybe_sync_venue()` einfügen)
- Test: `tests/unit/SyncTeamHomeVenueTest.php`

- [ ] **Step 1: Failing Tests schreiben**

```php
<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SyncTeamHomeVenueTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_engine(): \BBB_Sync_Engine {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call_sync_team_home_venue( \BBB_Sync_Engine $engine, int $team_wp_id, int $venue_term_id ): void {
        $method = new ReflectionMethod( \BBB_Sync_Engine::class, 'sync_team_home_venue' );
        $method->setAccessible( true );
        $method->invoke( $engine, $team_wp_id, $venue_term_id );
    }

    public function test_adds_venue_when_team_has_no_home_venue_yet(): void {
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->with( 42, 'sp_venue', [ 'fields' => 'ids' ] )
            ->andReturn( [] );

        Functions\expect( 'wp_set_object_terms' )
            ->once()
            ->with( 42, 7, 'sp_venue', true );

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_does_not_write_when_venue_already_assigned(): void {
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( [ 7 ] );

        Functions\expect( 'wp_set_object_terms' )->never();

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_appends_new_venue_keeping_existing_ones(): void {
        // Team hat bereits Venue #3 als Home-Court (z. B. manuell gepflegt).
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( [ 3 ] );

        // Venue #7 wird per append=true ergänzt, #3 bleibt (append überschreibt nicht).
        Functions\expect( 'wp_set_object_terms' )
            ->once()
            ->with( 42, 7, 'sp_venue', true );

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_aborts_on_wp_error(): void {
        Functions\when( 'is_wp_error' )->justReturn( true );

        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( (object) [] );

        Functions\expect( 'wp_set_object_terms' )->never();

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }
}
```

- [ ] **Step 2: Tests laufen lassen, Fehlschlag verifizieren**

Run: `vendor/bin/phpunit tests/unit/SyncTeamHomeVenueTest.php`
Expected: FAIL — `ReflectionException: Method BBB_Sync_Engine::sync_team_home_venue() does not exist`

- [ ] **Step 3: Minimale Implementierung schreiben**

In `includes/class-bbb-sync-engine.php` **vor** Zeile 1311
(`private function maybe_sync_venue(`) einfügen:

```php
    /**
     * Home-Venue eines Teams pflegen (append-only).
     *
     * SportsPress speichert das Team-Feld "Home" als `sp_venue`-Taxonomie-Terms
     * direkt am sp_team-Post (multiple => true). Bestehende, ggf. manuell
     * gepflegte Home-Venues eines Teams dürfen NIE ersetzt werden – nur ergänzt.
     */
    private function sync_team_home_venue( int $team_wp_id, int $venue_term_id ): void {
        $existing = wp_get_object_terms( $team_wp_id, 'sp_venue', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $existing ) ) return;
        if ( in_array( $venue_term_id, $existing, true ) ) return;

        wp_set_object_terms( $team_wp_id, $venue_term_id, 'sp_venue', true );
    }

```

- [ ] **Step 4: Tests laufen lassen, Erfolg verifizieren**

Run: `vendor/bin/phpunit tests/unit/SyncTeamHomeVenueTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/class-bbb-sync-engine.php tests/unit/SyncTeamHomeVenueTest.php
git commit -m "feat: sync_team_home_venue() – Home-Court append-only am Team pflegen"
```

---

### Task 3: TDD Caching-Helper für Early-Return-Fix

**Files:**
- Modify: `includes/class-bbb-sync-engine.php` (zwei neue Methoden, direkt nach `sync_team_home_venue()`)
- Test: `tests/unit/VenueLookupCacheTest.php`

- [ ] **Step 1: Failing Tests schreiben**

```php
<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class VenueLookupCacheTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_engine(): \BBB_Sync_Engine {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call( \BBB_Sync_Engine $engine, string $method, array $args ) {
        $reflection = new ReflectionMethod( \BBB_Sync_Engine::class, $method );
        $reflection->setAccessible( true );
        return $reflection->invokeArgs( $engine, $args );
    }

    public function test_is_cached_as_failed_reads_correct_transient_key(): void {
        Functions\expect( 'get_transient' )
            ->once()
            ->with( 'bbb_venue_lookup_failed_12345' )
            ->andReturn( true );

        $result = $this->call( $this->make_engine(), 'is_venue_lookup_cached_as_failed', [ 12345 ] );

        $this->assertTrue( $result );
    }

    public function test_is_cached_as_failed_returns_false_when_no_transient(): void {
        Functions\expect( 'get_transient' )
            ->once()
            ->andReturn( false );

        $result = $this->call( $this->make_engine(), 'is_venue_lookup_cached_as_failed', [ 12345 ] );

        $this->assertFalse( $result );
    }

    public function test_cache_failure_sets_transient_for_24_hours(): void {
        Functions\expect( 'set_transient' )
            ->once()
            ->with( 'bbb_venue_lookup_failed_12345', true, DAY_IN_SECONDS );

        $this->call( $this->make_engine(), 'cache_venue_lookup_failure', [ 12345 ] );
    }
}
```

- [ ] **Step 2: Tests laufen lassen, Fehlschlag verifizieren**

Run: `vendor/bin/phpunit tests/unit/VenueLookupCacheTest.php`
Expected: FAIL — `ReflectionException: Method BBB_Sync_Engine::is_venue_lookup_cached_as_failed() does not exist`

- [ ] **Step 3: Minimale Implementierung schreiben**

Direkt nach der in Task 2 eingefügten `sync_team_home_venue()`-Methode ergänzen:

```php
    /**
     * Prüft, ob für dieses Match kürzlich erfolglos versucht wurde, den
     * Spielort per matchInfo-API zu ermitteln (24h-Cache gegen API-Last).
     */
    private function is_venue_lookup_cached_as_failed( int $match_id ): bool {
        return (bool) get_transient( "bbb_venue_lookup_failed_{$match_id}" );
    }

    /**
     * Merkt sich für 24h, dass die matchInfo-API für dieses Match keinen
     * Spielort geliefert hat.
     */
    private function cache_venue_lookup_failure( int $match_id ): void {
        set_transient( "bbb_venue_lookup_failed_{$match_id}", true, DAY_IN_SECONDS );
    }

```

- [ ] **Step 4: Tests laufen lassen, Erfolg verifizieren**

Run: `vendor/bin/phpunit tests/unit/VenueLookupCacheTest.php`
Expected: `OK (3 tests, 3 assertions)`

- [ ] **Step 5: Gesamte Testsuite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: `OK (9 tests, ...)` (2 Smoke + 4 Team-Venue + 3 Cache)

- [ ] **Step 6: Commit**

```bash
git add includes/class-bbb-sync-engine.php tests/unit/VenueLookupCacheTest.php
git commit -m "feat: 24h-Cache-Helper gegen wiederholte erfolglose Venue-Lookups"
```

---

### Task 4: Wiring — Early-Return-Fix + Team-Home-Venue in `maybe_sync_venue()`

Diese Änderungen verdrahten die in Task 2/3 getesteten Bausteine in den
bestehenden Ablauf. Sie werden **manuell** verifiziert (Task 5), da ein
vollständiger Mock des `BBB_Api_Client`-Aufrufs außerhalb des Scopes dieses
Plans liegt (siehe Spec, Abschnitt "Out of Scope").

**Files:**
- Modify: `includes/class-bbb-sync-engine.php:1075` (Aufrufstelle in `sync_event()`)
- Modify: `includes/class-bbb-sync-engine.php:1298-1413` (`maybe_sync_venue()`)

- [ ] **Step 1: Docblock + Signatur von `maybe_sync_venue()` anpassen**

Aktuell (Zeile 1298-1311):
```php
    // ═════════════════════════════════════════════
    // VENUE SYNC (v3.5.0: Dual-Format Support)
    // ═════════════════════════════════════════

    /**
     * Sync venue with dual-format matchInfo support.
     *
     * v3.5.0 FIX: matchInfo hat 2 verschiedene Response-Formate:
     *   Format A (höhere Ligen): data.matchInfo.spielfeld { id, bezeichnung, strasse, plz, ort }
     *   Format B (Mini-Liga):    data.ort (String), data.akgId ("U10")
     *
     * Boxscore enthält KEIN spielfeld → immer matchInfo-Endpoint als Venue-Quelle.
     */
    private function maybe_sync_venue( array $match, int $event_wp_id, ?array $boxscore_data = null ): void {
        $match_id   = (int) ( $match['matchId'] ?? 0 );
        $result_str = $match['result'] ?? null;

        // Auch Venue für zukünftige Spiele setzen (aus match-Daten)
        if ( ! $match_id ) return;
```

Neu:
```php
    // ═════════════════════════════════════════════
    // VENUE SYNC (v3.5.0: Dual-Format Support, v1.1.5: Team-Home-Venue)
    // ═════════════════════════════════════════

    /**
     * Sync venue with dual-format matchInfo support.
     *
     * v3.5.0 FIX: matchInfo hat 2 verschiedene Response-Formate:
     *   Format A (höhere Ligen): data.matchInfo.spielfeld { id, bezeichnung, strasse, plz, ort }
     *   Format B (Mini-Liga):    data.ort (String), data.akgId ("U10")
     *
     * Boxscore enthält KEIN spielfeld → immer matchInfo-Endpoint als Venue-Quelle.
     *
     * v1.1.5: Wird auch für Spiele OHNE Ergebnis versucht (Spielort ist oft
     * vor Spielbeginn bekannt). 24h-Cache (is_venue_lookup_cached_as_failed())
     * verhindert wiederholte erfolglose API-Calls. Zusätzlich wird der
     * ermittelte Venue-Term am Heimteam als Home-Court ergänzt
     * (sync_team_home_venue()), damit SportsPress' "Automatisch"-Court-Logik
     * auch für künftige Spiele ohne API-Ergebnis greifen kann.
     */
    private function maybe_sync_venue( array $match, int $event_wp_id, int $home_wp_id, ?array $boxscore_data = null ): void {
        $match_id = (int) ( $match['matchId'] ?? 0 );

        // Auch Venue für zukünftige Spiele setzen (aus match-Daten)
        if ( ! $match_id ) return;
```

- [ ] **Step 2: Early-Return durch Cache-Check ersetzen**

Aktuell (Zeile 1336-1341, im aktuellen Datei-Stand nach Step 1 leicht
verschoben, per Textsuche identifizieren):
```php
        // Prio 2: matchInfo-Endpoint (immer als Fallback)
        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
            // Nur für beendete Spiele matchInfo laden (API-Call sparen)
            if ( $result_str === null ) return;

            $match_info = $this->api->get_match_info( $match_id );
```

Neu:
```php
        // Prio 2: matchInfo-Endpoint (immer als Fallback)
        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
            // v1.1.5: Auch ohne Ergebnis versuchen, aber 24h-Cache gegen
            // wiederholte erfolglose Anfragen (z. B. Freundschaftsspiele
            // ohne Spielort in der API).
            if ( $this->is_venue_lookup_cached_as_failed( $match_id ) ) return;

            $match_info = $this->api->get_match_info( $match_id );
```

- [ ] **Step 3: Finalen Bail-Out um Cache-Write ergänzen**

Aktuell:
```php
        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) return;

        $spielfeld_id = (int) $spielfeld['id'];
```

Neu:
```php
        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
            $this->cache_venue_lookup_failure( $match_id );
            return;
        }

        $spielfeld_id = (int) $spielfeld['id'];
```

- [ ] **Step 4: Team-Home-Venue-Zuweisung vor der Event-Zuweisung einfügen**

Aktuell (letzte Zeile der Methode):
```php
        // Venue zum Event zuweisen
        wp_set_object_terms( $event_wp_id, $venue_term_id, 'sp_venue', false );
    }
```

Neu:
```php
        // Venue am Heimteam als Home-Court ergänzen (append-only)
        $this->sync_team_home_venue( $home_wp_id, $venue_term_id );

        // Venue zum Event zuweisen
        wp_set_object_terms( $event_wp_id, $venue_term_id, 'sp_venue', false );
    }
```

- [ ] **Step 5: Aufrufstelle in `sync_event()` anpassen**

Zeile 1075 aktuell:
```php
        // v3.5.0: Venue-Sync (immer, auch ohne Player-Sync)
        $this->maybe_sync_venue( $match, $wp_id, $boxscore_data );
```

Neu (`$home_wp_id` ist in `sync_event()` bereits als Variable vorhanden):
```php
        // v3.5.0: Venue-Sync (immer, auch ohne Player-Sync)
        // v1.1.5: $home_wp_id für Team-Home-Court-Zuweisung
        $this->maybe_sync_venue( $match, $wp_id, $home_wp_id, $boxscore_data );
```

- [ ] **Step 6: Syntax-Check**

Run: `php -l includes/class-bbb-sync-engine.php`
Expected: `No syntax errors detected in includes/class-bbb-sync-engine.php`

- [ ] **Step 7: Unit-Testsuite erneut laufen lassen (Regressionscheck)**

Run: `vendor/bin/phpunit`
Expected: `OK (9 tests, ...)` — die Wiring-Änderungen dürfen die Task-2/3-Tests nicht brechen (sie testen weiterhin die isolierten Helper-Methoden per Reflection, unabhängig vom Rest der Methode).

- [ ] **Step 8: Commit**

```bash
git add includes/class-bbb-sync-engine.php
git commit -m "fix: Early-Return-Bug beheben + Team-Home-Venue in maybe_sync_venue() verdrahten"
```

---

### Task 5: Manuelle Verifikation gegen echten Sync-Lauf

**Files:** keine Code-Änderungen — Verifikationsschritt.

- [ ] **Step 1: Sync-Lauf für ein Spiel ohne Ergebnis anstoßen**

Über das Plugin-Admin-Dashboard (Menü "SportsPress → BBB Sync" o. ä.) einen
Sync für das Team/die Liga von ASV Cham U12 (oder ein vergleichbares Team mit
aktuell leerem Court bei einem zukünftigen Spiel) manuell auslösen.

- [ ] **Step 2: Event-Court prüfen**

Im WP-Admin unter `sp_event` das betreffende Spiel öffnen. Erwartet: Feld
"Court" (Taxonomie `sp_venue`) ist jetzt gesetzt.

- [ ] **Step 3: Team-Home-Court prüfen**

Im WP-Admin unter `sp_team` das Heimteam öffnen. Erwartet: Feld "Home" zeigt
denselben Venue-Term wie das Event.

- [ ] **Step 4: Regressionstest — bestehenden Team-Home-Court nicht verlieren**

Ein Team auswählen, das bereits manuell einen Home-Court gepflegt hat (oder
testweise selbst einen zweiten, beliebigen Venue-Term am Team-Editor
hinzufügen). Sync für dieses Team erneut auslösen.

Erwartet: Der zuvor gesetzte Home-Court bleibt erhalten, der neu ermittelte
Venue wird nur ergänzt (Team hat danach beide Venues unter "Home").

- [ ] **Step 5: Ergebnis im Plan-Dokument als Kommentar festhalten**

Kurze Notiz (z. B. in der PR-Beschreibung oder als Commit-Message-Body bei
Task 8), welches Team/Spiel getestet wurde und dass beide Checks erfolgreich
waren.

---

### Task 6: arc42-Dokumentation aktualisieren

**Files:**
- Modify: `docs/arc42-bbb-sportspress-sync.adoc:309-310` (Methoden-Tabelle)
- Modify: `docs/arc42-bbb-sportspress-sync.adoc:322-347` (Datenschutz-Regeln)
- Modify: `docs/arc42-bbb-sportspress-sync.adoc:554` (Querschnittliche Konzepte)
- Modify: `docs/arc42-bbb-sportspress-sync.adoc:850-857` (Versionsverlauf)

- [ ] **Step 1: Methoden-Tabelle ergänzen**

Aktuell (Zeile 309-311):
```asciidoc
| `maybe_sync_venue()`
| Venue anlegen/aktualisieren mit matchInfo + Geocoding

| `reconcile_events()`
```

Neu:
```asciidoc
| `maybe_sync_venue()`
| Venue anlegen/aktualisieren mit matchInfo + Geocoding (auch ohne Ergebnis, 24h-Cache gegen erfolglose Lookups)

| `sync_team_home_venue()`
| Venue als Home-Court am Heimteam ergänzen (append-only, `sp_venue`-Taxonomie am `sp_team`)

| `reconcile_events()`
```

- [ ] **Step 2: Datenschutz-Regeln-Tabelle ergänzen**

Aktuell (Zeile 340-347):
```asciidoc
| BBB-Meta (`_bbb_*`)
| Immer gesetzt
| Immer aktualisiert (Primary Keys + Zuordnung)

| `post_author`
| Sync-User
| Nur wenn aktueller Autor = Sync-User
|===
```

Neu:
```asciidoc
| BBB-Meta (`_bbb_*`)
| Immer gesetzt
| Immer aktualisiert (Primary Keys + Zuordnung)

| `post_author`
| Sync-User
| Nur wenn aktueller Autor = Sync-User

| Team-Home-Venue (`sp_venue` am `sp_team`)
| —
| NIE ersetzt (`wp_set_object_terms(..., $append = true)`), nur ergänzt – bestehende manuelle Home-Courts eines Teams bleiben erhalten
|===
```

- [ ] **Step 3: Querschnittliche Konzepte um Test-Setup-Hinweis ergänzen**

Zuerst den Abschnitt ab Zeile 554 lesen, um die passende Einfügeposition
(z. B. als neuer Unterabschnitt am Ende) zu bestimmen:

Run: `sed -n '554,620p' docs/arc42-bbb-sportspress-sync.adoc`

Am Ende des Abschnitts "Querschnittliche Konzepte" (vor dem nächsten `==`
Top-Level-Header) folgenden neuen Unterabschnitt einfügen:

```asciidoc
=== Testing

Seit v1.1.5 existiert ein Unit-Test-Setup mit
https://github.com/Brain-WP/BrainMonkey[Brain Monkey] (mockt globale
WP-Funktionen ohne echte WordPress-Installation/DB). Abgedeckt wird nur
neue/geänderte Logik (aktuell: Team-Home-Venue-Zuweisung, Venue-Lookup-Cache),
kein vollständiger Test der Sync-Engine.

[source,bash]
----
composer install
vendor/bin/phpunit
----

Tests liegen unter `tests/unit/`, Bootstrap in `tests/bootstrap.php`.
```

- [ ] **Step 4: Versionsverlauf-Tabelle ergänzen**

Aktuell (Zeile 850-857):
```asciidoc
== Versionsverlauf

[cols="1,1,4",options="header"]
|===
| Version | Datum | Änderungen

| v1.1.4
| 2026-02-24
| Fix Liga/Turnier-Erkennung: `tableExists` als `_bbb_table_exists` term_meta auf `sp_league`
```

Neu (neue Zeile **vor** v1.1.4 einfügen):
```asciidoc
== Versionsverlauf

[cols="1,1,4",options="header"]
|===
| Version | Datum | Änderungen

| v1.1.5
| 2026-07-18
| Team-Home-Venue-Sync: Heimteam bekommt ermittelten Spielort als Home-Court
ergänzt (append-only), damit SportsPress' "Automatisch"-Court-Logik auch für
künftige Spiele ohne API-Ergebnis greift. Early-Return-Bug behoben (Venue
wurde bisher nur für Spiele mit Ergebnis ermittelt), 24h-Cache gegen
wiederholte erfolglose matchInfo-Lookups. Neues Brain-Monkey-Unit-Test-Setup.

| v1.1.4
| 2026-02-24
| Fix Liga/Turnier-Erkennung: `tableExists` als `_bbb_table_exists` term_meta auf `sp_league`
```

- [ ] **Step 5: Commit**

```bash
git add docs/arc42-bbb-sportspress-sync.adoc
git commit -m "docs: arc42 um Team-Home-Venue-Sync + Test-Setup ergänzen"
```

---

### Task 7: Versionsbump + README-Changelog

**Files:**
- Modify: `bbb-sportspress-sync.php:6` (Plugin-Header-Kommentar)
- Modify: `bbb-sportspress-sync.php:20` (`BBB_SYNC_VERSION`-Konstante)
- Modify: `README.adoc:261-264` (Changelog)

- [ ] **Step 1: Plugin-Header-Version bumpen**

Zeile 6 aktuell:
```php
 * Version:           1.1.4
```
Neu:
```php
 * Version:           1.1.5
```

- [ ] **Step 2: Versions-Konstante bumpen**

Zeile 20 aktuell:
```php
define( 'BBB_SYNC_VERSION', '1.1.4' );
```
Neu:
```php
define( 'BBB_SYNC_VERSION', '1.1.5' );
```

- [ ] **Step 3: README-Changelog-Eintrag ergänzen**

Aktuell (Zeile 261-264):
```asciidoc
== Changelog

=== v1.1.4 (2026-02-24)
```

Neu:
```asciidoc
== Changelog

=== v1.1.5 (2026-07-18)

* **Feature: Team-Home-Venue-Sync** – Der beim Sync ermittelte Spielort wird jetzt zusätzlich als Home-Court am Heimteam ergänzt (append-only, bestehende manuelle Home-Courts bleiben erhalten). Ermöglicht SportsPress' "Automatisch"-Court-Logik auch für künftige Spiele ohne API-Ergebnis.
* **Fix: Early-Return-Bug** – Venue-Ermittlung lief bisher nur für Spiele mit Ergebnis. Jetzt auch für zukünftige Spiele, mit 24h-Cache gegen wiederholte erfolglose API-Anfragen.
* **Neu:** Brain-Monkey-Unit-Test-Setup (`composer install && vendor/bin/phpunit`).

=== v1.1.4 (2026-02-24)
```

- [ ] **Step 4: Commit**

```bash
git add bbb-sportspress-sync.php README.adoc
git commit -m "v1.1.5 – Team-Home-Venue-Sync, Early-Return-Fix, Brain-Monkey-Tests"
```

---

### Task 8: Abschluss

- [ ] **Step 1: Vollständige Testsuite ein letztes Mal laufen lassen**

Run: `vendor/bin/phpunit`
Expected: `OK (9 tests, ...)`

- [ ] **Step 2: PHP-Syntax-Check der geänderten Datei**

Run: `php -l includes/class-bbb-sync-engine.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: `git log` der Feature-Commits als Übersicht prüfen**

Run: `git log --oneline -10`
Expected: 8 neue Commits sichtbar (Task 1–7 + dieser Punkt hat keinen eigenen Commit), jeweils mit den Messages aus den vorherigen Tasks.

- [ ] **Step 4: Manuelle Verifikation (Task 5) bestätigen**

Sicherstellen, dass Task 5 (Sync-Lauf gegen echtes Team) durchgeführt und
erfolgreich war, bevor der Branch als fertig gilt.
