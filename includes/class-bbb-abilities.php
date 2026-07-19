<?php
/**
 * WordPress Abilities API Integration.
 *
 * Registriert die BBB-Sync-Aktionen als "Abilities" (WordPress Abilities API,
 * ab WP 6.9), damit KI-Agenten/MCP-Clients den Sync über
 * /wp-json/wp-abilities/v1/ auslösen und abfragen können – ohne eigene
 * REST-Endpunkte.
 *
 * Auf WP < 6.9 (keine Abilities API) feuern die Hooks
 * wp_abilities_api_categories_init / wp_abilities_api_init nie – die Klasse
 * ist dann komplett inert. Kein function_exists()-Guard nötig.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_Abilities' ) ) {
    return;
}

class BBB_Abilities {

    private const CATEGORY = 'bbb-sportspress-sync';

    public function __construct() {
        add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
        add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
    }

    public function register_category(): void {
        wp_register_ability_category(
            self::CATEGORY,
            [
                'label'       => 'BBB SportsPress Sync',
                'description' => 'Synchronisation von Basketball-Bund.net-Daten (Teams, Spiele, Ergebnisse, Spieler) in SportsPress.',
            ]
        );
    }

    public function register_abilities(): void {
        wp_register_ability(
            self::CATEGORY . '/trigger-sync',
            [
                'label'               => 'BBB Sync auslösen',
                'description'         => 'Plant einen einmaligen Sync-Lauf über WP-Cron ein, sofern nicht bereits einer läuft.',
                'category'            => self::CATEGORY,
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'scheduled'       => [ 'type' => 'boolean' ],
                        'already_running' => [ 'type' => 'boolean' ],
                        'message'         => [ 'type' => 'string' ],
                    ],
                    'required'   => [ 'scheduled', 'already_running', 'message' ],
                ],
                'execute_callback'    => [ $this, 'execute_trigger_sync' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'meta'                => [
                    'show_in_rest' => true,
                    'annotations'  => [ 'readonly' => false ],
                ],
            ]
        );

        wp_register_ability(
            self::CATEGORY . '/get-sync-status',
            [
                'label'               => 'BBB Sync-Status abfragen',
                'description'         => 'Liefert den aktuellen Sync-Fortschritt sowie Informationen zum letzten abgeschlossenen Lauf.',
                'category'            => self::CATEGORY,
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'running'          => [ 'type' => 'boolean' ],
                        'phase'            => [ 'type' => 'string' ],
                        'current_label'    => [ 'type' => 'string' ],
                        'started_at'       => [ 'type' => [ 'integer', 'null' ] ],
                        'registered_teams' => [ 'type' => 'integer' ],
                        'last_run'         => [ 'type' => [ 'object', 'null' ] ],
                    ],
                    'required'   => [ 'running', 'phase', 'current_label', 'started_at', 'registered_teams', 'last_run' ],
                ],
                'execute_callback'    => [ $this, 'execute_get_sync_status' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'meta'                => [
                    'show_in_rest' => true,
                    'annotations'  => [ 'readonly' => true ],
                ],
            ]
        );

        wp_register_ability(
            self::CATEGORY . '/get-sync-history',
            [
                'label'               => 'BBB Sync-Historie abfragen',
                'description'         => 'Liefert die letzten N Sync-Läufe, neueste zuerst.',
                'category'            => self::CATEGORY,
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => [
                            'type'    => 'integer',
                            'default' => 10,
                            'minimum' => 1,
                            'maximum' => 50,
                        ],
                    ],
                ],
                'output_schema'       => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'time'     => [ 'type' => 'string' ],
                            'duration' => [ 'type' => 'number' ],
                            'trigger'  => [ 'type' => 'string' ],
                            'stats'    => [ 'type' => 'object' ],
                        ],
                    ],
                ],
                'execute_callback'    => [ $this, 'execute_get_sync_history' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'meta'                => [
                    'show_in_rest' => true,
                    'annotations'  => [ 'readonly' => true ],
                ],
            ]
        );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * execute_callback für bbb-sportspress-sync/trigger-sync.
     */
    public function execute_trigger_sync(): array {
        $progress = BBB_Sync_Engine::get_progress();

        if ( ! empty( $progress['running'] ) ) {
            $started = $progress['started_at'] ?? 0;
            if ( $started && ( time() - $started ) > 300 ) {
                // Stale lock → Reset (gleiche Schwelle wie ajax_start_sync()).
                delete_transient( 'bbb_sync_progress' );
            } else {
                return [
                    'scheduled'       => false,
                    'already_running' => true,
                    'message'         => 'Sync läuft bereits.',
                ];
            }
        }

        $own_teams = get_option( 'bbb_sync_own_teams', [] );
        if ( empty( $own_teams ) ) {
            return [
                'scheduled'       => false,
                'already_running' => false,
                'message'         => 'Keine Teams registriert – nichts zu synchronisieren.',
            ];
        }

        // Lock sofort setzen, damit kein zweiter Aufruf vor dem Cron-Tick startet.
        set_transient(
            'bbb_sync_progress',
            [
                'running'       => true,
                'phase'         => 'scheduled',
                'current_label' => 'Sync über WP-Cron eingeplant...',
                'started_at'    => time(),
            ],
            600
        );

        wp_schedule_single_event( time(), 'bbb_sync_cron_event' );

        return [
            'scheduled'       => true,
            'already_running' => false,
            'message'         => 'Sync wurde eingeplant und läuft beim nächsten WP-Cron-Tick.',
        ];
    }

    /**
     * execute_callback für bbb-sportspress-sync/get-sync-status.
     */
    public function execute_get_sync_status(): array {
        $progress  = BBB_Sync_Engine::get_progress();
        $history   = get_option( 'bbb_sync_history', [] );
        $own_teams = get_option( 'bbb_sync_own_teams', [] );

        return [
            'running'          => ! empty( $progress['running'] ),
            'phase'            => $progress['phase'] ?? 'idle',
            'current_label'    => $progress['current_label'] ?? '',
            'started_at'       => $progress['started_at'] ?? null,
            'registered_teams' => count( $own_teams ),
            'last_run'         => ! empty( $history ) ? end( $history ) : null,
        ];
    }

    /**
     * execute_callback für bbb-sportspress-sync/get-sync-history.
     *
     * @param array $input Validiertes Input-Schema, z. B. [ 'limit' => 10 ].
     */
    public function execute_get_sync_history( array $input ): array {
        $limit   = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;
        $history = get_option( 'bbb_sync_history', [] );

        return array_slice( array_reverse( $history ), 0, $limit );
    }
}
