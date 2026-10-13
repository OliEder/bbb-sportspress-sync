# SportsPress-Native Meta REST Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Korrektur nach Umsetzung:** `sp_main_result` wird als `string` registriert (nicht `integer`) – das Feld enthält den Slug der Haupt-Ergebnis-Spalte (z.B. `pts`, `t`), nicht die Punktzahl. Dafür gibt es einen zusätzlichen Test (`test_main_result_is_string_slug_not_integer`), insgesamt 9 statt 7 neue Tests. Maßgeblich ist der implementierte Code in `includes/class-bbb-sportspress-meta-registration.php`; die Code-Blöcke unten zeigen noch den ursprünglichen Entwurf.

**Goal:** Make 11 native SportsPress meta fields (team assignments, results, player performance stats, team metadata) visible in the REST API, the same way `BBB_Meta_Registration` already did for this plugin's own `_bbb_*` fields.

**Architecture:** A new class `BBB_SportsPress_Meta_Registration`, structurally identical to the existing `BBB_Meta_Registration` (ABSPATH guard, class_exists guard, hooks `register_meta()` onto `init`), registers 11 `(post_type, meta_key)` pairs via `register_post_meta()`. No term_meta in this batch (venue geo/address fields are stored via `wp_options`, not term meta, and are explicitly out of scope — see spec). `sp_players`/`sp_results` are registered as `type => 'object'` without a fixed schema, since their sub-structure depends on runtime SportsPress/league configuration.

**Tech Stack:** PHP 8.1+, WordPress `register_post_meta()`, PHPUnit + Brain Monkey (mocks `register_post_meta`/`current_user_can`), PHPCS (WordPress Coding Standards, already configured in this repo).

**Full spec:** `docs/superpowers/specs/2026-07-19-sportspress-native-meta-rest-design.md`

---

### Task 1: Create `BBB_SportsPress_Meta_Registration` + wire it in

**Files:**
- Create: `includes/class-bbb-sportspress-meta-registration.php`
- Modify: `tests/bootstrap.php`
- Modify: `bbb-sportspress-sync.php`
- Test: `tests/unit/SportsPressMetaRegistrationTest.php`

- [ ] **Step 1: Create the class file**

Create `includes/class-bbb-sportspress-meta-registration.php` with this exact content:

