<?php
/**
 * PHPUnit Bootstrap für BBB SportsPress Sync Unit-Tests.
 *
 * Lädt Brain Monkey (mockt globale WP-Funktionen) statt einer echten
 * WordPress-Installation. Deckt nur gezielt ausgewählte, isoliert testbare
 * Logik ab, kein vollständiger Test der gesamten Plugin-Klassen.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

require_once __DIR__ . '/../includes/class-bbb-sync-engine.php';
require_once __DIR__ . '/../includes/class-bbb-admin-page.php';
