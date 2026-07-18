<?php
/**
 * PHPUnit bootstrap – defines WP stubs so plugin classes can be loaded
 * without a real WordPress installation.
 *
 * Lädt Brain Monkey (mockt globale WP-Funktionen) statt einer echten
 * WordPress-Installation. Deckt nur gezielt ausgewählte, isoliert testbare
 * Logik ab, kein vollständiger Test der gesamten Plugin-Klassen.
 */

// WordPress constants used by the plugin files
define( 'ABSPATH', __DIR__ . '/' );
define( 'BBB_SYNC_VERSION', '1.1.7' );
define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
define( 'WP_DEBUG', false );
define( 'DAY_IN_SECONDS', 86400 );
define( 'FS_CHMOD_FILE', 0644 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Patchwork (used by Brain Monkey to redefine functions per-test) only
// registers its rewriting stream wrapper on first require, not eagerly via
// composer's autoload. Force that activation here, before any WP stub
// function is defined, so later Functions\when('is_wp_error') etc. calls
// in individual tests can actually redefine it. See tests/wp-stubs.php.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

require_once __DIR__ . '/wp-stubs.php';

// ── Load plugin classes ───────────────────────────────────────────────────────

$plugin_includes = dirname( __DIR__ ) . '/includes/';

require_once $plugin_includes . 'class-bbb-api-client.php';
require_once $plugin_includes . 'class-bbb-logo-handler.php';
require_once $plugin_includes . 'class-bbb-player-sync.php';
require_once $plugin_includes . 'class-bbb-sync-engine.php';
require_once $plugin_includes . 'class-bbb-admin-page.php';