```php
<?php
/**
 * BBB SportsPress-native Meta REST Registration.
 *
 * Registriert die von SportsPress selbst definierten, aber von diesem Plugin
 * geschriebenen Meta-Felder (`sp_*`, ohne Unterstrich-Prefix) für die REST API.
 * Seit WP 4.7 muss jedes Meta-Feld – unabhängig vom Unterstrich-Prefix –
 * explizit per register_post_meta()/register_term_meta() registriert werden,
 * um im REST-`meta`-Objekt zu erscheinen. Ohne diese Registrierung bleiben
 * z.B. Spielergebnisse (`sp_results`) oder Team-Zuordnungen (`sp_team`) für
 * externe REST-Konsumenten unsichtbar, obwohl SportsPress selbst diese Felder
 * im Backend/Frontend anzeigt.
 *
 * Ergänzt `BBB_Meta_Registration` (registriert die plugineigenen `_bbb_*`-IDs);
 * diese Klasse kümmert sich ausschließlich um die nativen SportsPress-Felder,
 * die dieses Plugin beim Sync befüllt (Team-Zuordnung, Ergebnisse,
 * Spieler-Performance, Team-Stammdaten).
 *
 * Bewusst NICHT enthalten: Venue-Adress-/Geo-Felder (`sp_address`,
 * `sp_latitude`, `sp_longitude`) – SportsPress speichert diese überwiegend
 * über eine WP-Option `taxonomy_{term_id}`, nicht als echtes Term-Meta;
 * register_term_meta() kann Options-Daten nicht erfassen. Ebenfalls nicht
 * enthalten: `sp_format`/`sp_precision` auf `sp_result`-Posts (Konfiguration
 * der Ergebnis-Spalten selbst, keine für externe Konsumenten relevanten
 * Spieldaten).
 *
 * Bekannte Einschränkung: `sp_players` und `sp_results` werden als `object`-
 * Typ ohne festes Schema registriert (Sub-Struktur ist dynamisch, abhängig
 * von der jeweiligen Liga-/Performance-Konfiguration). WordPress' REST-Meta-
 * Mechanismus liest solche Felder zuverlässig, verlangt aber beim Schreiben
 * über REST i.d.R. ein Schema mit `properties` zur Validierung – ein
 * Schreibversuch über REST kann für genau diese zwei Felder daher von WP
 * abgelehnt werden. Das ist eine bekannte WP-Einschränkung bei dynamischen
 * Strukturen, kein Bug dieser Klasse.
 *
 * @since 1.1.11
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_SportsPress_Meta_Registration' ) ) {
    return;
}

class BBB_SportsPress_Meta_Registration {

    public function __construct() {
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    public function register_meta(): void {
        $auth_callback = static function (): bool {
            return current_user_can( 'edit_posts' );
        };

        $event_fields = [
            'sp_team'        => [
                'type'        => 'integer',
                'single'      => false,
                'description' => 'Home-/Away-Team-Zuordnung (SP Team Post-IDs)',
            ],
            'sp_player'      => [
                'type'        => 'integer',
                'single'      => false,
                'description' => 'Im Spiel eingesetzte Spieler (SP Player Post-IDs)',
            ],
            'sp_players'     => [
                'type'        => 'object',
                'single'      => true,
                'description' => 'Spieler-Performance-Stats je Team/Spieler (dynamische Stat-Spalten)',
            ],
            'sp_results'     => [
                'type'        => 'object',
                'single'      => true,
                'description' => 'Ergebnis je Team/Ergebnis-Spalte',
            ],
            'sp_status'      => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Spielstatus (z.B. scheduled, results)',
            ],
            'sp_main_result' => [
                'type'        => 'integer',
                'single'      => true,
                'description' => 'Referenz auf die Haupt-Ergebnis-Spalte',
            ],
            'sp_day'         => [
                'type'        => 'integer',
                'single'      => true,
                'description' => 'Spieltag',
            ],
            'sp_format'      => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Format der Ergebnis-Erfassung (z.B. league)',
            ],
        ];

        $team_fields = [
            'sp_abbreviation' => [
                'type'        => 'string',
                'single'      => true,
                'description' => '3-stelliges Team-Kürzel',
            ],
            'sp_short_name'   => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Team-Kurzname',
            ],
            'sp_url'          => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Team-Homepage-URL',
            ],
        ];

        foreach ( $event_fields as $meta_key => $args ) {
            register_post_meta(
                'sp_event',
                $meta_key,
                array_merge(
                    $args,
                    [
                        'show_in_rest'  => true,
                        'auth_callback' => $auth_callback,
                    ]
                )
            );
        }

        foreach ( $team_fields as $meta_key => $args ) {
            register_post_meta(
                'sp_team',
                $meta_key,
                array_merge(
                    $args,
                    [
                        'show_in_rest'  => true,
                        'auth_callback' => $auth_callback,
                    ]
                )
            );
        }
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-bbb-sportspress-meta-registration.php`
Expected: `No syntax errors detected in includes/class-bbb-sportspress-meta-registration.php`

- [ ] **Step 3: Wire into `tests/bootstrap.php`**

In `tests/bootstrap.php`, find this line:

```php
require_once $plugin_includes . 'class-bbb-meta-registration.php';
```

Add immediately after it:

```php
require_once $plugin_includes . 'class-bbb-sportspress-meta-registration.php';
```

- [ ] **Step 4: Wire into `bbb-sportspress-sync.php`**

Find this line in the `plugins_loaded` callback's "Core Classes" block:

```php
		require_once BBB_PLUGIN_DIR . 'includes/class-bbb-meta-registration.php';
```

Add immediately after it:

```php
		require_once BBB_PLUGIN_DIR . 'includes/class-bbb-sportspress-meta-registration.php';
```

Find this line further down (instantiation block):

```php
		new BBB_Meta_Registration();
```

Add immediately after it:

```php
		new BBB_SportsPress_Meta_Registration();
```

