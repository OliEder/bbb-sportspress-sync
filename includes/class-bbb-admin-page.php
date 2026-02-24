<?php
/**
 * BBB Admin Page v3.2
 *
 * Sync-Architektur:
 *   1. Button-Klick → AJAX "bbb_start_sync"
 *   2. Handler sendet sofort JSON-Response, schließt HTTP-Verbindung
 *   3. PHP läuft weiter und führt Sync durch (ignore_user_abort)
 *   4. Fortschritt wird per Transient gespeichert
 *   5. Browser pollt alle 2s den Fortschritt via "bbb_sync_progress"
 *   6. Bei Seitenreload: Prüft ob Sync noch läuft → Progress-UI reaktivieren
 */

defined( 'ABSPATH' ) || exit;

class BBB_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_notices', [ $this, 'show_notices' ] );

        // AJAX endpoints
        add_action( 'wp_ajax_bbb_start_sync', [ $this, 'ajax_start_sync' ] );
        add_action( 'wp_ajax_bbb_sync_progress', [ $this, 'ajax_sync_progress' ] );

        // Meta-Box "DBB-Daten" auf sp_player + sp_team + sp_event
        add_action( 'add_meta_boxes', [ $this, 'add_dbb_meta_boxes' ] );
        add_action( 'save_post_sp_player', [ $this, 'save_dbb_meta' ] );
        add_action( 'save_post_sp_team', [ $this, 'save_dbb_meta' ] );
        add_action( 'save_post_sp_event', [ $this, 'save_dbb_meta' ] );

        // Venue-Taxonomie: BBB-Felder im Term-Editor
        add_action( 'sp_venue_edit_form_fields', [ $this, 'render_venue_bbb_fields' ], 10, 2 );
        add_action( 'edited_sp_venue', [ $this, 'save_venue_bbb_fields' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'sportspress',
            'BBB Sync',
            'BBB Sync',
            'manage_options',
            'bbb-sync',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'bbb_sync_settings', 'bbb_sync_club_id', [
            'type' => 'integer', 'sanitize_callback' => 'absint',
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_range_days', [
            'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 365,
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_interval', [
            'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 6,
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_players_enabled', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false,
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_result_slugs', [
            'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_result_slugs' ], 'default' => 't',
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_stat_mapping', [
            'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_stat_mapping' ], 'default' => '',
        ]);
        register_setting( 'bbb_sync_settings', 'bbb_sync_players_own_only', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true,
        ]);
    }

    /**
     * Sanitize: Checkbox-Array → Komma-String.
     * Liest aus $_POST['bbb_sync_result_slugs_arr'] (Checkbox-Array)
     * und konvertiert in "slug1,slug2,...".
     */
    public function sanitize_result_slugs( $value ): string {
        // phpcs:ignore WordPress.Security.NonceVerification -- handled by options.php
        $arr = $_POST['bbb_sync_result_slugs_arr'] ?? [];
        if ( ! is_array( $arr ) ) return '';
        $clean = array_map( 'sanitize_key', $arr );
        return implode( ',', array_filter( $clean ) );
    }

    /**
     * Sanitize: Stat-Mapping Dropdowns → JSON.
     * Liest aus $_POST['bbb_sync_stat_map'] (assoziatives Array bbb_key → sp_slug)
     * und speichert als JSON-String.
     */
    public function sanitize_stat_mapping( $value ): string {
        $arr = $_POST['bbb_sync_stat_map'] ?? [];
        if ( ! is_array( $arr ) ) return '';
        $clean = [];
        foreach ( $arr as $bbb_key => $sp_slug ) {
            $bbb_key = sanitize_key( $bbb_key );
            $sp_slug = sanitize_key( $sp_slug );
            if ( $bbb_key && $sp_slug ) {
                $clean[ $bbb_key ] = $sp_slug;
            }
        }
        return $clean ? wp_json_encode( $clean ) : '';
    }

    // ═════════════════════════════════════════
    // AJAX: Sync starten (Connection Close + Background)
    // ═════════════════════════════════════════

    public function ajax_start_sync(): void {
        check_ajax_referer( 'bbb_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Deadlock-Schutz: Wenn running > 5 Minuten, dann Reset
        $progress = BBB_Sync_Engine::get_progress();
        if ( ! empty( $progress['running'] ) ) {
            $started = $progress['started_at'] ?? 0;
            if ( $started && ( time() - $started ) > 300 ) {
                // Stale lock → Reset
                delete_transient( 'bbb_sync_progress' );
            } else {
                wp_send_json_error( 'Sync läuft bereits.' );
            }
        }

        // Progress initialisieren
        set_transient( 'bbb_sync_progress', [
            'running'       => true,
            'phase'         => 'starting',
            'current_label' => 'Sync wird gestartet...',
            'current_team'  => 0,
            'total_teams'   => 0,
            'matches_done'  => 0,
            'matches_total' => 0,
            'started_at'    => time(),
        ], 600 );

        // ── HTTP-Verbindung sofort schließen, PHP weiterarbeiten ──
        @set_time_limit( 600 );
        @ignore_user_abort( true );

        // JSON-Response aufbauen
        $response = wp_json_encode( [ 'success' => true, 'data' => [ 'message' => 'Sync gestartet' ] ] );

        // Alle bestehenden Output-Buffer leeren
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Response senden und Connection schließen
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Length: ' . strlen( $response ) );
        header( 'Connection: close' );
        echo $response;
        flush();

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }

        // Ab hier läuft PHP im Hintergrund weiter
        // Der Browser hat die Antwort bereits erhalten
        $engine = new BBB_Sync_Engine();

        try {
            $stats = $engine->sync_all();
            set_transient( 'bbb_sync_notice', $this->format_stats_message( $stats ), 300 );
        } catch ( \Throwable $e ) {
            // Fehler abfangen damit Progress auf jeden Fall auf "done" gesetzt wird
            set_transient( 'bbb_sync_progress', [
                'running'       => false,
                'phase'         => 'done',
                'current_label' => 'Sync-Fehler: ' . $e->getMessage(),
                'stats'         => [ 'errors' => 1 ],
                'finished_at'   => time(),
            ], 300 );

            set_transient( 'bbb_sync_notice', 'Sync-Fehler: ' . $e->getMessage(), 300 );
            set_transient( 'bbb_sync_notice_type', 'error', 300 );
        }

        exit; // PHP-Prozess beenden
    }

    // ═════════════════════════════════════════
    // AJAX: Fortschritt abfragen
    // ═════════════════════════════════════════

    public function ajax_sync_progress(): void {
        check_ajax_referer( 'bbb_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $progress = BBB_Sync_Engine::get_progress();

        // Deadlock-Erkennung: Sync läuft angeblich, aber kein Update seit 120s
        if ( ! empty( $progress['running'] ) ) {
            $started = $progress['started_at'] ?? 0;
            $last_update = $progress['last_update'] ?? $started;
            if ( $last_update && ( time() - $last_update ) > 120 ) {
                $progress['running'] = false;
                $progress['phase']   = 'done';
                $progress['current_label'] = 'Sync scheint abgebrochen (keine Updates seit 2 Min.)';
                delete_transient( 'bbb_sync_progress' );
            }
        }

        wp_send_json_success( $progress );
    }

    // ═════════════════════════════════════════
    // Synchrone Actions (Discovery, Settings, etc.)
    // ═════════════════════════════════════════

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['bbb_discover_teams'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            $engine    = new BBB_Sync_Engine();
            $discovery = $engine->discover_teams();
            if ( isset( $discovery['error'] ) ) {
                set_transient( 'bbb_sync_notice', $discovery['error'], 30 );
                set_transient( 'bbb_sync_notice_type', 'error', 30 );
            } else {
                set_transient( 'bbb_discovery_data', $discovery, 300 );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=discovery' ) );
            exit;
        }

        if ( isset( $_POST['bbb_register_teams'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            $selected = array_map( 'intval', (array) ( $_POST['bbb_selected_teams'] ?? [] ) );
            if ( ! empty( $selected ) ) {
                ( new BBB_Sync_Engine() )->register_own_teams( $selected );
                set_transient( 'bbb_sync_notice', count( $selected ) . ' Teams registriert.', 30 );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync' ) );
            exit;
        }

        if ( isset( $_POST['bbb_clear_logs'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            delete_option( 'bbb_sync_logs' );
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=logs' ) );
            exit;
        }

        if ( isset( $_POST['bbb_reset_boxscore_flags'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            global $wpdb;
            $n = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_bbb_boxscore_synced'" );
            set_transient( 'bbb_sync_notice', "{$n} Boxscore-Flags zurückgesetzt.", 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=cleanup' ) );
            exit;
        }

        // Tabellen-Cache leeren (Live Tables + Brackets)
        if ( isset( $_POST['bbb_clear_table_cache'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            global $wpdb;
            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_bbb_live_table_%'
                 OR option_name LIKE '_transient_timeout_bbb_live_table_%'
                 OR option_name LIKE '_transient_bbb_bracket_%'
                 OR option_name LIKE '_transient_timeout_bbb_bracket_%'"
            );
            set_transient( 'bbb_sync_notice', "Tabellen-Cache geleert ({$deleted} Einträge entfernt).", 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=dashboard' ) );
            exit;
        }

        // Deadlock manuell lösen
        if ( isset( $_POST['bbb_reset_sync_lock'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            delete_transient( 'bbb_sync_progress' );
            set_transient( 'bbb_sync_notice', 'Sync-Lock gelöst.', 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync' ) );
            exit;
        }

        // Repair: Result-Keys in sp_results korrigieren
        if ( isset( $_POST['bbb_repair_result_keys'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            $this->handle_repair_result_keys();
            exit;
        }

        // Cleanup: Spieler löschen
        if ( isset( $_POST['bbb_cleanup_players'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            $this->handle_cleanup_players();
            exit;
        }

        // Cleanup: ALLES löschen (Full Reset)
        if ( isset( $_POST['bbb_cleanup_all'] ) && check_admin_referer( 'bbb_sync_action' ) ) {
            $this->handle_cleanup_all();
            exit;
        }
    }

    public function show_notices(): void {
        $notice = get_transient( 'bbb_sync_notice' );
        if ( ! $notice ) return;
        $type = get_transient( 'bbb_sync_notice_type' ) ?: 'success';
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice ) );
        delete_transient( 'bbb_sync_notice' );
        delete_transient( 'bbb_sync_notice_type' );
    }

    // ═════════════════════════════════════════
    // META-BOX: DBB-Daten (sp_player + sp_team)
    // ═════════════════════════════════════════

    public function add_dbb_meta_boxes(): void {
        add_meta_box(
            'bbb_dbb_data',
            'BBB-Daten',
            [ $this, 'render_dbb_meta_box' ],
            'sp_player',
            'side',
            'default'
        );
        add_meta_box(
            'bbb_dbb_data',
            'BBB-Daten',
            [ $this, 'render_dbb_meta_box' ],
            'sp_team',
            'side',
            'default'
        );
        add_meta_box(
            'bbb_dbb_data',
            'BBB-Daten',
            [ $this, 'render_dbb_meta_box' ],
            'sp_event',
            'side',
            'default'
        );
    }

    public function render_dbb_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'bbb_dbb_meta', 'bbb_dbb_meta_nonce' );

        if ( $post->post_type === 'sp_player' ) {
            $person_id = get_post_meta( $post->ID, '_bbb_person_id', true );
            ?>
            <p>
                <label><strong>BBB Person-ID</strong></label><br>
                <span class="bbb-id-display" style="font-family:monospace; font-size:13px;">
                    <?php echo $person_id ? esc_html( $person_id ) : '<em style="color:#999;">nicht gesetzt</em>'; ?>
                </span>
                <input type="number" name="bbb_person_id"
                       value="<?php echo esc_attr( $person_id ); ?>"
                       class="widefat bbb-id-input" min="1" placeholder="z.B. 12345"
                       style="display:none;">
            </p>
            <?php

        } elseif ( $post->post_type === 'sp_team' ) {
            $permanent_id = get_post_meta( $post->ID, '_bbb_team_permanent_id', true );
            ?>
            <p>
                <label><strong>BBB Team Permanent-ID</strong></label><br>
                <span class="bbb-id-display" style="font-family:monospace; font-size:13px;">
                    <?php echo $permanent_id ? esc_html( $permanent_id ) : '<em style="color:#999;">nicht gesetzt</em>'; ?>
                </span>
                <input type="number" name="bbb_team_permanent_id"
                       value="<?php echo esc_attr( $permanent_id ); ?>"
                       class="widefat bbb-id-input" min="1" placeholder="z.B. 67890"
                       style="display:none;">
            </p>
            <?php

        } elseif ( $post->post_type === 'sp_event' ) {
            $match_id  = get_post_meta( $post->ID, '_bbb_match_id', true );
            $liga_id   = get_post_meta( $post->ID, '_bbb_liga_id', true );
            $match_day = get_post_meta( $post->ID, '_bbb_match_day', true );
            ?>
            <p>
                <label><strong>BBB Match-ID</strong></label><br>
                <span class="bbb-id-display" style="font-family:monospace; font-size:13px;">
                    <?php echo $match_id ? esc_html( $match_id ) : '<em style="color:#999;">nicht gesetzt</em>'; ?>
                </span>
                <input type="number" name="bbb_match_id"
                       value="<?php echo esc_attr( $match_id ); ?>"
                       class="widefat bbb-id-input" min="1" placeholder="z.B. 123456"
                       style="display:none;">
            </p>
            <p>
                <label><strong>BBB Liga-ID</strong></label><br>
                <span class="bbb-id-display" style="font-family:monospace; font-size:13px;">
                    <?php echo $liga_id ? esc_html( $liga_id ) : '<em style="color:#999;">nicht gesetzt</em>'; ?>
                </span>
                <input type="number" name="bbb_liga_id"
                       value="<?php echo esc_attr( $liga_id ); ?>"
                       class="widefat bbb-id-input" min="1" placeholder="z.B. 4567"
                       style="display:none;">
            </p>
            <?php if ( $match_day ) : ?>
            <p>
                <span class="description" style="font-size:11px;">Spieltag: <strong><?php echo esc_html( $match_day ); ?></strong></span>
            </p>
            <?php endif; ?>
            <?php if ( $match_id ) : ?>
            <p style="margin-top:8px;">
                <a href="https://www.basketball-bund.net/game/id/<?php echo esc_attr( $match_id ); ?>"
                   target="_blank" class="button button-small">
                    ↗ Auf basketball-bund.net anzeigen
                </a>
            </p>
            <?php endif; ?>
            <?php
        }

        // Unlock-Checkbox (alle Post-Types)
        ?>
        <hr style="margin:10px 0 8px;">
        <label style="font-size:11px; color:#999; cursor:pointer;">
            <input type="checkbox" class="bbb-unlock-checkbox" style="margin:0 4px 0 0;">
            Bearbeiten freischalten
        </label>
        <p class="description" style="font-size:10px; color:#bbb; margin-top:4px;">
            Diese IDs werden automatisch vom Sync gesetzt.<br>
            Manuelle Änderungen können die Zuordnung zerstören.
        </p>
        <script>
        (function() {
            var box = document.querySelector('#bbb_dbb_data');
            if (!box) return;
            var cb = box.querySelector('.bbb-unlock-checkbox');
            if (!cb) return;
            cb.addEventListener('change', function() {
                var displays = box.querySelectorAll('.bbb-id-display');
                var inputs   = box.querySelectorAll('.bbb-id-input');
                displays.forEach(function(el) { el.style.display = cb.checked ? 'none' : ''; });
                inputs.forEach(function(el)   { el.style.display = cb.checked ? '' : 'none'; });
            });
        })();
        </script>
        <?php
    }

    public function save_dbb_meta( int $post_id ): void {
        if ( ! isset( $_POST['bbb_dbb_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['bbb_dbb_meta_nonce'], 'bbb_dbb_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $post_type = get_post_type( $post_id );

        if ( $post_type === 'sp_player' && isset( $_POST['bbb_person_id'] ) ) {
            $val = absint( $_POST['bbb_person_id'] );
            if ( $val > 0 ) {
                update_post_meta( $post_id, '_bbb_person_id', $val );
            } else {
                delete_post_meta( $post_id, '_bbb_person_id' );
            }
        }

        if ( $post_type === 'sp_team' && isset( $_POST['bbb_team_permanent_id'] ) ) {
            $val = absint( $_POST['bbb_team_permanent_id'] );
            if ( $val > 0 ) {
                update_post_meta( $post_id, '_bbb_team_permanent_id', $val );
            } else {
                delete_post_meta( $post_id, '_bbb_team_permanent_id' );
            }
        }

        if ( $post_type === 'sp_event' ) {
            if ( isset( $_POST['bbb_match_id'] ) ) {
                $val = absint( $_POST['bbb_match_id'] );
                if ( $val > 0 ) {
                    update_post_meta( $post_id, '_bbb_match_id', $val );
                } else {
                    delete_post_meta( $post_id, '_bbb_match_id' );
                }
            }
            if ( isset( $_POST['bbb_liga_id'] ) ) {
                $val = absint( $_POST['bbb_liga_id'] );
                if ( $val > 0 ) {
                    update_post_meta( $post_id, '_bbb_liga_id', $val );
                } else {
                    delete_post_meta( $post_id, '_bbb_liga_id' );
                }
            }
        }
    }

    // ═════════════════════════════════════════
    // VENUE TAXONOMY: BBB-Felder
    // ═════════════════════════════════════════

    /**
     * BBB-Felder im Venue-Term-Editor anzeigen (Edit-Formular).
     */
    public function render_venue_bbb_fields( \WP_Term $term, string $taxonomy ): void {
        $spielfeld_id = get_term_meta( $term->term_id, '_bbb_spielfeld_id', true );
        wp_nonce_field( 'bbb_venue_meta', 'bbb_venue_meta_nonce' );
        ?>
        <tr class="form-field">
            <th scope="row">
                <label>BBB Spielfeld-ID</label>
            </th>
            <td>
                <span id="bbb-venue-id-display" style="font-family:monospace; font-size:13px;">
                    <?php echo $spielfeld_id ? esc_html( $spielfeld_id ) : '<em style="color:#999;">nicht gesetzt</em>'; ?>
                </span>
                <input type="number" id="bbb_spielfeld_id" name="bbb_spielfeld_id"
                       value="<?php echo esc_attr( $spielfeld_id ); ?>"
                       min="1" placeholder="z.B. 789" style="width:200px; display:none;">
                <p class="description" style="margin-top:8px;">
                    <label style="cursor:pointer; color:#999;">
                        <input type="checkbox" id="bbb-venue-unlock" style="margin:0 4px 0 0;">
                        Bearbeiten freischalten
                    </label><br>
                    <span style="font-size:11px; color:#bbb;">
                        Wird vom Sync automatisch gesetzt. Manuelle Änderungen können die Zuordnung zerstören.
                    </span>
                </p>
                <script>
                (function() {
                    var cb = document.getElementById('bbb-venue-unlock');
                    if (!cb) return;
                    cb.addEventListener('change', function() {
                        document.getElementById('bbb-venue-id-display').style.display = cb.checked ? 'none' : '';
                        document.getElementById('bbb_spielfeld_id').style.display = cb.checked ? '' : 'none';
                    });
                })();
                </script>
            </td>
        </tr>
        <?php
    }

    /**
     * BBB-Felder beim Speichern des Venue-Terms sichern.
     */
    public function save_venue_bbb_fields( int $term_id ): void {
        if ( ! isset( $_POST['bbb_venue_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['bbb_venue_meta_nonce'], 'bbb_venue_meta' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['bbb_spielfeld_id'] ) ) {
            $val = absint( $_POST['bbb_spielfeld_id'] );
            if ( $val > 0 ) {
                update_term_meta( $term_id, '_bbb_spielfeld_id', $val );
            } else {
                delete_term_meta( $term_id, '_bbb_spielfeld_id' );
            }
        }
    }

    // ═════════════════════════════════════════
    // PAGE RENDER
    // ═════════════════════════════════════════

    public function render_page(): void {
        $tab = $_GET['tab'] ?? 'dashboard';
        ?>
        <div class="wrap">
            <h1>BBB SportsPress Sync</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( [
                    'dashboard' => 'Dashboard', 'discovery' => 'Team-Discovery',
                    'cleanup' => 'Cleanup', 'settings' => 'Einstellungen', 'logs' => 'Logs',
                    'support' => '❤️ Support',
                ] as $slug => $label ) : ?>
                    <a href="?page=bbb-sync&tab=<?php echo $slug; ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div style="margin-top:20px;">
                <?php match ($tab) {
                    'discovery' => $this->render_discovery_tab(),
                    'cleanup'   => $this->render_cleanup_tab(),
                    'settings'  => $this->render_settings_tab(),
                    'logs'      => $this->render_logs_tab(),
                    'support'   => $this->render_support_tab(),
                    default     => $this->render_dashboard_tab(),
                }; ?>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: DASHBOARD
    // ─────────────────────────────────────────

    private function render_dashboard_tab(): void {
        $own_teams  = get_option( 'bbb_sync_own_teams', [] );
        $last_run   = get_option( 'bbb_sync_last_run', 'Noch nie' );
        $last_stats = get_option( 'bbb_sync_last_stats', [] );
        $club_id    = (int) get_option( 'bbb_sync_club_id', 0 );
        $nonce      = wp_create_nonce( 'bbb_sync_nonce' );

        // Prüfe ob gerade ein Sync läuft (z.B. nach Tab-Wechsel)
        $current_progress = BBB_Sync_Engine::get_progress();
        $sync_running = ! empty( $current_progress['running'] );
        ?>

        <?php if ( ! $club_id ) : ?>
            <div class="notice notice-warning inline">
                <p><a href="?page=bbb-sync&tab=settings">Einstellungen</a> → Club-ID eingeben.</p>
            </div>
        <?php elseif ( empty( $own_teams ) ) : ?>
            <div class="notice notice-info inline">
                <p><a href="?page=bbb-sync&tab=discovery">Team-Discovery</a> durchführen.</p>
            </div>
        <?php else : ?>

            <!-- Progress-Box -->
            <div id="bbb-progress-box" style="display:<?php echo $sync_running ? 'block' : 'none'; ?>; max-width:650px; margin-bottom:20px;">
                <div class="card" style="padding:15px; border-left:4px solid #2271b1;">
                    <h3 style="margin-top:0;" id="bbb-p-header">
                        <span class="spinner is-active" style="float:left; margin-right:8px;" id="bbb-p-spinner"></span>
                        <span id="bbb-p-title">Sync läuft...</span>
                    </h3>
                    <div id="bbb-p-label" style="margin-bottom:10px; font-weight:500;">
                        <?php echo $sync_running ? esc_html( $current_progress['current_label'] ?? '' ) : ''; ?>
                    </div>
                    <div style="background:#e0e0e0; border-radius:4px; height:24px; overflow:hidden; position:relative;">
                        <div id="bbb-p-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.5s ease; border-radius:4px;"></div>
                        <div id="bbb-p-pct" style="position:absolute; top:0; left:0; right:0; text-align:center; line-height:24px; font-size:12px; font-weight:600; color:#333;"></div>
                    </div>
                    <div id="bbb-p-detail" style="margin-top:8px; font-size:12px; color:#666;"></div>
                </div>
            </div>

            <!-- Status -->
            <div class="card" style="max-width:650px; padding:15px;">
                <h2>Status</h2>
                <table class="widefat striped">
                    <tr><td>Club-ID</td><td><strong><?php echo esc_html( $club_id ); ?></strong></td></tr>
                    <tr><td>Teams</td><td><strong><?php echo count( $own_teams ); ?></strong></td></tr>
                    <tr><td>Spieler-Import</td>
                        <td><?php
                            if ( (bool) get_option( 'bbb_sync_players_enabled' ) ) {
                                $own_only = (bool) get_option( 'bbb_sync_players_own_only', true );
                                echo '<span style="color:green;">✅ Aktiv</span>';
                                echo $own_only ? ' (nur eigene)' : ' (alle Teams)';
                            } else {
                                echo '❌ Aus (<a href="?page=bbb-sync&tab=settings">ändern</a>)';
                            }
                        ?></td>
                    </tr>
                    <tr><td>Letzter Sync</td><td><?php echo esc_html( $last_run ); ?></td></tr>
                    <?php if ( $last_stats ) : ?>
                    <tr><td>Ergebnis</td>
                        <td style="font-size:13px;">
                            T:<?php echo (int)($last_stats['teams_created']??0); ?>/<?php echo (int)($last_stats['teams_updated']??0); ?>
                            E:<?php echo (int)($last_stats['events_created']??0); ?>/<?php echo (int)($last_stats['events_updated']??0); ?>/<?php echo (int)($last_stats['events_deleted']??0); ?>
                            V:<?php echo (int)($last_stats['venues_created']??0); ?>/<?php echo (int)($last_stats['venues_updated']??0); ?>
                            Tab:<?php echo (int)($last_stats['tables_created']??0); ?>/<?php echo (int)($last_stats['tables_updated']??0); ?>
                            <?php if (($last_stats['players_created']??0)+($last_stats['players_updated']??0)>0): ?>
                                P:<?php echo (int)$last_stats['players_created']; ?>/<?php echo (int)$last_stats['players_updated']; ?>
                            <?php endif; ?>
                            API:<?php echo (int)($last_stats['api_calls']??0); ?>
                            <?php if (($last_stats['errors']??0)>0): ?>
                                <span style="color:red;">Err:<?php echo (int)$last_stats['errors']; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <div style="margin-top:15px;">
                    <button type="button" class="button button-primary" id="bbb-sync-btn"
                            <?php echo $sync_running ? 'disabled' : ''; ?>>
                        <?php echo $sync_running ? 'Sync läuft...' : 'Jetzt synchronisieren'; ?>
                    </button>
                    <span id="bbb-sync-msg" style="margin-left:10px; color:#666;"></span>
                </div>
            </div>

            <!-- Shortcodes -->
            <?php $this->render_shortcodes_card(); ?>

            <!-- Schnellaktionen -->
            <div class="card" style="max-width:650px; padding:15px; margin-top:15px;">
                <h2>🛠️ Schnellaktionen</h2>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start;">
                    <form method="post">
                        <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                        <?php submit_button( 'Tabellen-Cache leeren', 'secondary', 'bbb_clear_table_cache', false ); ?>
                    </form>
                    <form method="post">
                        <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                        <?php submit_button( 'Sync-Lock lösen', 'secondary', 'bbb_reset_sync_lock', false ); ?>
                    </form>
                </div>
                <p class="description" style="margin-top:8px;">
                    <strong>Tabellen-Cache:</strong> Leert den Cache für Live-Tabellen und Brackets. Die Daten werden beim nächsten Seitenaufruf frisch von basketball-bund.net geladen.<br>
                    <strong>Sync-Lock:</strong> Löst einen hängengebliebenen Sync-Prozess. Nutze dies, wenn der Button dauerhaft "Sync läuft…" anzeigt, obwohl kein Sync mehr aktiv ist.
                </p>
            </div>

            <script>
            (function() {
                const btn     = document.getElementById('bbb-sync-btn');
                const msg     = document.getElementById('bbb-sync-msg');
                const box     = document.getElementById('bbb-progress-box');
                const bar     = document.getElementById('bbb-p-bar');
                const pct     = document.getElementById('bbb-p-pct');
                const label   = document.getElementById('bbb-p-label');
                const detail  = document.getElementById('bbb-p-detail');
                const title   = document.getElementById('bbb-p-title');
                const spinner = document.getElementById('bbb-p-spinner');
                const nonce   = '<?php echo esc_js( $nonce ); ?>';
                const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                let polling   = null;
                let alreadyRunning = <?php echo $sync_running ? 'true' : 'false'; ?>;

                // Bei Seitenreload: Polling sofort starten wenn Sync läuft
                if (alreadyRunning) {
                    startPolling();
                }

                if (btn) {
                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        btn.textContent = 'Wird gestartet...';
                        msg.textContent = '';
                        box.style.display = 'block';
                        label.textContent = 'Starte Sync...';
                        bar.style.width = '2%';
                        bar.style.background = '#2271b1';
                        title.textContent = 'Sync läuft...';
                        spinner.style.display = '';

                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=bbb_start_sync&nonce=' + nonce
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                btn.textContent = 'Sync läuft...';
                                startPolling();
                            } else {
                                fail(res.data || 'Unbekannter Fehler');
                            }
                        })
                        .catch(() => fail('Netzwerkfehler'));
                    });
                }

                function fail(text) {
                    btn.disabled = false;
                    btn.textContent = 'Jetzt synchronisieren';
                    msg.textContent = '❌ ' + text;
                    msg.style.color = 'red';
                    box.style.display = 'none';
                }

                function startPolling() {
                    if (polling) return;
                    polling = setInterval(pollProgress, 2000);
                    // Sofort einmal abfragen
                    pollProgress();
                }

                function pollProgress() {
                    fetch(ajaxUrl + '?action=bbb_sync_progress&nonce=' + nonce, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success) return;
                        const p = res.data;

                        // ── Fertig ──
                        if (!p.running && (p.phase === 'done' || p.phase === 'idle')) {
                            clearInterval(polling);
                            polling = null;

                            if (p.phase === 'done' && p.stats) {
                                title.textContent = '✅ Sync abgeschlossen!';
                                spinner.style.display = 'none';
                                label.textContent = p.current_label || '';
                                bar.style.width = '100%';
                                bar.style.background = '#00a32a';
                                pct.textContent = '100%';

                                const s = p.stats;
                                detail.innerHTML =
                                    'Teams: ' + (s.teams_created||0) + '/' + (s.teams_updated||0) +
                                    ' · Events: ' + (s.events_created||0) + '/' + (s.events_updated||0) + '/' + (s.events_deleted||0) +
                                    ' · Venues: ' + (s.venues_created||0) + '/' + (s.venues_updated||0) +
                                    ' · Tabellen: ' + (s.tables_created||0) + '/' + (s.tables_updated||0) +
                                    ((s.players_created||0)+(s.players_updated||0) > 0
                                        ? ' · Spieler: ' + s.players_created + '/' + s.players_updated : '') +
                                    ' · ' + (s.api_calls||0) + ' API-Calls' +
                                    ((s.errors||0) > 0 ? ' · <span style="color:red">' + s.errors + ' Fehler</span>' : '');
                            } else {
                                // idle oder abgebrochen
                                title.textContent = p.current_label || 'Sync beendet';
                                spinner.style.display = 'none';
                            }

                            btn.disabled = false;
                            btn.textContent = 'Jetzt synchronisieren';
                            return;
                        }

                        // ── Läuft noch ──
                        if (p.current_label) label.textContent = p.current_label;

                        let percent = 0;
                        if (p.total_teams > 0 && p.matches_total > 0) {
                            const tp = Math.max(0, (p.current_team - 1)) / p.total_teams;
                            const mp = (p.matches_done || 0) / Math.max(1, p.matches_total) / p.total_teams;
                            percent = Math.round((tp + mp) * 100);
                        } else if (p.total_teams > 0) {
                            percent = Math.round(((p.current_team || 0) / p.total_teams) * 50);
                        } else {
                            percent = 5;
                        }
                        percent = Math.max(2, Math.min(percent, 98));
                        bar.style.width = percent + '%';
                        pct.textContent = percent + '%';

                        let d = '';
                        if (p.current_team && p.total_teams) d += 'Team ' + p.current_team + '/' + p.total_teams;
                        if (p.matches_done !== undefined && p.matches_total) d += ' · Match ' + p.matches_done + '/' + p.matches_total;
                        if (p.started_at) {
                            const el = Math.round(Date.now()/1000 - p.started_at);
                            d += ' · ' + Math.floor(el/60) + ':' + String(el%60).padStart(2,'0');
                        }
                        detail.textContent = d;
                    })
                    .catch(() => {});
                }
            })();
            </script>

        <?php endif;
    }

    // ─────────────────────────────────────────
    // TAB: DISCOVERY
    // ─────────────────────────────────────────

    private function render_discovery_tab(): void {
        $club_id   = (int) get_option( 'bbb_sync_club_id', 0 );
        $own_teams = get_option( 'bbb_sync_own_teams', [] );
        $discovery = get_transient( 'bbb_discovery_data' );

        if ( ! $club_id ) : ?>
            <div class="notice notice-warning inline">
                <p><a href="?page=bbb-sync&tab=settings">Einstellungen</a> → Club-ID.</p>
            </div>
            <?php return;
        endif; ?>

        <div class="card" style="max-width:900px; padding:15px;">
            <h2>Team-Discovery</h2>
            <p>Club-ID: <?php echo esc_html( $club_id ); ?></p>

            <?php if ( ! empty( $own_teams ) ) : ?>
                <div class="notice notice-info inline" style="margin:10px 0;">
                    <p><strong><?php echo count( $own_teams ); ?> Teams registriert.</strong></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                <?php submit_button( 'Teams erkennen', 'secondary', 'bbb_discover_teams', false ); ?>
            </form>

            <?php if ( $discovery && isset( $discovery['own_teams'] ) ) : ?>
                <hr>
                <h3><?php echo count( $discovery['own_teams'] ); ?> Teams</h3>
                <p><?php echo (int) $discovery['match_count']; ?> Matches · <?php echo count( $discovery['all_leagues'] ); ?> Ligen</p>

                <form method="post">
                    <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width:30px;"><input type="checkbox" id="bbb-sel-all" checked></th>
                                <th>Team</th>
                                <th>AK</th>
                                <th>Liga(en)</th>
                                <th>ID</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $discovery['own_teams'] as $pid => $team ) :
                            $already = in_array( (int) $pid, $own_teams, true );
                            $ak = $team['akName'] ?? '';
                            $g  = match ($team['geschlecht'] ?? '') { 'mix'=>'⚥','maennlich','m'=>'♂','weiblich','w'=>'♀', default=>'' };
                        ?>
                            <tr>
                                <td><input type="checkbox" name="bbb_selected_teams[]" value="<?php echo esc_attr($pid); ?>" checked></td>
                                <td><strong><?php echo esc_html( $team['teamname'] ); ?></strong></td>
                                <td>
                                    <?php if ($ak): ?>
                                        <span style="background:#e8f0fe; padding:2px 8px; border-radius:3px; font-size:12px;">
                                            <?php echo esc_html( "{$ak} {$g}" ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;">
                                    <?php foreach ($team['ligen'] ?? [] as $l): ?>
                                        <div><?php echo esc_html($l); ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><code style="font-size:11px;"><?php echo esc_html($pid); ?></code></td>
                                <td><?php echo $already ? '✅' : '🆕'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:15px;">
                        <?php submit_button( 'Registrieren', 'primary', 'bbb_register_teams', false ); ?>
                    </p>
                </form>
                <script>
                document.getElementById('bbb-sel-all')?.addEventListener('change', function() {
                    document.querySelectorAll('input[name="bbb_selected_teams[]"]').forEach(cb => cb.checked = this.checked);
                });
                </script>
                <?php delete_transient( 'bbb_discovery_data' ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: SETTINGS
    // ─────────────────────────────────────────

    private function render_settings_tab(): void {
        ?>
        <div class="card" style="max-width:600px; padding:15px;">
            <h2>Einstellungen</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'bbb_sync_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="bbb_sync_club_id">Club-ID</label></th>
                        <td><input type="number" id="bbb_sync_club_id" name="bbb_sync_club_id"
                                   value="<?php echo esc_attr( get_option('bbb_sync_club_id','') ); ?>" class="regular-text" min="1"></td>
                    </tr>
                    <tr>
                        <th><label for="bbb_sync_range_days">Zeitraum (Tage)</label></th>
                        <td><input type="number" id="bbb_sync_range_days" name="bbb_sync_range_days"
                                   value="<?php echo esc_attr( get_option('bbb_sync_range_days',365) ); ?>" class="small-text" min="30" max="730"></td>
                    </tr>
                    <tr>
                        <th><label for="bbb_sync_interval">Auto-Sync (h)</label></th>
                        <td><input type="number" id="bbb_sync_interval" name="bbb_sync_interval"
                                   value="<?php echo esc_attr( get_option('bbb_sync_interval',6) ); ?>" class="small-text" min="1" max="168"></td>
                    </tr>
                    <tr>
                        <th>Ergebnis-Felder</th>
                        <td>
                            <?php
                            $result_posts = get_posts([
                                'post_type'      => 'sp_result',
                                'posts_per_page' => -1,
                                'post_status'    => 'publish',
                                'orderby'        => 'menu_order',
                                'order'          => 'ASC',
                            ]);
                            $saved = array_filter( array_map( 'trim', explode( ',', get_option( 'bbb_sync_result_slugs', '' ) ) ) );
                            if ( ! empty( $result_posts ) ) : ?>
                                <fieldset>
                                    <?php foreach ( $result_posts as $rp ) :
                                        $slug = $rp->post_name;
                                        $checked = in_array( $slug, $saved, true ) ? 'checked' : '';
                                    ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="bbb_sync_result_slugs_arr[]" value="<?php echo esc_attr( $slug ); ?>" <?php echo $checked; ?>>
                                        <code><?php echo esc_html( $slug ); ?></code> – <?php echo esc_html( $rp->post_title ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description">In welche SP-Ergebnis-Spalten soll das Gesamtergebnis geschrieben werden?<br>
                                    Typisch bei Basketball: <code>t</code> (Total) + ggf. <code>pts</code>.<br>
                                    Leer = automatisch erster sp_result Post.</p>
                            <?php else : ?>
                                <p class="description">Keine sp_result Posts gefunden. Erst einen Sync durchführen.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Spieler-Import</th>
                        <td>
                            <input type="hidden" name="bbb_sync_players_enabled" value="0">
                            <label><input type="checkbox" id="bbb_sync_players_enabled" name="bbb_sync_players_enabled"
                                   value="1" <?php checked( (bool) get_option('bbb_sync_players_enabled') ); ?>>
                                Spieler aus Boxscore</label>
                            <p class="description">Mini-Ligen (U8–U12): meist kein Boxscore.</p>
                            <div style="margin-top:8px; padding-left:4px;">
                                <input type="hidden" name="bbb_sync_players_own_only" value="0">
                                <label><input type="checkbox" id="bbb_sync_players_own_only" name="bbb_sync_players_own_only"
                                       value="1" <?php checked( (bool) get_option('bbb_sync_players_own_only', true) ); ?>>
                                    Nur eigene Spieler</label>
                                <p class="description">Wenn aktiv, werden nur Spieler der eigenen Teams angelegt (keine Gegner).<br>
                                Empfohlen: Weniger Datenmüll, DSGVO-freundlicher.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th style="vertical-align:top;">Statistik-Mapping</th>
                        <td>
                            <?php
                            $perf_posts = get_posts([
                                'post_type'      => 'sp_performance',
                                'posts_per_page' => -1,
                                'post_status'    => 'publish',
                                'orderby'        => 'menu_order',
                                'order'          => 'ASC',
                            ]);
                            $saved_mapping = json_decode( get_option( 'bbb_sync_stat_mapping', '' ), true ) ?: [];

                            // BBB-API Felder mit Labels und Default-SP-Slugs
                            $bbb_fields = [
                                'Punkte & Effizienz' => [
                                    'pts'                => [ 'Punkte (pts)', 'pts' ],
                                    'eff'                => [ 'Effizienz (eff)', 'eff' ],
                                    'esz'                => [ 'Einsatzzeit (esz)', 'min' ],
                                ],
                                'Würfe (Made / Attempted)' => [
                                    'wt.made'            => [ 'Field Goals Made', 'fgm' ],
                                    'wt.attempted'       => [ 'Field Goals Attempted', 'fga' ],
                                    'twopoints.made'     => [ '2-Punkte Made', '2pm' ],
                                    'twopoints.attempted'=> [ '2-Punkte Attempted', '2pa' ],
                                    'threepoints.made'   => [ '3-Punkte Made', '3pm' ],
                                    'threepoints.attempted'=> [ '3-Punkte Attempted', '3pa' ],
                                    'onepoints.made'     => [ 'Freiwürfe Made', 'ftm' ],
                                    'onepoints.attempted'=> [ 'Freiwürfe Attempted', 'fta' ],
                                ],
                                'Rebounds' => [
                                    'ro'                 => [ 'Offensiv-Rebounds (ro)', 'off' ],
                                    'rd'                 => [ 'Defensiv-Rebounds (rd)', 'def' ],
                                    'rt'                 => [ 'Rebounds Total (rt)', 'reb' ],
                                ],
                                'Sonstiges' => [
                                    'as'                 => [ 'Assists (as)', 'ast' ],
                                    'st'                 => [ 'Steals (st)', 'stl' ],
                                    'to'                 => [ 'Turnovers (to)', 'to' ],
                                    'bs'                 => [ 'Blocks (bs)', 'blk' ],
                                    'fouls'              => [ 'Fouls', 'pf' ],
                                ],
                            ];

                            if ( ! empty( $perf_posts ) ) : ?>
                                <table class="widefat fixed" style="max-width:500px;">
                                    <thead><tr><th>BBB-API Feld</th><th>SportsPress Spalte</th></tr></thead>
                                    <tbody>
                                    <?php foreach ( $bbb_fields as $group => $fields ) : ?>
                                        <tr><td colspan="2" style="background:#f0f0f1; font-weight:600; padding:6px 10px;"><?php echo esc_html( $group ); ?></td></tr>
                                        <?php foreach ( $fields as $bbb_key => [ $label, $default_slug ] ) :
                                            $current = $saved_mapping[ $bbb_key ] ?? $default_slug;
                                        ?>
                                        <tr>
                                            <td><span title="<?php echo esc_attr( $bbb_key ); ?>"><?php echo esc_html( $label ); ?></span></td>
                                            <td>
                                                <select name="bbb_sync_stat_map[<?php echo esc_attr( $bbb_key ); ?>]" style="width:100%;">
                                                    <option value="">– nicht mappen –</option>
                                                    <?php foreach ( $perf_posts as $pp ) :
                                                        $sel = selected( $current, $pp->post_name, false );
                                                    ?>
                                                        <option value="<?php echo esc_attr( $pp->post_name ); ?>" <?php echo $sel; ?>>
                                                            <?php echo esc_html( $pp->post_name ); ?> – <?php echo esc_html( $pp->post_title ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p class="description">Welches BBB-API-Feld in welche SportsPress Performance-Spalte geschrieben wird.<br>
                                    Leer = Feld wird nicht synchronisiert. Defaults basieren auf Standard-Basketball-Slugs.</p>
                            <?php else : ?>
                                <p class="description">Keine sp_performance Posts gefunden. Erst einen Sync durchführen.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>


        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // SHORTCODES CARD (Dashboard + ggf. andere Tabs)
    // ─────────────────────────────────────────

    private function render_shortcodes_card(): void {
        // Ligen aus sp_league mit _bbb_liga_id
        $leagues = get_terms([
            'taxonomy'   => 'sp_league',
            'hide_empty' => false,
            'orderby'    => 'name',
        ]);
        if ( is_wp_error( $leagues ) || empty( $leagues ) ) return;

        $rows = [];
        foreach ( $leagues as $league ) {
            $liga_id = get_term_meta( $league->term_id, '_bbb_liga_id', true );
            if ( ! $liga_id ) continue;

            // Typ ermitteln: Gibt es eine sp_table für diese Liga?
            $has_table = (bool) get_posts([
                'post_type' => 'sp_table', 'posts_per_page' => 1, 'fields' => 'ids',
                'tax_query' => [[ 'taxonomy' => 'sp_league', 'terms' => $league->term_id ]],
            ]);

            $rows[] = [
                'liga_id' => $liga_id,
                'name'    => $league->name,
                'type'    => $has_table ? 'league' : 'tournament',
            ];
        }

        if ( empty( $rows ) ) return;
        ?>
        <div class="card" style="max-width:650px; padding:15px; margin-top:15px;">
            <h2>Verfügbare Shortcodes</h2>
            <p class="description" style="margin-bottom:10px;">Zum Einbetten in Seiten, Beiträge oder den Page Builder.</p>
            <table class="widefat striped" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Liga</th>
                        <th>Typ</th>
                        <th>Shortcode</th>
                        <th>Goodlayers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r['name'] ); ?></td>
                        <td>
                            <?php if ( $r['type'] === 'league' ) : ?>
                                <span style="color:#27ae60;">●</span> Tabelle
                            <?php else : ?>
                                <span style="color:#e67e22;">●</span> Turnier
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="font-size:12px; user-select:all;"><?php
                                echo $r['type'] === 'league'
                                    ? '[bbb_table liga_id="' . esc_attr( $r['liga_id'] ) . '"]'
                                    : '[bbb_bracket liga_id="' . esc_attr( $r['liga_id'] ) . '"]';
                            ?></code>
                        </td>
                        <td>
                            <code style="font-size:12px; user-select:all;"><?php
                                echo $r['type'] === 'league'
                                    ? '[gdlr_core_bbb_table liga-id="' . esc_attr( $r['liga_id'] ) . '"]'
                                    : '[gdlr_core_bbb_bracket liga-id="' . esc_attr( $r['liga_id'] ) . '"]';
                            ?></code>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;">
                <strong>Hinweis:</strong> Im Goodlayers Page Builder sind die Elemente unter der Kategorie <em>Sport</em> verfügbar
                ("BBB Liga-Tabelle" und "BBB Turnier-Bracket").
            </p>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: CLEANUP
    // ─────────────────────────────────────────

    private function render_cleanup_tab(): void {
        // Eigene Teams laden
        $own_team_ids = get_option( 'bbb_sync_own_teams', [] );
        $teams = [];
        foreach ( $own_team_ids as $pid ) {
            global $wpdb;
            $wp_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'sp_team' AND pm.meta_key = '_bbb_team_permanent_id' AND pm.meta_value = %d
                 LIMIT 1", $pid
            ) );
            if ( $wp_id ) {
                $teams[] = [ 'wp_id' => (int) $wp_id, 'pid' => $pid, 'name' => get_the_title( $wp_id ) ];
            }
        }

        // Seasons laden
        $seasons = get_terms([ 'taxonomy' => 'sp_season', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC' ]);
        if ( is_wp_error( $seasons ) ) $seasons = [];

        // Zähler für Übersicht
        global $wpdb;
        $total_players = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_person_id'
             WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'"
        );
        $total_events = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_match_id'
             WHERE p.post_type = 'sp_event'"
        );
        $total_teams_synced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_team_permanent_id'
             WHERE p.post_type = 'sp_team'"
        );
        ?>

        <!-- Übersicht -->
        <div class="card" style="max-width:700px; padding:15px;">
            <h2>Synchronisierte Daten</h2>
            <table class="widefat striped" style="max-width:400px;">
                <tr><td>Spieler (BBB-Import)</td><td><strong><?php echo $total_players; ?></strong></td></tr>
                <tr><td>Events (BBB-Import)</td><td><strong><?php echo $total_events; ?></strong></td></tr>
                <tr><td>Teams (BBB-Import)</td><td><strong><?php echo $total_teams_synced; ?></strong></td></tr>
            </table>
        </div>

        <!-- Spieler nach Team/Saison löschen -->
        <div class="card" style="max-width:700px; padding:15px; margin-top:15px;">
            <h2>🧹 Spieler löschen (nach Team + Saison)</h2>
            <p class="description">
                Löscht alle BBB-importierten Spieler, die dem gewählten Team und der Saison zugeordnet sind.
                Boxscore-Flags werden automatisch zurückgesetzt, sodass ein erneuter Sync die Spieler frisch importiert.
            </p>
            <form method="post" onsubmit="return confirm('Wirklich alle Spieler dieses Teams/Saison löschen?');">
                <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cleanup_team">Team</label></th>
                        <td>
                            <select name="cleanup_team" id="cleanup_team" style="min-width:300px;">
                                <option value="all">Alle eigenen Teams</option>
                                <?php foreach ( $teams as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t['wp_id'] ); ?>">
                                        <?php echo esc_html( $t['name'] ); ?> (PID <?php echo $t['pid']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cleanup_season">Saison</label></th>
                        <td>
                            <select name="cleanup_season" id="cleanup_season" style="min-width:300px;">
                                <option value="all">Alle Saisons</option>
                                <?php foreach ( $seasons as $s ) : ?>
                                    <option value="<?php echo esc_attr( $s->term_id ); ?>">
                                        <?php echo esc_html( $s->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Spieler löschen', 'delete', 'bbb_cleanup_players', false ); ?>
            </form>
        </div>

        <!-- Boxscore-Flags -->
        <div class="card" style="max-width:700px; padding:15px; margin-top:15px;">
            <h2>🔄 Boxscore-Flags zurücksetzen</h2>
            <p class="description">
                Der Sync merkt sich pro Spiel, ob der Boxscore (Spielerstatistiken) bereits importiert wurde.
                Das verhindert, dass bei jedem Sync-Lauf alle Boxscores erneut von der API abgerufen werden.
            </p>
            <p class="description" style="margin-top:8px;">
                <strong>Wann zurücksetzen?</strong>
            </p>
            <ul style="margin:4px 0 12px 20px; font-size:13px; color:#555;">
                <li>Spieler-Statistiken fehlen oder sind unvollständig (z.B. nach einem abgebrochenen Sync)</li>
                <li>Du hast das Statistik-Mapping geändert und möchtest, dass alle Boxscores mit der neuen Zuordnung neu importiert werden</li>
                <li>Spieler wurden manuell gelöscht und sollen beim nächsten Sync erneut angelegt werden</li>
                <li>Die BBB-API hat nachträglich korrigierte Daten für bereits synchronisierte Spiele</li>
            </ul>
            <p class="description" style="margin-bottom:12px;">
                Nach dem Zurücksetzen werden beim nächsten Sync <strong>alle Boxscores erneut</strong> von der API abgerufen.
                Das dauert entsprechend länger, da mehr API-Calls nötig sind.
            </p>
            <form method="post" onsubmit="return confirm('Boxscore-Flags zurücksetzen? Beim nächsten Sync werden alle Boxscores erneut importiert.');">
                <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                <?php submit_button( 'Boxscore-Flags zurücksetzen', 'secondary', 'bbb_reset_boxscore_flags', false ); ?>
            </form>
        </div>

        <!-- Result-Keys reparieren -->
        <div class="card" style="max-width:700px; padding:15px; margin-top:15px;">
            <h2>🔧 Ergebnis-Keys reparieren</h2>
            <p class="description">
                Kopiert vorhandene Spielergebnisse in alle unter
                <a href="?page=bbb-sync&tab=settings">Einstellungen → Ergebnis-Felder</a>
                konfigurierten Spalten und setzt <code>sp_main_result</code> korrekt.
            </p>
            <p class="description" style="margin-top:8px;">
                <strong>Wann reparieren?</strong>
            </p>
            <ul style="margin:4px 0 12px 20px; font-size:13px; color:#555;">
                <li>Ergebnisse stehen im Game-Editor, aber in der Übersicht/Teasern wird „N/A“ angezeigt</li>
                <li>SportsPress-Tabellen zeigen 0:0 obwohl Ergebnisse vorhanden sind</li>
                <li>Du hast die Ergebnis-Felder in den Einstellungen geändert (z.B. <code>t</code> → <code>pts</code>)</li>
            </ul>
            <form method="post" onsubmit="return confirm('Ergebnis-Keys in allen Events reparieren?');">
                <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                <?php submit_button( 'Result-Keys reparieren', 'secondary', 'bbb_repair_result_keys', false ); ?>
            </form>
        </div>

        <!-- Full Reset -->
        <div class="card" style="max-width:700px; padding:15px; margin-top:15px; border-left:4px solid #d63638;">
            <h2>⚠️ Vollständiger Reset</h2>
            <p class="description">
                Löscht <strong>ALLE</strong> BBB-synchronisierten Daten: Spieler, Events, Teams, Spielerlisten, Tabellen, Venues.
                SP-Ligen und Saisons (Taxonomien) bleiben erhalten. Danach muss ein kompletter Re-Sync durchgeführt werden.
            </p>
            <form method="post" onsubmit="return confirm('ACHTUNG: ALLE synchronisierten Daten werden unwiderruflich gelöscht!\n\nBist du sicher?');">
                <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                <?php submit_button( 'Alles löschen (Full Reset)', 'delete', 'bbb_cleanup_all', false ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Repariert Result-Keys in allen sp_event Posts.
     */
    private function handle_repair_result_keys(): void {
        $engine = new BBB_Sync_Engine();

        $configured_slugs = $engine->get_result_slugs();

        $result_posts = get_posts([
            'post_type'      => 'sp_result',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        if ( empty( $result_posts ) ) {
            set_transient( 'bbb_sync_notice', 'Keine sp_result Posts gefunden. Bitte erst einen Sync durchführen.', 30 );
            set_transient( 'bbb_sync_notice_type', 'error', 30 );
            wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=settings' ) );
            exit;
        }

        $valid_slugs = wp_list_pluck( $result_posts, 'post_name' );
        $target_slugs = ! empty( $configured_slugs ) ? $configured_slugs : [ $result_posts[0]->post_name ];
        $primary_slug = $target_slugs[0];

        global $wpdb;
        $events = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'sp_results'
             WHERE p.post_type = 'sp_event' AND p.post_status IN ('publish','future','draft')"
        );

        $fixed = 0;
        $main_fixed = 0;

        foreach ( $events as $event ) {
            $results = maybe_unserialize( $event->meta_value );
            if ( ! is_array( $results ) ) continue;

            $new_results = [];
            $changed = false;

            foreach ( $results as $team_id => $data ) {
                if ( ! is_array( $data ) ) {
                    $new_results[ $team_id ] = $data;
                    continue;
                }

                $score = null;
                $outcome = $data['outcome'] ?? [];
                foreach ( $data as $k => $v ) {
                    if ( $k === 'outcome' ) continue;
                    if ( $v !== '' && $v !== null ) {
                        $score = $v;
                        break;
                    }
                }

                $new_data = [ 'outcome' => $outcome ];
                foreach ( $target_slugs as $slug ) {
                    $old_val = $data[ $slug ] ?? null;
                    if ( $score !== null && ( $old_val === null || $old_val === '' ) ) {
                        $new_data[ $slug ] = $score;
                        $changed = true;
                    } else {
                        $new_data[ $slug ] = $old_val ?? '';
                    }
                }

                foreach ( $data as $k => $v ) {
                    if ( $k === 'outcome' ) continue;
                    if ( ! in_array( $k, $valid_slugs, true ) && ! isset( $new_data[ $k ] ) ) {
                        $changed = true;
                    } elseif ( in_array( $k, $valid_slugs, true ) && ! isset( $new_data[ $k ] ) ) {
                        $new_data[ $k ] = $v;
                    }
                }

                $new_results[ $team_id ] = $new_data;
            }

            if ( $changed ) {
                update_post_meta( $event->ID, 'sp_results', $new_results );
                $fixed++;
            }

            $current_main = get_post_meta( $event->ID, 'sp_main_result', true );
            if ( $current_main !== $primary_slug ) {
                update_post_meta( $event->ID, 'sp_main_result', $primary_slug );
                $main_fixed++;
            }
        }

        $tables = get_posts([
            'post_type'      => 'sp_table',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ]);
        $tables_fixed = 0;
        foreach ( $tables as $table ) {
            $current = get_post_meta( $table->ID, 'sp_main_result', true );
            if ( $current !== $primary_slug ) {
                update_post_meta( $table->ID, 'sp_main_result', $primary_slug );
                $tables_fixed++;
            }
        }

        $slug_list = implode( ', ', $target_slugs );
        $msg = "Result-Key Repair: Ziel-Slugs=[{$slug_list}], Primär='{$primary_slug}'. ";
        $msg .= "{$fixed} Events repariert, {$main_fixed} sp_main_result korrigiert";
        if ( $tables_fixed ) $msg .= ", {$tables_fixed} Tabellen korrigiert";
        $msg .= '.';

        set_transient( 'bbb_sync_notice', $msg, 30 );
        wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=cleanup' ) );
        exit;
    }

    /**
     * Spieler nach Team/Saison löschen.
     */
    private function handle_cleanup_players(): void {
        global $wpdb;

        $team_filter   = sanitize_text_field( $_POST['cleanup_team'] ?? 'all' );
        $season_filter = sanitize_text_field( $_POST['cleanup_season'] ?? 'all' );

        $query_args = [
            'post_type'      => 'sp_player',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => '_bbb_person_id', 'compare' => 'EXISTS' ],
            ],
        ];

        if ( $team_filter !== 'all' ) {
            $query_args['meta_query'][] = [
                'key' => 'sp_team', 'value' => (int) $team_filter,
            ];
        }

        if ( $season_filter !== 'all' ) {
            $query_args['tax_query'] = [
                [ 'taxonomy' => 'sp_season', 'terms' => (int) $season_filter ],
            ];
        }

        $players = get_posts( $query_args );
        $deleted = 0;

        foreach ( $players as $player_id ) {
            wp_delete_post( $player_id, true );
            $deleted++;
        }

        if ( $deleted > 0 ) {
            if ( $team_filter !== 'all' ) {
                $event_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = 'sp_team' AND meta_value = %d",
                    (int) $team_filter
                ) );
                if ( $event_ids ) {
                    $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
                    $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->postmeta}
                         WHERE meta_key = '_bbb_boxscore_synced'
                         AND post_id IN ($placeholders)",
                        ...$event_ids
                    ) );
                }
            } else {
                $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_bbb_boxscore_synced'" );
            }

            if ( $team_filter !== 'all' ) {
                $lists = $wpdb->get_col( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = '_bbb_team_wp_id' AND meta_value = %d",
                    (int) $team_filter
                ) );
                foreach ( $lists as $list_id ) {
                    delete_post_meta( (int) $list_id, 'sp_player' );
                }
            }
        }

        $label = [];
        if ( $team_filter !== 'all' ) $label[] = get_the_title( (int) $team_filter );
        if ( $season_filter !== 'all' ) {
            $term = get_term( (int) $season_filter, 'sp_season' );
            if ( $term && ! is_wp_error( $term ) ) $label[] = $term->name;
        }
        $context = $label ? ' (' . implode( ', ', $label ) . ')' : '';

        set_transient( 'bbb_sync_notice', "{$deleted} Spieler gelöscht{$context}. Boxscore-Flags zurückgesetzt.", 30 );
        wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=cleanup' ) );
    }

    /**
     * Vollständiger Reset: Alle BBB-synchronisierten Daten löschen.
     */
    private function handle_cleanup_all(): void {
        global $wpdb;
        $stats = [];

        $players = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_person_id'
             WHERE p.post_type = 'sp_player'"
        );
        foreach ( $players as $id ) wp_delete_post( (int) $id, true );
        $stats[] = count( $players ) . ' Spieler';

        $events = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_match_id'
             WHERE p.post_type = 'sp_event'"
        );
        foreach ( $events as $id ) wp_delete_post( (int) $id, true );
        $stats[] = count( $events ) . ' Events';

        $teams = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_team_permanent_id'
             WHERE p.post_type = 'sp_team'"
        );
        foreach ( $teams as $id ) wp_delete_post( (int) $id, true );
        $stats[] = count( $teams ) . ' Teams';

        $lists = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_team_wp_id'
             WHERE p.post_type = 'sp_list'"
        );
        foreach ( $lists as $id ) wp_delete_post( (int) $id, true );
        $stats[] = count( $lists ) . ' Spielerlisten';

        $tables = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_liga_id'
             WHERE p.post_type = 'sp_table'"
        );
        foreach ( $tables as $id ) wp_delete_post( (int) $id, true );
        $stats[] = count( $tables ) . ' Tabellen';

        $venues = get_terms([ 'taxonomy' => 'sp_venue', 'hide_empty' => false,
            'meta_key' => '_bbb_spielfeld_id', 'meta_compare' => 'EXISTS' ]);
        if ( ! is_wp_error( $venues ) ) {
            foreach ( $venues as $v ) {
                delete_option( "taxonomy_{$v->term_id}" );
                wp_delete_term( $v->term_id, 'sp_venue' );
            }
            $stats[] = count( $venues ) . ' Venues';
        }

        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_bbb_boxscore_synced'" );

        delete_option( 'bbb_sync_logs' );
        delete_option( 'bbb_sync_last_run' );
        delete_option( 'bbb_sync_last_stats' );
        delete_transient( 'bbb_sync_progress' );

        wp_cache_flush();

        $msg = 'Full Reset: ' . implode( ', ', $stats ) . ' gelöscht.';
        set_transient( 'bbb_sync_notice', $msg, 30 );

        $logs = [[ 'time' => current_time( 'mysql' ), 'level' => 'warning', 'message' => $msg ]];
        update_option( 'bbb_sync_logs', $logs, false );

        wp_safe_redirect( admin_url( 'admin.php?page=bbb-sync&tab=cleanup' ) );
    }

    // ─────────────────────────────────────────
    // TAB: LOGS
    // ─────────────────────────────────────────

    private function render_logs_tab(): void {
        $logs = array_reverse( get_option( 'bbb_sync_logs', [] ) );
        $filter = $_GET['log_level'] ?? 'all';

        $counts = [ 'all' => count($logs), 'error' => 0, 'warning' => 0, 'info' => 0 ];
        foreach ( $logs as $l ) {
            $level = $l['level'] ?? 'info';
            if ( isset( $counts[$level] ) ) $counts[$level]++;
        }

        if ( $filter !== 'all' ) {
            $logs = array_filter( $logs, fn($l) => ($l['level'] ?? 'info') === $filter );
        }
        ?>
        <style>
            .bbb-log-entry { border-bottom:1px solid #eee; padding:3px 6px; font-family:monospace; font-size:12px; line-height:1.7; }
            .bbb-log-entry:hover { background:#f0f0f0; }
            .bbb-log-error { color:#dc3232; background:#fef1f1; font-weight:500; }
            .bbb-log-error:hover { background:#fde3e3; }
            .bbb-log-warning { color:#996800; background:#fef8ee; }
            .bbb-log-warning:hover { background:#fdf0d5; }
            .bbb-log-info { color:#444; }
            .bbb-log-time { color:#999; font-size:11px; }
            .bbb-log-filters { margin-bottom:12px; display:flex; gap:6px; align-items:center; }
            .bbb-log-filters a { text-decoration:none; padding:4px 12px; border-radius:3px; font-size:13px; }
            .bbb-log-filters a.active { background:#2271b1; color:#fff; }
            .bbb-log-filters a:not(.active) { background:#f0f0f0; color:#444; }
            .bbb-log-filters a:not(.active):hover { background:#e0e0e0; }
            .bbb-log-badge { font-size:11px; margin-left:2px; }
        </style>

        <div class="card" style="max-width:900px; padding:15px;">
            <h2>Logs</h2>

            <div class="bbb-log-filters">
                <?php
                $base_url = admin_url( 'admin.php?page=bbb-sync&tab=logs' );
                foreach ( [
                    'all'     => ['📋 Alle', $counts['all']],
                    'error'   => ['❌ Fehler', $counts['error']],
                    'warning' => ['⚠️ Warnungen', $counts['warning']],
                    'info'    => ['ℹ️ Info', $counts['info']],
                ] as $level => [$label, $count] ) :
                    $active = ($filter === $level) ? ' active' : '';
                    $url = ($level === 'all') ? $base_url : $base_url . '&log_level=' . $level;
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="<?php echo $active; ?>">
                        <?php echo $label; ?>
                        <span class="bbb-log-badge">(<?php echo $count; ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <form method="post">
                    <?php wp_nonce_field( 'bbb_sync_action' ); ?>
                    <?php submit_button( 'Logs löschen', 'secondary', 'bbb_clear_logs', false ); ?>
                </form>
            </div>

            <?php if ( empty($logs) ) : ?>
                <p>Keine <?php echo $filter !== 'all' ? esc_html($filter) . '-' : ''; ?>Logs.</p>
            <?php else : ?>
                <div style="max-height:600px; overflow-y:auto; background:#fafafa; border:1px solid #ddd; border-radius:3px;">
                    <?php foreach ( $logs as $l ) :
                        $level = $l['level'] ?? 'info';
                        $icon  = match($level) { 'error' => '❌', 'warning' => '⚠️', default => '' };
                    ?>
                        <div class="bbb-log-entry bbb-log-<?php echo esc_attr($level); ?>">
                            <?php echo $icon; ?>
                            <span class="bbb-log-time">[<?php echo esc_html($l['time'] ?? ''); ?>]</span>
                            <?php echo esc_html($l['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: SUPPORT
    // ─────────────────────────────────────────

    private function render_support_tab(): void {
        ?>
        <div class="card" style="max-width:700px; padding:20px;">
            <h2>🏀 BBB SportsPress Sync unterstützen</h2>
            <p>
                Dieses Plugin wird ehrenamtlich von <strong>Oliver-Marcus Eder</strong> entwickelt und gepflegt.
                Es ist kostenlos und Open Source – wenn es dir weiterhilft, freue ich mich über einen kleinen Beitrag:
            </p>

            <div style="display:flex; gap:16px; margin:20px 0;">
                <a href="https://buymeacoffee.com/olivermarcus.eder" target="_blank"
                   style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#FFDD00; color:#000; border-radius:8px; text-decoration:none; font-weight:600; font-size:15px;">
                    ☕ Buy Me a Coffee
                </a>
                <a href="https://ko-fi.com/olieder" target="_blank"
                   style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#13C3FF; color:#fff; border-radius:8px; text-decoration:none; font-weight:600; font-size:15px;">
                    🎁 Ko-fi
                </a>
            </div>

            <hr style="margin:20px 0;">

            <h3>🐛 Fehler melden &amp; Feature-Wünsche</h3>
            <p>
                Hast du einen Bug gefunden oder eine Idee für ein neues Feature?
                Erstelle ein Issue auf GitHub:
            </p>
            <p>
                <a href="https://github.com/OliEder/bbb-sportspress-sync/issues" target="_blank" class="button">
                    GitHub Issues → bbb-sportspress-sync
                </a>
            </p>

            <hr style="margin:20px 0;">

            <h3>📦 Weitere Plugins</h3>
            <table class="widefat striped" style="max-width:500px;">
                <tr>
                    <td><strong>BBB Live Tables</strong></td>
                    <td>Liga-Tabellen &amp; Turnier-Brackets direkt aus der BBB-API</td>
                    <td><a href="https://github.com/OliEder/bbb-live-tables" target="_blank">GitHub</a></td>
                </tr>
            </table>

            <hr style="margin:20px 0;">

            <p style="color:#666; font-size:13px;">
                Entwickelt mit ❤️ in Bayern · <a href="https://github.com/OliEder" target="_blank">github.com/OliEder</a>
            </p>
        </div>
        <?php
    }

    private function format_stats_message( array $s ): string {
        $p = [];
        $p[] = "Teams {$s['teams_created']}/{$s['teams_updated']}";
        $p[] = "Events {$s['events_created']}/{$s['events_updated']}/{$s['events_deleted']}";
        $p[] = "Venues {$s['venues_created']}/{$s['venues_updated']}";
        $tc = ($s['tables_created'] ?? 0) + ($s['tables_updated'] ?? 0);
        if ($tc > 0) $p[] = "Tabellen {$s['tables_created']}/{$s['tables_updated']}";
        if (($s['players_created']??0)+($s['players_updated']??0)>0) $p[] = "Spieler {$s['players_created']}/{$s['players_updated']}";
        $p[] = "{$s['api_calls']} API-Calls";
        if (($s['errors']??0)>0) $p[] = "{$s['errors']} Fehler";
        return 'Sync fertig: ' . implode(', ', $p);
    }
}
