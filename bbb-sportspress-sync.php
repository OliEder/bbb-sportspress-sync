<?php
/**
 * Plugin Name:       BBB SportsPress Sync
 * Plugin URI:        https://github.com/OliEder/bbb-sportspress-sync
 * Description:       Synchronisiert Vereinsdaten aus der Basketball-Bund.net (BBB) REST API in SportsPress – Teams, Spielplan, Ergebnisse, Spieler, Statistiken und Spielorte. Benötigt SportsPress und BBB Live Tables.
 * Version:           1.1.1
 * Author:            Oliver-Marcus Eder
 * Author URI:        https://github.com/OliEder
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bbb-sportspress-sync
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  sportspress
 */

defined( 'ABSPATH' ) || exit;

define( 'BBB_SYNC_VERSION', '1.1.1' );
define( 'BBB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BBB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BBB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
if ( ! defined( 'BBB_API_BASE_URL' ) ) {
    define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
}

/**
 * Activation: Check dependencies + schedule cron.
 */
register_activation_hook( __FILE__, function() {
    if ( ! is_plugin_active( 'sportspress/sportspress.php' ) && ! is_plugin_active( 'sportspress-pro/sportspress-pro.php' ) ) {
        deactivate_plugins( BBB_PLUGIN_BASENAME );
        wp_die( 'BBB SportsPress Sync benötigt SportsPress oder SportsPress Pro.', 'Abhängigkeit fehlt', [ 'back_link' => true ] );
    }

    if ( ! wp_next_scheduled( 'bbb_sync_cron_event' ) ) {
        wp_schedule_event( time(), 'bbb_sync_interval', 'bbb_sync_cron_event' );
    }
});

/**
 * Deactivation: Remove cron.
 */
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'bbb_sync_cron_event' );
});

/**
 * Custom cron interval.
 */
add_filter( 'cron_schedules', function( $schedules ) {
    $hours = max( 1, (int) get_option( 'bbb_sync_interval', 6 ) );
    $schedules['bbb_sync_interval'] = [
        'interval' => $hours * HOUR_IN_SECONDS,
        'display'  => "Alle {$hours} Stunden (BBB Sync)",
    ];
    return $schedules;
});

/**
 * Init: Load classes.
 *
 * Priority 10 (default) – vor bbb-live-tables (Priority 20).
 * Definiert BBB_SYNC_VERSION, damit das Standalone-Plugin die
 * Goodlayers-Registrierung an dieses Plugin abgibt.
 */
add_action( 'plugins_loaded', function() {

    // ── Abhängigkeit: SportsPress ──
    if ( ! class_exists( 'SportsPress' ) && ! defined( 'SP_VERSION' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>BBB SportsPress Sync:</strong> SportsPress ist nicht aktiv!</p></div>';
        });
        return;
    }

    // ── Abhängigkeit: BBB Live Tables ──
    if ( ! defined( 'BBB_TABLES_VERSION' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>'
                . '<strong>BBB SportsPress Sync:</strong> '
                . 'Das Plugin <a href="https://github.com/OliEder/bbb-live-tables">BBB Live Tables</a> '
                . 'wird benötigt für Liga-Tabellen und Turnier-Brackets. '
                . 'Ohne BBB Live Tables fehlen diese Funktionen. '
                . 'Alternativ können die SportsPress-eigenen Tabellen verwendet werden – '
                . 'diese benötigen jedoch vollständige Ergebnisse aller Liga-Teams.'
                . '</p></div>';
        });
        // Sync funktioniert trotzdem – nur ohne Tabellen/Brackets.
    }

    // ── Core Classes ──
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-api-client.php';
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-sync-engine.php';
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-logo-handler.php';
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-player-sync.php';
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-cron.php';

    // ── BBB Live Tables Integration ──
    // Filter-Callbacks die SP-Daten (Logos, Links, Farben) in das Standalone-Plugin einspeisen.
    if ( defined( 'BBB_TABLES_VERSION' ) ) {
        require_once BBB_PLUGIN_DIR . 'includes/class-bbb-tables-integration.php';
        new BBB_Tables_Integration();
    }

    // ── Goodlayers Page Builder Elemente ──
    // SP-angereicherte Versionen (Liga-Dropdown aus sp_league etc.).
    // Überschreibt die Standalone-Registrierung wenn beide Plugins aktiv sind.
    require_once BBB_PLUGIN_DIR . 'includes/class-bbb-goodlayers-bracket.php';
    new BBB_Goodlayers_Bracket();

    // ── Admin UI + GitHub Update Checker ──
    if ( is_admin() ) {
        require_once BBB_PLUGIN_DIR . 'includes/class-bbb-admin-page.php';
        new BBB_Admin_Page();

        require_once BBB_PLUGIN_DIR . 'includes/class-bbb-github-updater.php';
        new BBB_GitHub_Updater(
            BBB_PLUGIN_BASENAME,
            'OliEder',
            'bbb-sportspress-sync',
            BBB_SYNC_VERSION
        );
    }

    new BBB_Cron();
});

/**
 * Settings link on plugin page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    array_unshift( $links,
        '<a href="' . admin_url( 'admin.php?page=bbb-sync' ) . '">Einstellungen</a>'
    );
    return $links;
});