- [ ] **Step 5: Bump version to 1.1.11**

In `bbb-sportspress-sync.php`, change the docblock header:
```php
 * Version:           1.1.10
```
to:
```php
 * Version:           1.1.11
```

And change:
```php
define( 'BBB_SYNC_VERSION', '1.1.10' );
```
to:
```php
define( 'BBB_SYNC_VERSION', '1.1.11' );
```

In `tests/bootstrap.php`, change:
```php
define( 'BBB_SYNC_VERSION', '1.1.10' );
```
to:
```php
define( 'BBB_SYNC_VERSION', '1.1.11' );
```

- [ ] **Step 6: Write the test file**

Create `tests/unit/SportsPressMetaRegistrationTest.php` with this exact content:

```php
<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests für BBB_SportsPress_Meta_Registration (REST-Sichtbarkeit nativer
 * SportsPress-Felder, v1.1.11). Gleiches Testmuster wie
 * MetaRegistrationTest.php: register_post_meta() wird gemockt, alle Aufrufe
 * werden pro (Post Type, Meta-Key)-Paar eingesammelt und geprüft.
 */
final class SportsPressMetaRegistrationTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_registration(): \BBB_SportsPress_Meta_Registration {
        $reflection = new ReflectionClass( \BBB_SportsPress_Meta_Registration::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Ruft register_meta() auf und sammelt alle register_post_meta()-Aufrufe,
     * geschlüsselt als "<post_type>:<meta_key>".
     */
    private function register_and_capture(): array {
        $captured = [];

        Functions\expect( 'register_post_meta' )
            ->times( 11 )
            ->andReturnUsing(
                function ( string $post_type, string $meta_key, array $args ) use ( &$captured ) {
                    $captured[ "{$post_type}:{$meta_key}" ] = $args;
                    return true;
                }
            );

        $this->make_registration()->register_meta();

        return $captured;
    }

    public function test_registers_all_eleven_expected_pairs(): void {
        $captured = $this->register_and_capture();

        $expected_keys = [
            'sp_event:sp_team',
            'sp_event:sp_player',
            'sp_event:sp_players',
            'sp_event:sp_results',
            'sp_event:sp_status',
            'sp_event:sp_main_result',
            'sp_event:sp_day',
            'sp_event:sp_format',
            'sp_team:sp_abbreviation',
            'sp_team:sp_short_name',
            'sp_team:sp_url',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $captured );
        }
        $this->assertCount( 11, $captured );
    }

    public function test_multi_value_fields_have_single_false(): void {
        $captured = $this->register_and_capture();

        $this->assertFalse( $captured['sp_event:sp_team']['single'] );
        $this->assertFalse( $captured['sp_event:sp_player']['single'] );
    }

    public function test_single_value_fields_have_single_true(): void {
        $captured = $this->register_and_capture();

        $single_true_keys = [
            'sp_event:sp_players',
            'sp_event:sp_results',
            'sp_event:sp_status',
            'sp_event:sp_main_result',
            'sp_event:sp_day',
            'sp_event:sp_format',
            'sp_team:sp_abbreviation',
            'sp_team:sp_short_name',
            'sp_team:sp_url',
        ];

        foreach ( $single_true_keys as $key ) {
            $this->assertTrue( $captured[ $key ]['single'], "single mismatch for {$key}" );
        }
    }

    public function test_object_type_fields(): void {
        $captured = $this->register_and_capture();

        $this->assertSame( 'object', $captured['sp_event:sp_players']['type'] );
        $this->assertSame( 'object', $captured['sp_event:sp_results']['type'] );
    }

    public function test_integer_type_fields(): void {
        $captured = $this->register_and_capture();

        foreach ( [ 'sp_event:sp_team', 'sp_event:sp_player', 'sp_event:sp_main_result', 'sp_event:sp_day' ] as $key ) {
            $this->assertSame( 'integer', $captured[ $key ]['type'], "type mismatch for {$key}" );
        }
    }

    public function test_string_type_fields(): void {
        $captured = $this->register_and_capture();

        foreach ( [ 'sp_event:sp_status', 'sp_event:sp_format', 'sp_team:sp_abbreviation', 'sp_team:sp_short_name', 'sp_team:sp_url' ] as $key ) {
            $this->assertSame( 'string', $captured[ $key ]['type'], "type mismatch for {$key}" );
        }
    }

    public function test_all_pairs_have_show_in_rest_and_callable_auth_callback(): void {
        $captured = $this->register_and_capture();

        foreach ( $captured as $key => $args ) {
            $this->assertTrue( $args['show_in_rest'], "show_in_rest mismatch for {$key}" );
            $this->assertArrayHasKey( 'auth_callback', $args );
            $this->assertIsCallable( $args['auth_callback'] );
        }
    }

    public function test_auth_callback_checks_edit_posts(): void {
        $captured = $this->register_and_capture();

        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'edit_posts' )
            ->andReturn( true );

        $this->assertTrue( ( $captured['sp_event:sp_team']['auth_callback'] )() );
    }
}
```

