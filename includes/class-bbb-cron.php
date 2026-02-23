<?php
/**
 * BBB Cron Handler v3.0
 *
 * WP-Cron basierte Synchronisation.
 * Ruft sync_all() auf – iteriert über alle registrierten eigenen Teams.
 *
 * Prod-Limits beachten:
 *   max_execution_time: 30s
 *   memory_limit: 256M
 * → Sync muss pro Team schnell sein (1 API-Call + Processing)
 */

defined( 'ABSPATH' ) || exit;

class BBB_Cron {

    public function __construct() {
        add_action( 'bbb_sync_cron_event', [ $this, 'run_sync' ] );
    }

    /**
     * Cron callback: Führt den Sync aus.
     */
    public function run_sync(): void {
        // Safety: Don't run if no teams registered
        $own_teams = get_option( 'bbb_sync_own_teams', [] );
        if ( empty( $own_teams ) ) {
            return;
        }

        $engine = new BBB_Sync_Engine();

        $start    = microtime( true );
        $stats    = $engine->sync_all();
        $duration = round( microtime( true ) - $start, 2 );

        // Log run to history
        $history   = get_option( 'bbb_sync_history', [] );
        $history[] = [
            'time'     => current_time( 'mysql' ),
            'duration' => $duration,
            'stats'    => $stats,
            'trigger'  => 'cron',
        ];
        $history = array_slice( $history, -50 );
        update_option( 'bbb_sync_history', $history, false );
    }
}