- [ ] **Step 7: Run the new test file**

Run: `docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli vendor/bin/phpunit tests/unit/SportsPressMetaRegistrationTest.php`
Expected: `OK (7 tests, ...)` — 7 tests, all passing.

- [ ] **Step 8: Run full test suite + PHPCS**

Run: `docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli vendor/bin/phpunit`
Expected: all tests green, test count increased by 7 over the previous total (previous total was 62 tests / 235 assertions before this task — note the new totals for use in Task 2).

Run: `vendor/bin/phpcs`
Expected: no violations.

- [ ] **Step 9: Commit**

```bash
git add includes/class-bbb-sportspress-meta-registration.php tests/unit/SportsPressMetaRegistrationTest.php tests/bootstrap.php bbb-sportspress-sync.php
git commit -m "v1.1.11 – REST-Sichtbarkeit für native SportsPress-Meta-Felder"
```

---

### Task 2: Update README.adoc

**Files:**
- Modify: `README.adoc:276` (Aktueller Stand version line + PHPUnit output block + Abgedeckt list)
- Modify: `README.adoc:316-318` (Changelog — insert new v1.1.11 section before v1.1.10)

- [ ] **Step 1: Update the changelog**

In `README.adoc`, find:

```
== Changelog

=== v1.1.10 (2026-07-19)
```

Replace with (inserting the new v1.1.11 entry before v1.1.10, keeping v1.1.10 untouched below it):

```
== Changelog

=== v1.1.11 (2026-07-19)

* **Neu: REST-Sichtbarkeit für native SportsPress-Meta-Felder** –
  `BBB_SportsPress_Meta_Registration` registriert 11 von SportsPress selbst
  definierte, aber von diesem Plugin geschriebene Meta-Felder per
  `register_post_meta()` mit `show_in_rest => true`. Ergänzt
  `BBB_Meta_Registration` (v1.1.10, dort: plugineigene `_bbb_*`-IDs) um die
  nativen SportsPress-Datenfelder (Team-Zuordnung, Ergebnisse,
  Spieler-Performance, Team-Stammdaten).
** Betroffen (`sp_event`): `sp_team`, `sp_player` (jeweils Mehrfachwerte),
   `sp_players` (Spieler-Performance-Stats, verschachtelt), `sp_results`
   (Ergebnis je Spalte), `sp_status`, `sp_main_result`, `sp_day`, `sp_format`
** Betroffen (`sp_team`): `sp_abbreviation`, `sp_short_name`, `sp_url`
** `auth_callback` prüft einheitlich `current_user_can('edit_posts')`
   (gleiche Konvention wie bei `BBB_Meta_Registration`)
** Bewusst ausgeschlossen: Venue-Adress-/Geo-Felder (`sp_address`,
   `sp_latitude`, `sp_longitude`) – SportsPress speichert diese über eine
   WP-Option (`taxonomy_{term_id}`), nicht als echtes Term-Meta;
   `register_term_meta()` kann das nicht erfassen. Separates künftiges
   Vorhaben mit anderer Technik (`register_rest_field()` + Options-Getter).
** Bekannte Einschränkung: `sp_players`/`sp_results` sind als `object`-Typ
   ohne festes Schema registriert (Sub-Struktur ist dynamisch je
   Liga-/Performance-Konfiguration) – Lesen über REST funktioniert
   zuverlässig, Schreiben über REST kann WP für diese zwei Felder ohne
   Schema ablehnen.

=== v1.1.10 (2026-07-19)
```

- [ ] **Step 2: Update "Aktueller Stand" version line and PHPUnit output block**

Using the actual output captured in Task 1 Step 8, find:

```
Aktueller Stand (Stand: v1.1.10, 2026-07-19):

[source]
----
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.32
Configuration: /app/phpunit.xml.dist

..............................................................    62 / 62 (100%)

Time: 00:00.262, Memory: 24.00 MB

OK (62 tests, 235 assertions)
----
```

Replace the version line with `Aktueller Stand (Stand: v1.1.11, 2026-07-19):` and replace the dots/counts/timing inside the `[source]` block with the actual fresh output from re-running:

Run: `docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli vendor/bin/phpunit`

Copy the real `Runtime:`, dots line, `Time:`/`Memory:` line, and final `OK (N tests, M assertions)` line from that output verbatim into the block (do not guess the numbers — use what the command prints).

- [ ] **Step 3: Add a bullet to "Abgedeckt"**

Find:

```
* `BBB_Meta_Registration::register_meta()` – REST-Sichtbarkeit der `_bbb_*`-
  Meta-Felder via `register_post_meta()`/`register_term_meta()`, Schema
  (`type`, `single`, `show_in_rest`) sowie `auth_callback`-Capability-Checks
  für post_meta (`edit_posts`) und term_meta (`manage_options`) (4 Tests)
```

Add immediately after it:

```
* `BBB_SportsPress_Meta_Registration::register_meta()` – REST-Sichtbarkeit
  von 11 nativen SportsPress-Meta-Feldern (`sp_event`, `sp_team`) via
  `register_post_meta()`, inkl. Mehrfachwert-Felder (`single => false`) und
  `object`-Typ-Felder ohne festes Schema (7 Tests)
```

- [ ] **Step 4: Commit**

```bash
git add README.adoc
git commit -m "docs: README-Changelog für v1.1.11 (native SportsPress-Meta REST-Sichtbarkeit)"
```

---

### Task 3: Update arc42 architecture doc

**Files:**
- Modify: `docs/arc42-bbb-sportspress-sync.adoc:615` (append new subsection after the existing v1.1.10 REST-Sichtbarkeit paragraph)

- [ ] **Step 1: Add the new subsection**

Find this line (end of the `=== Datenmodell-Mapping BBB → SportsPress` section):

```
**REST-Sichtbarkeit (seit v1.1.10):** Alle oben aufgeführten `_bbb_*`-Meta-Felder sind seit v1.1.10 per `register_post_meta()` / `register_term_meta()` (`includes/class-bbb-meta-registration.php`) für die REST API registriert (`show_in_rest => true` + `auth_callback`). Zuvor lieferte die REST API für diese Felder `rest_no_route`, da mit Unterstrich beginnende Meta-Keys von WordPress als „protected meta" behandelt werden und ohne explizite Registrierung unsichtbar bleiben – auch mit `show_in_rest => true` am Meta-Wert selbst. Ob `sp_team`, `sp_event`, `sp_player`, `sp_list`, `sp_table`, `sp_league` und `sp_venue` selbst (als von SportsPress registrierte Post Types/Taxonomies) `show_in_rest => true` gesetzt haben, liegt außerhalb dieses Plugins und ist hier nicht geprüft – bei Bedarf auf der Live-Site via `GET /wp-json/wp/v2/types` bzw. `GET /wp-json/wp/v2/taxonomies` verifizieren.
```

Add immediately after it (new paragraph):

```

**REST-Sichtbarkeit nativer SportsPress-Felder (seit v1.1.11):** Zusätzlich zu den `_bbb_*`-Feldern registriert `BBB_SportsPress_Meta_Registration` (`includes/class-bbb-sportspress-meta-registration.php`) 11 native SportsPress-Meta-Felder für die REST API, die dieses Plugin selbst schreibt: auf `sp_event` – `sp_team`, `sp_player` (Mehrfachwerte), `sp_players` (Spieler-Performance-Stats, verschachtelt), `sp_results`, `sp_status`, `sp_main_result`, `sp_day`, `sp_format`; auf `sp_team` – `sp_abbreviation`, `sp_short_name`, `sp_url`. Grund: seit WP 4.7 muss jedes Meta-Feld explizit registriert werden, um im REST-`meta`-Objekt zu erscheinen – unabhängig vom Unterstrich-Prefix. Bewusst ausgeschlossen: Venue-Adress-/Geo-Felder (`sp_address`, `sp_latitude`, `sp_longitude`), da SportsPress diese überwiegend über eine WP-Option `taxonomy_{term_id}` statt echtem Term-Meta speichert (`register_term_meta()` kann Options-Daten nicht erfassen); ebenfalls ausgeschlossen: `sp_format`/`sp_precision` auf `sp_result`-Posts (Konfiguration der Ergebnis-Spalten, keine Spieldaten). Bekannte Einschränkung: `sp_players`/`sp_results` sind als `object`-Typ ohne festes Schema registriert, da ihre Sub-Struktur von der jeweiligen Liga-/Performance-Konfiguration abhängt – Lesen über REST funktioniert zuverlässig, ein Schreibversuch über REST kann für genau diese zwei Felder von WP ohne Schema abgelehnt werden.
```

- [ ] **Step 2: Commit**

```bash
git add docs/arc42-bbb-sportspress-sync.adoc
git commit -m "docs: arc42-Datenmodell-Mapping um native SportsPress-Meta-REST-Sichtbarkeit (v1.1.11) ergänzt"
```

---

### Task 4: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Syntax-check every changed/created PHP file**

Run: `php -l includes/class-bbb-sportspress-meta-registration.php && php -l bbb-sportspress-sync.php && php -l tests/bootstrap.php && php -l tests/unit/SportsPressMetaRegistrationTest.php`
Expected: `No syntax errors detected` for all four.

- [ ] **Step 2: Full PHPCS run**

Run: `vendor/bin/phpcs`
Expected: 0 violations project-wide.

- [ ] **Step 3: Full PHPUnit run**

Run: `docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli vendor/bin/phpunit`
Expected: all tests green (previous total + 7 new tests), 0 deprecations.

- [ ] **Step 4: Manual note for the user (not automatable here)**

After deploying: `GET /wp-json/wp/v2/sp_event/<id>` should now include `sp_team`, `sp_player`, `sp_players`, `sp_results`, `sp_status`, `sp_main_result`, `sp_day`, `sp_format` inside the `meta` object (for logged-in users with `edit_posts`, given SportsPress's own `show_in_rest` is set for `sp_event`). Same for `GET /wp-json/wp/v2/sp_team/<id>` → `sp_abbreviation`, `sp_short_name`, `sp_url`. If `meta` is empty despite this registration, check whether `sp_event`/`sp_team` themselves have `show_in_rest => true` in SportsPress's own post type registration (`GET /wp-json/wp/v2/types/sp_event`) — outside this plugin's control.

---

## Self-Review

**Spec coverage:** All spec sections are covered — field table → Task 1 Step 1 (11 pairs, correct types/single); auth model → Task 1 Step 1 (`edit_posts` auth_callback); object-schema caveat → documented in class docblock (Task 1 Step 1), README (Task 2 Step 1), and arc42 (Task 3 Step 1); venue exclusion → documented in the same three places; version bump to 1.1.11 → Task 1 Step 5; test file mirroring `MetaRegistrationTest.php` → Task 1 Step 6; verification → Task 4.

**Placeholder scan:** No TBD/TODO; every step has literal file content or exact commands with expected output. README/arc42 steps use exact find/replace text (not "similar to above").

**Type consistency:** Method name `register_meta()` matches across class (Task 1 Step 1), bootstrap require (Task 1 Step 3), main plugin wiring (Task 1 Step 4), and test file (Task 1 Step 6). Class name `BBB_SportsPress_Meta_Registration` is consistent everywhere it's referenced (file, test, docs).
