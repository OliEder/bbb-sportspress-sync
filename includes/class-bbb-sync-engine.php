<?php
/**
 * BBB Sync Engine v3.5.3 – Deduplizierung, AK/Geschlecht, Venue-Fix, Liga-Spielplan
 *
 * v3.5.3 FEATURES:
 *   9. LIGA-SPIELPLAN: Sync ALLER Spiele jeder Liga (nicht nur eigene Beteiligung).
 *      Ermöglicht korrekte Tabellen-Berechnung in SportsPress.
 *      Nutzt /rest/competition/spielplan/id/{ligaId} Endpoint.
 *
 * v3.5.2 FIXES:
 *   6. TEAMNAME-SUFFIX: Alle Teams (nicht nur eigene) bekommen AK/Geschlecht-Suffix,
 *      außer Senioren. Format: "Teamname (U12 männlich)" statt "Teamname U12 (m)".
 *   7. VENUE-ADRESSE FIX: SportsPress speichert Venue-Meta als Option "taxonomy_{term_id}",
 *      NICHT als term_meta. Umgestellt auf set_venue_meta() Helper.
 *   8. VENUE-GEOCODING: Automatische Lat/Lng via Nominatim (OpenStreetMap, kostenlos).
 *      Strukturierte Query (strasse/plz/ort) mit Fallback auf PLZ/Ort.
 *
 * v3.5.1 FIXES:
 *   1. TEAM-DEDUPLIZIERUNG: Pre-Sync Cleanup entfernt Duplikate (behält ältesten Post)
 *   2. AK/GESCHLECHT: ligaData enthält IMMER akName + geschlecht in allen Endpoints
 *      (team-matches, boxscore, club/actualmatches). Direkter Zugriff, kein Parsing nötig.
 *   3. VENUE DUAL-FORMAT: matchInfo hat 2 Formate:
 *      - Mini-Liga: flaches data.ort (String, ohne Adresse)
 *      - Höhere Ligen: data.matchInfo.spielfeld (Objekt mit strasse/plz/ort)
 *   4. BOXSCORE RE-SYNC: Force-Reset aller Flags
 *   5. VENUE-ADRESSE: Auch für bestehende Venues ohne Adresse nachholen
 *
 * @see docs/SYNC-KONZEPT.md
 */

defined( 'ABSPATH' ) || exit;

class BBB_Sync_Engine {

    private BBB_Api_Client $api;
    private BBB_Logo_Handler $logo_handler;
    private BBB_Player_Sync $player_sync;

    /** @var int[] Liga-IDs die während des Team-Sync entdeckt wurden */
    private array $discovered_liga_ids = [];

    /** @var string|null Gecachter Slug des primären sp_result Posts */
    private static ?string $main_result_slug = null;

    private array $stats = [
        'teams_created'      => 0,
        'teams_updated'      => 0,
        'teams_deduped'      => 0,
        'events_created'     => 0,
        'events_updated'     => 0,
        'events_deleted'     => 0,
        'events_skipped'     => 0,
        'venues_created'     => 0,
        'venues_updated'     => 0,
        'players_created'    => 0,
        'players_updated'    => 0,
        'tables_created'     => 0,
        'tables_updated'     => 0,
        'logos_fetched'      => 0,
        'leagues_found'      => 0,
        'liga_matches_synced' => 0,
        'api_calls'          => 0,
        'errors'             => 0,
    ];

    public function __construct() {
        $this->api          = new BBB_Api_Client();
        $this->logo_handler = new BBB_Logo_Handler( $this->api );
        $this->player_sync  = new BBB_Player_Sync( $this->api );
    }

    // ═════════════════════════════════════════
    // SYNC USER (Autor für synchronisierte Inhalte)
    // ═════════════════════════════════════════

    private const SYNC_USER_LOGIN = 'bbb-sync';

    /**
     * Erstellt/findet den dedizierten Sync-Benutzer.
     * Alle synchronisierten Inhalte (Teams, Events, Spieler) werden unter
     * diesem Benutzer veröffentlicht statt unter dem Admin.
     *
     * Rolle: 'editor' (kann Posts veröffentlichen, aber keine Plugins/Settings ändern)
     */
    private function ensure_sync_user(): void {
        $user = get_user_by( 'login', self::SYNC_USER_LOGIN );
        if ( $user ) return;

        $user_id = wp_insert_user([
            'user_login'   => self::SYNC_USER_LOGIN,
            'user_pass'    => wp_generate_password( 32, true, true ),
            'user_email'   => 'sync@basketball-bund.net', // Nicht-existierende Adresse, nur Pflichtfeld
            'display_name' => 'basketball-bund.net',
            'first_name'   => 'BBB',
            'last_name'    => 'Daten-Sync',
            'description'  => 'Automatischer Import von Ligadaten aus basketball-bund.net',
            'role'         => 'editor',
        ]);

        if ( is_wp_error( $user_id ) ) {
            $this->log( 'Sync-User Fehler: ' . $user_id->get_error_message(), 'error' );
            return;
        }

        $this->log( "Sync-Benutzer 'basketball-bund.net' erstellt (ID: {$user_id})" );
    }

    /**
     * ID des Sync-Benutzers holen. Fallback: Aktueller Admin.
     */
    private function get_sync_user_id(): int {
        $user = get_user_by( 'login', self::SYNC_USER_LOGIN );
        return $user ? $user->ID : get_current_user_id();
    }

    // ═════════════════════════════════════════
    // SPORTSPRESS SETUP
    // ═════════════════════════════════════════

    public function ensure_sportspress_setup(): void {
        // Sync-Benutzer sicherstellen (Autor für alle synchronisierten Inhalte)
        $this->ensure_sync_user();
        $this->player_sync->set_sync_user_id( $this->get_sync_user_id() );

        // Result column: Points – existierenden suchen oder "pts" erstellen
        $main_slug = $this->get_main_result_slug();
        if ( ! $main_slug ) {
            $result_id = wp_insert_post([
                'post_type'   => 'sp_result',
                'post_title'  => 'Punkte',
                'post_name'   => 'pts',
                'post_status' => 'publish',
                'menu_order'  => 1,
            ]);
            if ( $result_id && ! is_wp_error( $result_id ) ) {
                update_post_meta( $result_id, 'sp_format', 'number' );
                update_post_meta( $result_id, 'sp_precision', 0 );
                $this->log( 'SportsPress Result-Column "pts" (Punkte) erstellt' );
            }
            self::$main_result_slug = 'pts';
        }

        $outcomes = [ 'win' => 'Sieg', 'loss' => 'Niederlage', 'draw' => 'Unentschieden' ];
        foreach ( $outcomes as $slug => $label ) {
            if ( ! get_page_by_path( $slug, OBJECT, 'sp_outcome' ) ) {
                wp_insert_post([
                    'post_type' => 'sp_outcome', 'post_title' => $label,
                    'post_name' => $slug, 'post_status' => 'publish',
                ]);
            }
        }

        // Basketball Performance-Typen
        $performance_types = [
            'pts' => ['PTS',1], 'ast' => ['AST',2], 'stl' => ['STL',3], 'blk' => ['BLK',4],
            'fgm' => ['FGM',5], 'fga' => ['FGA',6], '3pm' => ['3PM',7], '3pa' => ['3PA',8],
            'ftm' => ['FTM',9], 'fta' => ['FTA',10], 'off' => ['OFF',11], 'def' => ['DEF',12],
            'reb' => ['REB',13], 'to' => ['TO',14], 'pf' => ['PF',15], 'eff' => ['EFF',16],
            'min' => ['MIN',17],
        ];

        $created_perf = 0;
        foreach ( $performance_types as $slug => [ $label, $order ] ) {
            if ( ! get_page_by_path( $slug, OBJECT, 'sp_performance' ) ) {
                $pid = wp_insert_post([
                    'post_type' => 'sp_performance', 'post_title' => $label,
                    'post_name' => $slug, 'post_status' => 'publish', 'menu_order' => $order,
                ]);
                if ( $pid && ! is_wp_error( $pid ) ) {
                    update_post_meta( $pid, 'sp_format', 'number' );
                    update_post_meta( $pid, 'sp_precision', 0 );
                    $created_perf++;
                }
            }
        }
        if ( $created_perf > 0 ) {
            $this->log( "{$created_perf} Basketball Performance-Typen erstellt" );
        }
    }

    // ═════════════════════════════════════════
    // TEAM DEDUPLICATION (v3.5.0 NEU)
    // ═════════════════════════════════════════

    /**
     * Pre-Sync: Finde und merge duplizierte Teams.
     * Behält den ältesten Post (niedrigste ID), löscht Duplikate.
     * Aktualisiert alle Referenzen (sp_team Meta auf Events + Players).
     */
    private function deduplicate_teams(): void {
        global $wpdb;

        // Alle Teams mit _bbb_team_permanent_id gruppiert laden
        $results = $wpdb->get_results( "
            SELECT p.ID, pm.meta_value AS permanent_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_team_permanent_id'
            WHERE p.post_type = 'sp_team' AND p.post_status IN ('publish','draft')
            ORDER BY pm.meta_value, p.ID ASC
        " );

        if ( empty( $results ) ) return;

        // Gruppiere nach permanent_id
        $groups = [];
        foreach ( $results as $row ) {
            $pid = (int) $row->permanent_id;
            if ( ! $pid ) continue;
            $groups[ $pid ][] = (int) $row->ID;
        }

        $total_removed = 0;

        foreach ( $groups as $permanent_id => $wp_ids ) {
            if ( count( $wp_ids ) <= 1 ) continue;

            // Ersten behalten (ältester = niedrigste ID)
            $keep_id = array_shift( $wp_ids );
            $keep_title = get_the_title( $keep_id );

            foreach ( $wp_ids as $dup_id ) {
                $dup_title = get_the_title( $dup_id );

                // Alle sp_team Referenzen auf Events umhängen
                $events_with_dup = $wpdb->get_col( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = 'sp_team' AND meta_value = %d",
                    $dup_id
                ) );

                foreach ( $events_with_dup as $event_id ) {
                    // Duplikat-Referenz durch Keep-Referenz ersetzen
                    $wpdb->update(
                        $wpdb->postmeta,
                        [ 'meta_value' => $keep_id ],
                        [ 'post_id' => (int) $event_id, 'meta_key' => 'sp_team', 'meta_value' => $dup_id ],
                        [ '%d' ],
                        [ '%d', '%s', '%d' ]
                    );
                }

                // sp_results Meta: Team-IDs in serialisierten Arrays ersetzen
                $events_with_results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = 'sp_results' AND meta_value LIKE %s",
                    '%' . $wpdb->esc_like( (string) $dup_id ) . '%'
                ) );

                foreach ( $events_with_results as $row ) {
                    $data = maybe_unserialize( $row->meta_value );
                    if ( is_array( $data ) && isset( $data[ $dup_id ] ) ) {
                        $data[ $keep_id ] = $data[ $dup_id ];
                        unset( $data[ $dup_id ] );
                        update_post_meta( (int) $row->post_id, 'sp_results', $data );
                    }
                }

                // sp_players Meta: Team-IDs ersetzen
                $events_with_players = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = 'sp_players' AND meta_value LIKE %s",
                    '%' . $wpdb->esc_like( (string) $dup_id ) . '%'
                ) );

                foreach ( $events_with_players as $row ) {
                    $data = maybe_unserialize( $row->meta_value );
                    if ( is_array( $data ) && isset( $data[ $dup_id ] ) ) {
                        $data[ $keep_id ] = $data[ $dup_id ];
                        unset( $data[ $dup_id ] );
                        update_post_meta( (int) $row->post_id, 'sp_players', $data );
                    }
                }

                // Spieler: sp_team + sp_current_team umhängen
                $wpdb->update(
                    $wpdb->postmeta,
                    [ 'meta_value' => $keep_id ],
                    [ 'meta_key' => 'sp_team', 'meta_value' => $dup_id ],
                    [ '%d' ],
                    [ '%s', '%d' ]
                );
                $wpdb->update(
                    $wpdb->postmeta,
                    [ 'meta_value' => $keep_id ],
                    [ 'meta_key' => 'sp_current_team', 'meta_value' => $dup_id ],
                    [ '%d' ],
                    [ '%s', '%d' ]
                );

                // Spielerlisten: sp_team Meta umhängen
                $wpdb->update(
                    $wpdb->postmeta,
                    [ 'meta_value' => $keep_id ],
                    [ 'meta_key' => '_bbb_team_wp_id', 'meta_value' => $dup_id ],
                    [ '%d' ],
                    [ '%s', '%d' ]
                );

                // Taxonomien vom Duplikat auf Keep übertragen
                foreach ( [ 'sp_league', 'sp_season' ] as $tax ) {
                    $terms = wp_get_object_terms( $dup_id, $tax, [ 'fields' => 'ids' ] );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        wp_set_object_terms( $keep_id, $terms, $tax, true );
                    }
                }

                // Duplikat löschen
                wp_delete_post( $dup_id, true );
                $total_removed++;

                $this->log( "Dedup: '{$dup_title}' (#{$dup_id}) → merged in '{$keep_title}' (#{$keep_id}), PID {$permanent_id}" );
            }
        }

        if ( $total_removed > 0 ) {
            $this->stats['teams_deduped'] = $total_removed;
            $this->log( "Team-Deduplizierung: {$total_removed} Duplikate entfernt" );
            // WP Object Cache leeren nach massiven DB-Änderungen
            wp_cache_flush();
        }
    }

    // parse_liganame() entfernt in v3.5.1:
    // ligaData enthält IMMER akName + geschlecht direkt → kein Parsing nötig.

    // ═════════════════════════════════════════
    // PHASE 1: TEAM DISCOVERY
    // ═════════════════════════════════════════

    public function discover_teams(): array {
        $club_id = (int) get_option( 'bbb_sync_club_id', 0 );
        if ( ! $club_id ) {
            $this->log( 'Keine Club-ID konfiguriert.' );
            return [ 'error' => 'Keine Club-ID konfiguriert.' ];
        }

        $range_days = (int) get_option( 'bbb_sync_range_days', 365 );
        $result = $this->api->get_club_matches( $club_id, $range_days );
        $this->stats['api_calls']++;

        if ( is_wp_error( $result ) ) {
            $this->log( 'Discovery API-Fehler: ' . $result->get_error_message(), 'error' );
            return [ 'error' => $result->get_error_message() ];
        }

        $matches    = $result['matches'] ?? [];
        $own_teams  = [];
        $leagues    = [];

        foreach ( $matches as $match ) {
            $liga_data = $match['ligaData'] ?? [];
            $liga_id   = (int) ( $liga_data['ligaId'] ?? 0 );
            if ( $liga_id && ! isset( $leagues[ $liga_id ] ) ) {
                $leagues[ $liga_id ] = $liga_data;
            }

            // v3.5.1: akName + geschlecht sind IMMER in ligaData vorhanden
            $ak_name    = $liga_data['akName'] ?? '';
            $geschlecht = $liga_data['geschlecht'] ?? '';
            $liga_name  = $liga_data['liganame'] ?? '';

            foreach ( [ 'homeTeam', 'guestTeam' ] as $side ) {
                $team = $match[ $side ] ?? [];
                if ( (int) ( $team['clubId'] ?? 0 ) !== $club_id ) continue;

                $pid = (int) ( $team['teamPermanentId'] ?? 0 );
                if ( ! $pid ) continue;

                if ( ! isset( $own_teams[ $pid ] ) ) {
                    $own_teams[ $pid ] = [
                        'teamPermanentId' => $pid,
                        'seasonTeamId'    => (int) ( $team['seasonTeamId'] ?? 0 ),
                        'teamname'        => $team['teamname'] ?? '',
                        'teamnameSmall'   => $team['teamnameSmall'] ?? '',
                        'clubId'          => $club_id,
                        'akName'          => $ak_name,
                        'geschlecht'      => $geschlecht,
                        'liganame'        => $liga_name,
                        'ligen'           => [],
                    ];
                }

                if ( $liga_name && ! in_array( $liga_name, $own_teams[ $pid ]['ligen'], true ) ) {
                    $own_teams[ $pid ]['ligen'][] = $liga_name;
                }
                if ( empty( $own_teams[ $pid ]['akName'] ) && $ak_name ) {
                    $own_teams[ $pid ]['akName'] = $ak_name;
                }
                if ( empty( $own_teams[ $pid ]['geschlecht'] ) && $geschlecht ) {
                    $own_teams[ $pid ]['geschlecht'] = $geschlecht;
                }
            }
        }

        $this->log( sprintf(
            'Discovery: %d eigene Teams, %d Ligen, %d Matches',
            count( $own_teams ), count( $leagues ), count( $matches )
        ));

        return [
            'club'        => $result['club'] ?? [],
            'own_teams'   => $own_teams,
            'all_leagues' => $leagues,
            'match_count' => count( $matches ),
        ];
    }

    public function register_own_teams( array $team_permanent_ids ): void {
        update_option( 'bbb_sync_own_teams', array_map( 'intval', $team_permanent_ids ) );
        $this->log( sprintf( '%d eigene Teams registriert: %s',
            count( $team_permanent_ids ),
            implode( ', ', $team_permanent_ids )
        ));
    }

    // ═════════════════════════════════════════
    // PHASE 2: REGULAR SYNC
    // ═════════════════════════════════════════

    public function sync_all(): array {
        $this->stats = array_fill_keys( array_keys( $this->stats ), 0 );
        $this->discovered_liga_ids = [];

        $own_team_ids = get_option( 'bbb_sync_own_teams', [] );
        if ( empty( $own_team_ids ) ) {
            $this->log( 'Keine eigenen Teams registriert.' );
            return $this->stats;
        }

        $this->ensure_sportspress_setup();

        // Version-Upgrades: Flags resetten → erzwingt Re-Sync
        $last_version = get_option( 'bbb_sync_engine_version', '0' );
        if ( version_compare( $last_version, '3.5.0', '<' ) ) {
            $this->reset_boxscore_flags();
            $this->reset_venue_addresses();
            update_option( 'bbb_sync_engine_version', '3.5.0' );
            $this->log( 'v3.5.0 Upgrade: Boxscore-Flags + Venue-Adressen resettet' );
        }
        if ( version_compare( $last_version, '3.5.2', '<' ) ) {
            $this->migrate_venue_meta_to_options();
            update_option( 'bbb_sync_engine_version', '3.5.2' );
            $this->log( 'v3.5.2 Upgrade: Venue-Adressen von term_meta nach taxonomy_option migriert' );
        }
        if ( version_compare( $last_version, '3.5.3', '<' ) ) {
            $this->migrate_post_authors_to_sync_user();
            update_option( 'bbb_sync_engine_version', '3.5.3' );
            $this->log( 'v3.5.3 Upgrade: Post-Autoren auf Sync-User migriert' );
        }

        // v3.5.0: Team-Deduplizierung VOR dem eigentlichen Sync
        $this->update_progress([
            'running' => true, 'phase' => 'dedup',
            'current_label' => 'Team-Deduplizierung...', 'started_at' => time(),
        ]);
        $this->deduplicate_teams();

        $total_teams = count( $own_team_ids );
        $this->log( sprintf( 'Starte Sync für %d eigene Teams...', $total_teams ) );

        $this->update_progress([
            'running' => true, 'phase' => 'teams', 'current_team' => 0,
            'total_teams' => $total_teams, 'current_label' => 'Initialisiere...',
            'matches_total' => 0, 'matches_done' => 0,
        ]);

        foreach ( $own_team_ids as $index => $permanent_id ) {
            $this->update_progress([
                'running' => true, 'phase' => 'loading', 'current_team' => $index + 1,
                'total_teams' => $total_teams,
                'current_label' => "Lade Matches für Team " . ( $index + 1 ) . "/{$total_teams}...",
            ]);

            $this->sync_team_matches( (int) $permanent_id, $index, $total_teams );
            $this->api->throttle();
        }

        // Phase 3: Liga-Spielpläne – ALLE Spiele jeder Liga synken (für korrekte Tabellen)
        if ( ! empty( $this->discovered_liga_ids ) ) {
            $total_ligas = count( $this->discovered_liga_ids );
            $this->log( sprintf( 'Starte Liga-Spielplan-Sync für %d Ligen...', $total_ligas ) );

            foreach ( $this->discovered_liga_ids as $liga_index => $liga_id ) {
                $this->update_progress([
                    'running' => true, 'phase' => 'liga_spielplan',
                    'current_label' => sprintf( 'Liga-Spielplan %d/%d (Liga #%d)...',
                        $liga_index + 1, $total_ligas, $liga_id ),
                ]);

                $this->sync_liga_spielplan( (int) $liga_id );
                $this->api->throttle();
            }
        }

        $this->log( sprintf(
            'Sync fertig: %d Ligen (%d Liga-Matches), Teams %d/%d/%d (neu/akt./dedup), Events %d/%d/%d (neu/akt./gel.), Venues %d/%d, Tabellen %d/%d, Players %d/%d, Logos %d, %d API-Calls, %d Fehler',
            $this->stats['leagues_found'], $this->stats['liga_matches_synced'],
            $this->stats['teams_created'], $this->stats['teams_updated'], $this->stats['teams_deduped'],
            $this->stats['events_created'], $this->stats['events_updated'], $this->stats['events_deleted'],
            $this->stats['venues_created'], $this->stats['venues_updated'],
            $this->stats['tables_created'], $this->stats['tables_updated'],
            $this->stats['players_created'], $this->stats['players_updated'],
            $this->stats['logos_fetched'],
            $this->stats['api_calls'],
            $this->stats['errors']
        ));

        update_option( 'bbb_sync_last_run', current_time( 'mysql' ) );
        update_option( 'bbb_sync_last_stats', $this->stats );

        $this->update_progress([
            'running' => false, 'phase' => 'done',
            'finished_at' => time(), 'stats' => $this->stats,
        ]);

        return $this->stats;
    }

    private function sync_team_matches( int $team_permanent_id, int $team_index = 0, int $total_teams = 1 ): int {
        $result = $this->api->get_team_matches( $team_permanent_id );
        $this->stats['api_calls']++;

        if ( is_wp_error( $result ) ) {
            $this->log( "API-Fehler für Team {$team_permanent_id}: " . $result->get_error_message(), 'error' );
            $this->stats['errors']++;
            return 0;
        }

        $team_meta = $result['team'] ?? [];
        $matches   = $result['matches'] ?? [];
        $team_name = $team_meta['teamname'] ?? $team_meta['teamName'] ?? "Team {$team_permanent_id}";

        $this->log( sprintf( '%s: %d Matches geladen', $team_name, count( $matches ) ) );

        if ( empty( $matches ) ) return 0;

        $match_count = count( $matches );

        $leagues = $this->extract_leagues( $matches );
        $this->stats['leagues_found'] += count( $leagues );

        // Liga-IDs sammeln für späteren Spielplan-Sync (alle Spiele der Liga)
        foreach ( array_keys( $leagues ) as $lid ) {
            if ( ! in_array( $lid, $this->discovered_liga_ids, true ) ) {
                $this->discovered_liga_ids[] = $lid;
            }
        }

        $league_term_map = [];
        $season_term_map = [];

        foreach ( $leagues as $liga_id => $liga_data ) {
            $league_term_map[ $liga_id ] = $this->ensure_sp_league( $liga_data, $liga_id );
            $season_key = $liga_data['seasonName'] ?? '';
            if ( $season_key && ! isset( $season_term_map[ $season_key ] ) ) {
                $season_term_map[ $season_key ] = $this->ensure_sp_season( $liga_data );
            }
        }

        $club_id = (int) get_option( 'bbb_sync_club_id', 0 );
        $team_wp_map = $this->sync_teams_from_matches( $matches, $club_id, $team_meta, $league_term_map, $season_term_map );

        $api_match_ids = [];

        foreach ( $matches as $match_index => $match ) {
            $match_id = (int) ( $match['matchId'] ?? 0 );
            if ( $match_id ) $api_match_ids[] = $match_id;

            $home_name = $match['homeTeam']['teamname'] ?? '?';
            $away_name = $match['guestTeam']['teamname'] ?? '?';
            $this->update_progress([
                'running' => true, 'phase' => 'syncing',
                'current_team' => $team_index + 1, 'total_teams' => $total_teams,
                'current_label' => sprintf( 'Team %d/%d "%s": Match %d/%d (%s vs %s)',
                    $team_index + 1, $total_teams, $team_name,
                    $match_index + 1, $match_count, $home_name, $away_name ),
                'matches_done' => $match_index + 1, 'matches_total' => $match_count,
            ]);

            $this->sync_event( $match, $team_wp_map, $league_term_map, $season_term_map );
        }

        $this->reconcile_events( $api_match_ids, $team_permanent_id, $team_wp_map );
        return $match_count;
    }

    // ═════════════════════════════════════════
    // PROGRESS TRACKING
    // ═════════════════════════════════════════

    private function update_progress( array $progress ): void {
        $progress['last_update'] = time();
        $existing = get_transient( 'bbb_sync_progress' ) ?: [];
        if ( isset( $existing['started_at'] ) && ! isset( $progress['started_at'] ) ) {
            $progress['started_at'] = $existing['started_at'];
        }
        set_transient( 'bbb_sync_progress', $progress, 600 );
    }

    public static function get_progress(): array {
        return get_transient( 'bbb_sync_progress' ) ?: [ 'running' => false, 'phase' => 'idle' ];
    }

    // ═════════════════════════════════════════
    // TEAM SYNC
    // ═════════════════════════════════════════

    private function sync_teams_from_matches(
        array $matches, int $club_id, array $team_meta,
        array $league_term_map, array $season_term_map
    ): array {
        $teams = [];

        // v3.5.0: Erstes ligaData komplett loggen für Diagnose
        static $liga_data_logged = false;

        foreach ( $matches as $match ) {
            $liga_data   = $match['ligaData'] ?? [];
            $liga_id     = (int) ( $liga_data['ligaId'] ?? 0 );
            $season_name = $liga_data['seasonName'] ?? '';
            $liga_name   = $liga_data['liganame'] ?? '';

            // v3.5.0: Debug-Log für ligaData Feldnamen (einmalig)
            if ( ! $liga_data_logged && ! empty( $liga_data ) ) {
                $this->log( 'ligaData Keys: [' . implode( ', ', array_keys( $liga_data ) ) . ']' );
                $this->log( 'ligaData Sample: ' . wp_json_encode( $liga_data, JSON_UNESCAPED_UNICODE ) );
                $liga_data_logged = true;
            }

            // v3.5.1: akName + geschlecht direkt aus ligaData (immer vorhanden)
            $ak_name    = $liga_data['akName'] ?? '';
            $geschlecht = $liga_data['geschlecht'] ?? '';

            foreach ( [ 'homeTeam', 'guestTeam' ] as $side ) {
                $team = $match[ $side ] ?? [];
                $permanent_id = (int) ( $team['teamPermanentId'] ?? 0 );
                if ( ! $permanent_id ) continue;

                if ( ! isset( $teams[ $permanent_id ] ) ) {
                    $is_own = ( (int) ( $team['clubId'] ?? 0 ) === $club_id );
                    $teams[ $permanent_id ] = [
                        'data'       => $team,
                        'is_own'     => $is_own,
                        'liga_ids'   => [],
                        'seasons'    => [],
                        'extra_meta' => $is_own ? $team_meta : [],
                        'ak_name'    => '',
                        'geschlecht' => '',
                    ];
                }

                if ( $liga_id && ! in_array( $liga_id, $teams[ $permanent_id ]['liga_ids'], true ) ) {
                    $teams[ $permanent_id ]['liga_ids'][] = $liga_id;
                }
                if ( $season_name && ! in_array( $season_name, $teams[ $permanent_id ]['seasons'], true ) ) {
                    $teams[ $permanent_id ]['seasons'][] = $season_name;
                }
                if ( $ak_name && empty( $teams[ $permanent_id ]['ak_name'] ) ) {
                    $teams[ $permanent_id ]['ak_name'] = $ak_name;
                }
                if ( $geschlecht && empty( $teams[ $permanent_id ]['geschlecht'] ) ) {
                    $teams[ $permanent_id ]['geschlecht'] = $geschlecht;
                }
            }
        }

        // Debug: Eigene Teams mit extrahiertem AK/Geschlecht loggen
        foreach ( $teams as $pid => $ti ) {
            if ( $ti['is_own'] ) {
                $this->log( sprintf(
                    'Team-Meta PID %d "%s": ak_name="%s", geschlecht="%s", ligen=[%s]',
                    $pid, $ti['data']['teamname'] ?? '?',
                    $ti['ak_name'], $ti['geschlecht'],
                    implode( ', ', $ti['liga_ids'] )
                ));
            }
        }

        $wp_map = [];
        foreach ( $teams as $permanent_id => $team_info ) {
            $wp_id = $this->sync_team(
                $team_info['data'], $team_info['is_own'], $team_info['extra_meta'],
                $team_info['liga_ids'], $team_info['seasons'],
                $league_term_map, $season_term_map,
                $team_info['ak_name'], $team_info['geschlecht']
            );
            if ( $wp_id ) {
                $wp_map[ $permanent_id ] = $wp_id;
            }
        }

        return $wp_map;
    }

    private function sync_team(
        array $team_data, bool $is_own, array $extra_meta,
        array $liga_ids, array $seasons,
        array $league_term_map, array $season_term_map,
        string $ak_name = '', string $geschlecht = ''
    ): int|false {
        $permanent_id   = (int) ( $team_data['teamPermanentId'] ?? 0 );
        $season_team_id = (int) ( $team_data['seasonTeamId'] ?? 0 );
        $team_name      = $team_data['teamname'] ?? '';
        $club_id        = (int) ( $team_data['clubId'] ?? 0 );
        $short_name     = $team_data['teamnameSmall'] ?? null;

        if ( ! $permanent_id || ! $team_name ) return false;

        // v3.5.2: Team-Name mit AK + Geschlecht anreichern (alle Teams, außer Senioren)
        $display_name = $team_name;
        if ( $ak_name && mb_strtolower( $ak_name ) !== 'senioren' ) {
            $suffix = $geschlecht ? "{$ak_name} {$geschlecht}" : $ak_name;
            if ( ! str_contains( mb_strtolower( $display_name ), mb_strtolower( $ak_name ) ) ) {
                $display_name = "{$team_name} ({$suffix})";
            }
            $this->log( "Team-Name: '{$team_name}' → '{$display_name}' (AK={$ak_name}, G={$geschlecht})" );
        }

        // v3.5.0: Suche per permanentId (nach Dedup gibt's nur noch 1 Treffer)
        $existing_id = $this->find_sp_team_by_permanent_id( $permanent_id );
        if ( ! $existing_id ) {
            $existing_id = $this->find_sp_team_by_name( $team_name );
            if ( ! $existing_id && $display_name !== $team_name ) {
                $existing_id = $this->find_sp_team_by_name( $display_name );
            }
            if ( $existing_id ) {
                $this->log( "Adoption: Team '{$display_name}' (SP #{$existing_id}) ← permanentId {$permanent_id}" );
            }
        }

        $sync_uid  = $this->get_sync_user_id();
        $post_data = [
            'post_title'  => $display_name,
            'post_type'   => 'sp_team',
            'post_status' => 'publish',
        ];

        $is_update = (bool) $existing_id;

        if ( $is_update ) {
            // ═══ UPDATE: Titel + Content NIE überschreiben ═══
            $wp_id = $existing_id;
            // Nur bei Adoption (noch kein _bbb_team_permanent_id) Titel setzen
            if ( ! get_post_meta( $existing_id, '_bbb_team_permanent_id', true ) ) {
                wp_update_post( [ 'ID' => $existing_id, 'post_title' => $display_name ] );
            }
            $this->stats['teams_updated']++;
        } else {
            // ═══ CREATE: Alle Felder setzen ═══
            $post_data['post_author'] = $sync_uid;
            $wp_id = wp_insert_post( $post_data );
            if ( is_wp_error( $wp_id ) || ! $wp_id ) {
                $this->stats['errors']++;
                return false;
            }
            $this->stats['teams_created']++;
        }

        // BBB-interne Meta (Primary Keys + Zuordnung – immer aktualisieren)
        update_post_meta( $wp_id, '_bbb_team_permanent_id', $permanent_id );
        update_post_meta( $wp_id, '_bbb_season_team_id', $season_team_id );
        update_post_meta( $wp_id, '_bbb_club_id', $club_id );
        update_post_meta( $wp_id, '_bbb_is_own_team', $is_own ? '1' : '0' );

        // SP-Felder: Nur setzen wenn leer (schützt manuelle Änderungen)
        if ( $short_name !== null ) {
            $this->set_meta_if_empty( $wp_id, 'sp_abbreviation', $short_name );
        } elseif ( ! get_post_meta( $wp_id, 'sp_abbreviation', true ) ) {
            update_post_meta( $wp_id, 'sp_abbreviation', mb_strtoupper( mb_substr( $team_name, 0, 3 ) ) );
        }

        // v3.5.2: Short name = original teamname (ohne AK/Geschlecht-Suffix)
        $this->set_meta_if_empty( $wp_id, 'sp_short_name', $team_name );

        if ( $is_own && ! empty( $extra_meta ) ) {
            $this->set_meta_if_not_null( $wp_id, '_bbb_team_akj', $extra_meta['teamAkj'] ?? null );
            $this->set_meta_if_not_null( $wp_id, '_bbb_team_gender', $extra_meta['teamGender'] ?? null );
            $this->set_meta_if_not_null( $wp_id, '_bbb_team_number', $extra_meta['teamNumber'] ?? null );
        }

        if ( $ak_name ) $this->set_meta_if_empty( $wp_id, '_bbb_ak_name', $ak_name );
        if ( $geschlecht ) $this->set_meta_if_empty( $wp_id, '_bbb_geschlecht', $geschlecht );
        $this->set_meta_if_empty( $wp_id, '_bbb_original_teamname', $team_name );

        // sp_url: Nicht setzen – BBB hat keine öffentlichen Team-URLs.
        // Falls ein Trainer manuell eine Vereins-Website einträgt, bleibt diese erhalten.

        // Logo
        if ( $club_id ) {
            $logo_id = $this->logo_handler->maybe_sync_logo( $wp_id, $permanent_id, $club_id );
            if ( $logo_id ) $this->stats['logos_fetched']++;
        }

        // Taxonomies
        $league_terms = array_filter( array_map( fn( $lid ) => $league_term_map[ $lid ] ?? null, $liga_ids ) );
        if ( $league_terms ) wp_set_object_terms( $wp_id, $league_terms, 'sp_league', true );

        $season_terms = array_filter( array_map( fn( $s ) => $season_term_map[ $s ] ?? null, $seasons ) );
        if ( $season_terms ) wp_set_object_terms( $wp_id, $season_terms, 'sp_season', true );

        return $wp_id;
    }

    // ═════════════════════════════════════════
    // EVENT SYNC
    // ═════════════════════════════════════════

    private function sync_event(
        array $match, array $team_wp_map,
        array $league_term_map, array $season_term_map
    ): void {
        $match_id = (int) ( $match['matchId'] ?? 0 );
        if ( ! $match_id ) return;

        $home_permanent_id = (int) ( $match['homeTeam']['teamPermanentId'] ?? 0 );
        $away_permanent_id = (int) ( $match['guestTeam']['teamPermanentId'] ?? 0 );

        // Freilos/Platzhalter/Turnier: Teams mit permanentId=0 oder null
        // - "Freilos1" etc. = Bye, Gegner rückt automatisch weiter (Pokal/KO)
        // - "?" = Turnier-Platzhalter, Gegner steht nach Vorrunde fest
        // Beide haben permanentId=null in der API → (int) null === 0
        if ( ! $home_permanent_id || ! $away_permanent_id ) {
            $home_name = $match['homeTeam']['teamname'] ?? '?';
            $away_name = $match['guestTeam']['teamname'] ?? '?';
            $combined  = mb_strtolower( $home_name . $away_name );
            $reason    = match(true) {
                str_contains( $combined, 'freilos' ) => 'Freilos (Bye)',
                str_contains( $combined, '?' )       => 'Turnier-Platzhalter (Gegner steht noch nicht fest)',
                default                              => 'Team ohne permanentId',
            };
            $this->log( sprintf(
                'Event übersprungen: Match #%d (%s vs %s) – %s',
                $match_id, $home_name, $away_name, $reason
            ));
            $this->stats['events_skipped']++;
            return;
        }

        $home_wp_id = $team_wp_map[ $home_permanent_id ] ?? false;
        $away_wp_id = $team_wp_map[ $away_permanent_id ] ?? false;

        if ( ! $home_wp_id || ! $away_wp_id ) {
            $home_name = $match['homeTeam']['teamname'] ?? '?';
            $away_name = $match['guestTeam']['teamname'] ?? '?';
            $this->log( sprintf(
                'Event-Fehler: Team-Mapping fehlt für Match #%d (%s vs %s) – home_pid=%d→%s, away_pid=%d→%s',
                $match_id, $home_name, $away_name,
                $home_permanent_id, $home_wp_id ? "#{$home_wp_id}" : 'MISSING',
                $away_permanent_id, $away_wp_id ? "#{$away_wp_id}" : 'MISSING'
            ), 'error' );
            $this->stats['errors']++;
            return;
        }

        $liga_data   = $match['ligaData'] ?? [];
        $liga_id     = (int) ( $liga_data['ligaId'] ?? 0 );
        $season_name = $liga_data['seasonName'] ?? '';

        $home_name = $match['homeTeam']['teamname'] ?? get_the_title( $home_wp_id );
        $away_name = $match['guestTeam']['teamname'] ?? get_the_title( $away_wp_id );
        $title     = "{$home_name} vs. {$away_name}";

        $date     = $match['kickoffDate'] ?? '';
        $time     = $match['kickoffTime'] ?? '00:00';
        $datetime = $date ? "{$date} {$time}:00" : current_time( 'mysql' );

        $abgesagt   = $match['abgesagt'] ?? null;
        $result_str = $match['result'] ?? null;

        if ( $abgesagt === true ) {
            $post_status = 'draft';
        } elseif ( $result_str !== null ) {
            $post_status = 'publish';
        } else {
            $post_status = 'future';
        }

        $existing_id = $this->find_sp_event( $match_id );
        if ( ! $existing_id ) {
            $existing_id = $this->find_sp_event_by_date_and_teams( $datetime, $home_wp_id, $away_wp_id );
            if ( $existing_id ) {
                $this->log( "Adoption: Event '{$title}' (SP #{$existing_id}) ← matchId {$match_id}" );
            }
        }

        $sync_uid   = $this->get_sync_user_id();
        $is_update  = (bool) $existing_id;

        if ( $is_update ) {
            // ═══ UPDATE: Nur sichere Felder ändern, manuell Eingetragenes schützen ═══
            $wp_id = $existing_id;
            $current_post = get_post( $existing_id );

            $update_data = [ 'ID' => $existing_id ];

            // Titel NIE überschreiben (Spielbericht-Titel, manuelle Änderungen)
            // Nur bei Adoption (noch kein _bbb_match_id) initial setzen
            if ( ! get_post_meta( $existing_id, '_bbb_match_id', true ) ) {
                $update_data['post_title'] = $title;
            }

            // Datum aktualisieren (Spielverlegung)
            $update_data['post_date'] = $datetime;

            // Status nur "upgraden" (future→publish), nie manuellen Status überschreiben
            $current_status = $current_post->post_status ?? 'publish';
            if ( $current_status === 'future' && $post_status === 'publish' ) {
                $update_data['post_status'] = 'publish';
            } elseif ( $current_status === 'future' && $post_status === 'draft' ) {
                $update_data['post_status'] = 'draft'; // Abgesagt
            }
            // 'publish' oder manuell gesetzter Status bleibt IMMER erhalten

            // post_content (Spielbericht) NIE anfassen
            wp_update_post( $update_data );
            $this->stats['events_updated']++;

        } else {
            // ═══ CREATE: Alle Felder setzen ═══
            $post_data = [
                'post_title'  => $title,
                'post_type'   => 'sp_event',
                'post_status' => $post_status,
                'post_date'   => $datetime,
                'post_author' => $sync_uid,
            ];
            $wp_id = wp_insert_post( $post_data );
            if ( is_wp_error( $wp_id ) || ! $wp_id ) {
                $this->stats['errors']++;
                return;
            }
            $this->stats['events_created']++;
        }

        // BBB meta (_bbb_match_id immer setzen = Primary Key)
        update_post_meta( $wp_id, '_bbb_match_id', $match_id );
        // BBB-interne Meta: Immer aktualisieren (keine User-Daten)
        $this->set_meta_if_not_null( $wp_id, '_bbb_liga_id', $liga_id ?: null );
        $this->set_meta_if_not_null( $wp_id, '_bbb_match_day', $match['matchDay'] ?? null );
        $this->set_meta_if_not_null( $wp_id, '_bbb_match_no', $match['matchNo'] ?? null );
        $this->set_meta_if_not_null( $wp_id, '_bbb_verzicht', $match['verzicht'] ?? null );
        $this->set_meta_if_not_null( $wp_id, '_bbb_abgesagt', $match['abgesagt'] ?? null );
        $this->set_meta_if_not_null( $wp_id, '_bbb_ergebnis_bestaetigt', $match['ergebnisbestaetigt'] ?? null );

        // SportsPress Teams
        delete_post_meta( $wp_id, 'sp_team' );
        add_post_meta( $wp_id, 'sp_team', $home_wp_id );
        add_post_meta( $wp_id, 'sp_team', $away_wp_id );

        $this->set_meta_if_not_null( $wp_id, 'sp_day', $match['matchDay'] ?? null );
        $this->set_meta_if_empty( $wp_id, 'sp_format', 'league' );

        // Results – SportsPress erwartet IMMER ein sp_results Array mit beiden Teams,
        // auch bei Spielen ohne Ergebnis (sonst: "Undefined array key 'results'")
        //
        // Konfigurierte Slugs: z.B. ['t','pts'] → Gesamtergebnis in beide Spalten schreiben.
        // Basketball-typisch: 't' (Total für Teaser) + 'pts' (Event-Editor).
        $rk = $this->get_main_result_slug() ?: 'pts'; // Primär-Slug (für sp_main_result)
        $all_slugs = $this->get_result_slugs();        // Alle konfigurierten Slugs
        if ( empty( $all_slugs ) ) $all_slugs = [ $rk ]; // Fallback: nur Primär-Slug

        $existing_results = get_post_meta( $wp_id, 'sp_results', true );
        $has_existing_results = false;
        if ( ! empty( $existing_results ) && is_array( $existing_results ) ) {
            foreach ( $existing_results as $team_id => $data ) {
                if ( ! is_array( $data ) ) continue;
                foreach ( $data as $k => $v ) {
                    if ( $k === 'outcome' ) continue;
                    if ( $v !== '' && $v !== '0' && $v !== null ) {
                        $has_existing_results = true;
                        break 2;
                    }
                }
            }
        }

        if ( $result_str !== null && str_contains( (string) $result_str, ':' ) ) {
            // API hat Ergebnis → nur schreiben wenn noch kein Ergebnis vorhanden
            if ( ! $has_existing_results ) {
                $parts = explode( ':', $result_str );
                $home_score = (int) $parts[0];
                $away_score = (int) $parts[1];

                // Ergebnis in ALLE konfigurierten Slugs schreiben
                $home_data = [ 'outcome' => [ $home_score > $away_score ? 'win' : ( $home_score < $away_score ? 'loss' : 'draw' ) ] ];
                $away_data = [ 'outcome' => [ $away_score > $home_score ? 'win' : ( $away_score < $home_score ? 'loss' : 'draw' ) ] ];
                foreach ( $all_slugs as $slug ) {
                    $home_data[ $slug ] = (string) $home_score;
                    $away_data[ $slug ] = (string) $away_score;
                }

                $results = [ $home_wp_id => $home_data, $away_wp_id => $away_data ];
                update_post_meta( $wp_id, 'sp_results', $results );
                update_post_meta( $wp_id, 'sp_status', 'results' );
                update_post_meta( $wp_id, 'sp_main_result', $rk );
            }
        } else {
            // Kein API-Ergebnis → Leere Struktur nur bei Neuanlage
            if ( ! $is_update && empty( $existing_results ) ) {
                $home_data = [ 'outcome' => [] ];
                $away_data = [ 'outcome' => [] ];
                foreach ( $all_slugs as $slug ) {
                    $home_data[ $slug ] = '';
                    $away_data[ $slug ] = '';
                }
                update_post_meta( $wp_id, 'sp_results', [ $home_wp_id => $home_data, $away_wp_id => $away_data ] );
            }
        }

        // sp_main_result IMMER setzen (auch bei Update, falls fehlend)
        $current_main = get_post_meta( $wp_id, 'sp_main_result', true );
        if ( ! $current_main ) {
            update_post_meta( $wp_id, 'sp_main_result', $rk );
        }

        // Taxonomies
        $league_term = $league_term_map[ $liga_id ] ?? null;
        if ( $league_term ) wp_set_object_terms( $wp_id, $league_term, 'sp_league', false );

        $season_term = $season_term_map[ $season_name ] ?? null;
        if ( $season_term ) wp_set_object_terms( $wp_id, $season_term, 'sp_season', false );

        // Player-Sync + Venue
        $players_enabled = (bool) get_option( 'bbb_sync_players_enabled', false );
        $boxscore_data = null;

        if ( $result_str !== null && $players_enabled ) {
            // Nur eigene Spieler? → Eigene Team-IDs als Filter übergeben
            $own_pids = [];
            if ( (bool) get_option( 'bbb_sync_players_own_only', true ) ) {
                $own_pids = array_map( 'intval', get_option( 'bbb_sync_own_teams', [] ) );
            }
            $player_stats = $this->player_sync->sync_from_match( $match_id, $wp_id, $team_wp_map, $own_pids );
            $this->stats['players_created'] += $player_stats['created'];
            $this->stats['players_updated'] += $player_stats['updated'];
            if ( $player_stats['created'] + $player_stats['updated'] > 0 ) {
                $this->stats['api_calls']++;
            }
            $boxscore_data = $player_stats['boxscore_data'] ?? null;

            // Spielerlisten für eigene Teams
            $season_term_id = $season_term_map[ $season_name ] ?? null;
            $league_term_id = $league_term_map[ $liga_id ] ?? null;
            if ( $season_term_id ) {
                foreach ( [ $home_permanent_id, $away_permanent_id ] as $pid ) {
                    $t_wp_id = $team_wp_map[ $pid ] ?? null;
                    if ( ! $t_wp_id ) continue;
                    if ( get_post_meta( $t_wp_id, '_bbb_is_own_team', true ) !== '1' ) continue;

                    $list_id = $this->player_sync->ensure_player_list(
                        $t_wp_id, $season_term_id, $league_term_id ?: 0
                    );
                    if ( $list_id && $boxscore_data ) {
                        $mb = $boxscore_data['matchBoxscore'] ?? null;
                        if ( $mb ) {
                            $side_key = ( $pid === $home_permanent_id ) ? 'homePlayerStats' : 'guestPlayerStats';
                            foreach ( $mb[ $side_key ] ?? [] as $ps ) {
                                $player_wrapper = $ps['player'] ?? $ps;
                                $person = $player_wrapper['person'] ?? [];
                                $person_id = (int) ( $person['id'] ?? 0 );
                                if ( $person_id ) {
                                    $pw_id = $this->find_sp_player_by_person_id( $person_id );
                                    if ( $pw_id ) {
                                        $this->player_sync->add_player_to_list( $list_id, $pw_id );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // v3.5.0: Venue-Sync (immer, auch ohne Player-Sync)
        $this->maybe_sync_venue( $match, $wp_id, $boxscore_data );
    }

    // ═════════════════════════════════════════
    // RECONCILIATION
    // ═════════════════════════════════════════

    private function reconcile_events( array $api_match_ids, int $team_permanent_id, array $team_wp_map ): void {
        if ( empty( $api_match_ids ) ) return;

        $team_wp_id = $team_wp_map[ $team_permanent_id ] ?? null;
        if ( ! $team_wp_id ) return;

        $query = new WP_Query([
            'post_type' => 'sp_event', 'post_status' => 'any', 'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [ 'key' => '_bbb_match_id', 'compare' => 'EXISTS' ],
                [ 'key' => 'sp_team', 'value' => $team_wp_id ],
            ],
            'fields' => 'ids', 'no_found_rows' => true,
        ]);

        $orphaned = 0;
        foreach ( $query->posts as $sp_event_id ) {
            $sp_match_id = (int) get_post_meta( $sp_event_id, '_bbb_match_id', true );
            if ( $sp_match_id && ! in_array( $sp_match_id, $api_match_ids, true ) ) {
                wp_delete_post( $sp_event_id, true );
                $orphaned++;
            }
        }
        if ( $orphaned > 0 ) $this->stats['events_deleted'] += $orphaned;
    }

    // ═════════════════════════════════════════
    // LIGA SPIELPLAN SYNC (v3.5.3: Alle Spiele für korrekte Tabellen)
    // ═════════════════════════════════════════

    /**
     * Sync ALLER Spiele einer Liga (nicht nur eigene).
     *
     * Damit SportsPress die Tabelle korrekt berechnen kann, müssen auch Spiele
     * ohne eigene Beteiligung als sp_event existieren.
     *
     * Spielplan-Endpoint liefert ligaData auf Top-Level → wird in jedes Match injiziert.
     * Bereits existierende Events (aus Team-Sync) werden nur aktualisiert, nicht dupliziert.
     */
    private function sync_liga_spielplan( int $liga_id ): void {
        $result = $this->api->get_liga_spielplan( $liga_id );
        $this->stats['api_calls']++;

        if ( is_wp_error( $result ) ) {
            $this->log( "Liga-Spielplan API-Fehler für Liga #{$liga_id}: " . $result->get_error_message(), 'error' );
            $this->stats['errors']++;
            return;
        }

        $liga_data = $result['liga_data'] ?? [];
        $matches   = $result['matches'] ?? [];
        $liga_name = $liga_data['liganame'] ?? "Liga #{$liga_id}";

        if ( empty( $matches ) ) {
            $this->log( "Liga-Spielplan '{$liga_name}': Keine Matches" );
            return;
        }

        // ligaData in jedes Match injizieren (Spielplan hat es nur auf Top-Level)
        foreach ( $matches as &$match ) {
            if ( ! isset( $match['ligaData'] ) ) {
                $match['ligaData'] = $liga_data;
            }
        }
        unset( $match );

        // Zähle nur Matches die noch nicht als sp_event existieren
        $new_matches = 0;
        foreach ( $matches as $m ) {
            $mid = (int) ( $m['matchId'] ?? 0 );
            if ( $mid && ! $this->find_sp_event( $mid ) ) {
                $new_matches++;
            }
        }

        $this->log( sprintf(
            'Liga-Spielplan \'%s\': %d Matches gesamt, %d neu (ohne eigene Beteiligung)',
            $liga_name, count( $matches ), $new_matches
        ));

        // Teams + Leagues/Seasons aus Spielplan-Matches erstellen
        $club_id = (int) get_option( 'bbb_sync_club_id', 0 );

        $league_term_map = [];
        $season_term_map = [];

        $league_term_map[ $liga_id ] = $this->ensure_sp_league( $liga_data, $liga_id );
        $season_name = $liga_data['seasonName'] ?? '';
        if ( $season_name ) {
            $season_term_map[ $season_name ] = $this->ensure_sp_season( $liga_data );
        }

        // Teams aus allen Matches der Liga synken
        $team_wp_map = $this->sync_teams_from_matches( $matches, $club_id, [], $league_term_map, $season_term_map );

        // Events synken (bestehende werden nur aktualisiert)
        $synced = 0;
        foreach ( $matches as $match ) {
            $this->sync_event( $match, $team_wp_map, $league_term_map, $season_term_map );
            $synced++;
        }

        $this->stats['liga_matches_synced'] += $synced;

        // sp_table nur für Ligen mit Tabelle (nicht für Pokal/KO-Wettbewerbe)
        // WICHTIG: Nur explizites false = Pokal! null = unbekannt → als Liga behandeln.
        // Club-Endpoint liefert oft null, Spielplan-Endpoint ist zuverlässiger.
        $has_table = ( $liga_data['tableExists'] ?? null ) !== false;
        $league_term_id = $league_term_map[ $liga_id ] ?? null;
        $season_term_id = $season_term_map[ $season_name ] ?? null;

        $raw_table_exists = $liga_data['tableExists'] ?? null;
        $this->log( sprintf(
            'Liga \'%s\' (#%d): tableExists=%s → %s',
            $liga_name, $liga_id,
            var_export( $raw_table_exists, true ),
            $has_table ? 'Liga (sp_table)' : 'Pokal (Bracket)'
        ));

        if ( $has_table && $league_term_id ) {
            $this->ensure_league_table( $liga_id, $liga_name, $league_term_id, $season_term_id, $team_wp_map );
        } elseif ( ! $has_table ) {
            $sk_name = $liga_data['skName'] ?? '';
            $this->log( sprintf(
                'Keine Tabelle für \'%s\' (tableExists=false%s) – Pokal/KO-Wettbewerb → Bracket-Cache invalidiert',
                $liga_name,
                $sk_name ? ", Typ: {$sk_name}" : ''
            ));
            // Bracket-Cache invalidieren damit Shortcode aktuelle Daten zeigt
            BBB_Tournament_Bracket::invalidate_cache( $liga_id );
        }
    }

    // ═════════════════════════════════════════════
    // LEAGUE TABLE SYNC (v3.5.5: sp_table pro Liga)
    // ═════════════════════════════════════════════

    /**
     * SportsPress League Table (sp_table) erstellen oder aktualisieren.
     *
     * SportsPress berechnet die Tabelle automatisch aus sp_event Ergebnissen.
     * Wir müssen nur den sp_table Post mit den richtigen Teams + Taxonomien erstellen.
     *
     * @param int      $liga_id        BBB Liga-ID
     * @param string   $liga_name      Liga-Name für Post-Titel
     * @param int      $league_term_id SP League Taxonomy Term ID
     * @param int|null $season_term_id SP Season Taxonomy Term ID
     * @param array    $team_wp_map    permanentId => WP Post ID
     */
    private function ensure_league_table(
        int $liga_id, string $liga_name,
        int $league_term_id, ?int $season_term_id,
        array $team_wp_map
    ): void {
        $existing_id = $this->find_sp_table_by_liga_id( $liga_id );
        $sync_uid    = $this->get_sync_user_id();

        $post_data = [
            'post_title'  => $liga_name,
            'post_type'   => 'sp_table',
            'post_status' => 'publish',
        ];

        if ( $existing_id ) {
            // UPDATE: Titel NIE überschreiben
            $wp_id = $existing_id;
            $this->stats['tables_updated']++;
        } else {
            $post_data['post_author'] = $sync_uid;
            $wp_id = wp_insert_post( $post_data );
            if ( is_wp_error( $wp_id ) || ! $wp_id ) {
                $this->log( "Tabelle für '{$liga_name}' konnte nicht erstellt werden", 'error' );
                $this->stats['errors']++;
                return;
            }
            $this->stats['tables_created']++;
            $this->log( "Tabelle erstellt: '{$liga_name}' (SP #{$wp_id})" );
        }

        // Meta
        update_post_meta( $wp_id, '_bbb_liga_id', $liga_id );

        // Teams zuweisen (sp_team Meta, mehrfach)
        delete_post_meta( $wp_id, 'sp_team' );
        foreach ( $team_wp_map as $team_wp_id ) {
            add_post_meta( $wp_id, 'sp_team', $team_wp_id );
        }

        // Taxonomien
        wp_set_object_terms( $wp_id, $league_term_id, 'sp_league', false );
        if ( $season_term_id ) {
            wp_set_object_terms( $wp_id, $season_term_id, 'sp_season', false );
        }

        // SportsPress-Konfiguration für Basketball
        $rk = $this->get_main_result_slug() ?: 'pts';
        update_post_meta( $wp_id, 'sp_main_result', $rk );
    }

    /**
     * sp_table Post über _bbb_liga_id finden.
     */
    private function find_sp_table_by_liga_id( int $liga_id ): int|false {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_table' AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_bbb_liga_id' AND pm.meta_value = %d
             LIMIT 1",
            $liga_id
        ) );
        return $id ? (int) $id : false;
    }

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

        // Bereits Venue mit Adresse zugewiesen?
        $existing_venues = wp_get_object_terms( $event_wp_id, 'sp_venue', [ 'fields' => 'ids' ] );
        if ( ! empty( $existing_venues ) && ! is_wp_error( $existing_venues ) ) {
            // SportsPress speichert Venue-Meta als Option "taxonomy_{term_id}"
            $venue_option = get_option( "taxonomy_{$existing_venues[0]}", [] );
            $existing_address = $venue_option['sp_address'] ?? '';
            if ( ! empty( $existing_address ) ) {
                return; // Venue + Adresse vorhanden → fertig
            }
        }

        $spielfeld = null;

        // Prio 1: Boxscore matchInfo (falls vorhanden)
        if ( $boxscore_data && isset( $boxscore_data['matchInfo']['spielfeld'] ) ) {
            $spielfeld = $boxscore_data['matchInfo']['spielfeld'];
        }

        // Prio 2: matchInfo-Endpoint (immer als Fallback)
        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) {
            // Nur für beendete Spiele matchInfo laden (API-Call sparen)
            if ( $result_str === null ) return;

            $match_info = $this->api->get_match_info( $match_id );
            $this->stats['api_calls']++;

            if ( is_wp_error( $match_info ) ) {
                $this->stats['errors']++;
                return;
            }
            $this->api->throttle();

            // v3.5.0: Dual-Format Support
            if ( isset( $match_info['matchInfo']['spielfeld'] ) ) {
                // Format A: Höhere Ligen → Spielfeld-Objekt mit Adresse
                $spielfeld = $match_info['matchInfo']['spielfeld'];
            } elseif ( isset( $match_info['spielfeld'] ) && is_array( $match_info['spielfeld'] ) ) {
                // Format A alt: spielfeld direkt in data
                $spielfeld = $match_info['spielfeld'];
            } elseif ( ! empty( $match_info['ort'] ) ) {
                // Format B: Mini-Liga → nur "ort" als String (keine Adresse)
                $spielfeld = [
                    'id'          => abs( crc32( $match_info['ort'] ) ), // Deterministisch
                    'bezeichnung' => $match_info['ort'],
                    // Keine strasse/plz/ort → Mini-Liga hat keine strukturierten Adressdaten
                ];
            }
        }

        if ( ! $spielfeld || empty( $spielfeld['id'] ) ) return;

        $spielfeld_id = (int) $spielfeld['id'];
        $venue_name   = $spielfeld['bezeichnung'] ?? "Spielfeld {$spielfeld_id}";
        $address      = $this->build_venue_address( $spielfeld );

        $venue_term_id = $this->find_venue_by_spielfeld_id( $spielfeld_id );

        if ( ! $venue_term_id ) {
            $result = wp_insert_term( $venue_name, 'sp_venue' );
            if ( is_wp_error( $result ) ) {
                $existing = get_term_by( 'name', $venue_name, 'sp_venue' );
                $venue_term_id = $existing ? $existing->term_id : null;
                if ( ! $venue_term_id ) return;
            } else {
                $venue_term_id = $result['term_id'];
                $this->stats['venues_created']++;
            }
            update_term_meta( $venue_term_id, '_bbb_spielfeld_id', $spielfeld_id );
            if ( $address ) {
                $this->set_venue_meta( $venue_term_id, 'sp_address', $address );
                $this->maybe_geocode_venue( $venue_term_id, $spielfeld );
            }
            $this->log( "Venue: '{$venue_name}' (BBB #{$spielfeld_id})" . ( $address ? " → {$address}" : ' (Mini-Liga, ohne Adresse)' ) );
        } else {
            // Update: Name NIE überschreiben (manuelle Änderungen schützen)
            // Adresse ggf. nachträglich ergänzen
            if ( $address ) {
                $venue_option = get_option( "taxonomy_{$venue_term_id}", [] );
                $old_addr = $venue_option['sp_address'] ?? '';
                if ( ! $old_addr || $old_addr !== $address ) {
                    $this->set_venue_meta( $venue_term_id, 'sp_address', $address );
                    $this->maybe_geocode_venue( $venue_term_id, $spielfeld );
                    if ( ! $old_addr ) {
                        $this->log( "Venue-Adresse nachgetragen: '{$venue_name}' → {$address}" );
                    }
                } else {
                    // Adresse unverändert, aber ggf. Geocoding nachholen
                    $this->maybe_geocode_venue( $venue_term_id, $spielfeld );
                }
            }
            $this->stats['venues_updated']++;
        }

        // Venue zum Event zuweisen
        wp_set_object_terms( $event_wp_id, $venue_term_id, 'sp_venue', false );
    }

    /**
     * SportsPress Venue-Meta schreiben.
     *
     * SportsPress speichert Venue-Daten als WP Option "taxonomy_{term_id}" (Array),
     * NICHT als term_meta. Ohne dieses Format findet SportsPress die Adresse nicht.
     */
    private function set_venue_meta( int $term_id, string $key, string $value ): void {
        $option_key = "taxonomy_{$term_id}";
        $meta = get_option( $option_key, [] );
        if ( ! is_array( $meta ) ) $meta = [];
        $meta[ $key ] = $value;
        update_option( $option_key, $meta );
    }

    /**
     * Geocode venue if lat/lng are missing.
     * Uses Nominatim (OpenStreetMap) – kostenlos, kein API-Key.
     * Rate Limit: max 1 req/sec (respektiert durch throttle()).
     */
    private function maybe_geocode_venue( int $venue_term_id, array $spielfeld ): void {
        $venue_option = get_option( "taxonomy_{$venue_term_id}", [] );

        // Bereits geocodiert?
        if ( ! empty( $venue_option['sp_latitude'] ) && ! empty( $venue_option['sp_longitude'] ) ) {
            return;
        }

        $strasse = $spielfeld['strasse'] ?? '';
        $plz     = $spielfeld['plz'] ?? '';
        $ort     = $spielfeld['ort'] ?? '';

        if ( ! $strasse && ! $plz && ! $ort ) return;

        $coords = $this->geocode_address( $strasse, $plz, $ort );
        if ( ! $coords ) return;

        $this->set_venue_meta( $venue_term_id, 'sp_latitude', $coords['lat'] );
        $this->set_venue_meta( $venue_term_id, 'sp_longitude', $coords['lon'] );

        $bezeichnung = $spielfeld['bezeichnung'] ?? 'Venue';
        $this->log( "Geocoded: '{$bezeichnung}' → {$coords['lat']}, {$coords['lon']}" );
    }

    /**
     * Nominatim Geocoding (OpenStreetMap).
     * Structured query für präzisere Ergebnisse mit deutschen Adressen.
     *
     * @return array{lat: string, lon: string}|null
     */
    private function geocode_address( string $strasse, string $plz, string $ort ): ?array {
        $query_params = [
            'format'       => 'json',
            'countrycodes' => 'de',
            'limit'        => 1,
        ];

        if ( $strasse ) $query_params['street']     = $strasse;
        if ( $plz )     $query_params['postalcode'] = $plz;
        if ( $ort )     $query_params['city']       = $ort;

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query( $query_params );

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'BBB-SportsPress-Sync/' . BBB_SYNC_VERSION . ' (WordPress Plugin; +https://github.com)',
            'headers'    => [ 'Accept' => 'application/json' ],
        ] );

        // Rate Limit respektieren
        $this->api->throttle();

        if ( is_wp_error( $response ) ) {
            $this->log( 'Geocoding-Fehler: ' . $response->get_error_message(), 'error' );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $this->log( "Geocoding HTTP {$code}", 'error' );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) {
            // Fallback: Nur PLZ + Ort (ohne Straße) probieren
            if ( $strasse && ( $plz || $ort ) ) {
                $this->log( "Geocoding: Straße nicht gefunden, Fallback auf PLZ/Ort: {$plz} {$ort}" );
                return $this->geocode_address( '', $plz, $ort );
            }
            $this->log( "Geocoding: Keine Ergebnisse für {$strasse}, {$plz} {$ort}" );
            return null;
        }

        return [
            'lat' => $data[0]['lat'],
            'lon' => $data[0]['lon'],
        ];
    }

    private function build_venue_address( array $spielfeld ): string {
        $parts = array_filter([
            $spielfeld['strasse'] ?? '',
            trim( ( $spielfeld['plz'] ?? '' ) . ' ' . ( $spielfeld['ort'] ?? '' ) ),
        ]);
        return $parts ? implode( ', ', $parts ) : '';
    }

    private function find_venue_by_spielfeld_id( int $spielfeld_id ): int|false {
        $terms = get_terms([
            'taxonomy' => 'sp_venue', 'meta_key' => '_bbb_spielfeld_id',
            'meta_value' => $spielfeld_id, 'hide_empty' => false, 'number' => 1,
        ]);
        return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->term_id : false;
    }

    // ═════════════════════════════════════════
    // RESET HELPERS
    // ═════════════════════════════════════════

    private function reset_boxscore_flags(): void {
        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_bbb_boxscore_synced' ], [ '%s' ] );
        $this->log( "Boxscore-Flags resettet: {$deleted} Einträge gelöscht" );
    }

    /**
     * v3.5.2: Migriere bestehende Venue-Adressen von term_meta nach taxonomy_option.
     * SportsPress liest Venue-Meta aus get_option("taxonomy_{term_id}"), nicht aus term_meta.
     */
    private function migrate_venue_meta_to_options(): void {
        $terms = get_terms([
            'taxonomy' => 'sp_venue', 'hide_empty' => false,
            'meta_key' => '_bbb_spielfeld_id', 'meta_compare' => 'EXISTS',
        ]);
        if ( is_wp_error( $terms ) ) return;

        $migrated = 0;
        foreach ( $terms as $term ) {
            // Alte Adresse aus term_meta lesen
            $addr_from_termmeta = get_term_meta( $term->term_id, 'sp_address', true );
            if ( empty( $addr_from_termmeta ) ) continue;

            // In SportsPress-Format (Option) schreiben
            $option_key = "taxonomy_{$term->term_id}";
            $meta = get_option( $option_key, [] );
            if ( ! is_array( $meta ) ) $meta = [];

            if ( empty( $meta['sp_address'] ) ) {
                $meta['sp_address'] = $addr_from_termmeta;
                update_option( $option_key, $meta );
                $migrated++;
            }

            // Altes term_meta aufräumen
            delete_term_meta( $term->term_id, 'sp_address' );
        }

        if ( $migrated > 0 ) {
            $this->log( "v3.5.2 Migration: {$migrated} Venue-Adressen von term_meta nach taxonomy_option migriert" );
        }
    }

    /**
     * v3.5.3: Bestehende BBB-synchronisierte Posts auf Sync-User umhängen.
     * Nur Posts, die ausschließlich Sync-Daten enthalten (kein manueller Content).
     */
    private function migrate_post_authors_to_sync_user(): void {
        global $wpdb;
        $sync_uid = $this->get_sync_user_id();
        if ( ! $sync_uid ) return;

        // sp_team + sp_event: Alle mit _bbb_* Meta
        $meta_keys_by_type = [
            'sp_team'   => '_bbb_team_permanent_id',
            'sp_event'  => '_bbb_match_id',
            'sp_player' => '_bbb_player_id',
            'sp_list'   => '_bbb_team_wp_id',
        ];

        $total = 0;
        foreach ( $meta_keys_by_type as $post_type => $meta_key ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 SET p.post_author = %d
                 WHERE p.post_type = %s AND p.post_author != %d",
                $meta_key, $sync_uid, $post_type, $sync_uid
            ) );
            $total += (int) $updated;
        }

        if ( $total > 0 ) {
            wp_cache_flush();
            $this->log( "v3.5.3 Migration: {$total} Posts auf Sync-User 'basketball-bund.net' umgestellt" );
        }

        // Ungültige Team-URLs entfernen (basketball-bund.net/team/id/... sind nicht öffentlich)
        $cleaned = $wpdb->query(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key = 'sp_url'
             AND meta_value LIKE 'https://www.basketball-bund.net/team/id/%'"
        );
        if ( $cleaned > 0 ) {
            $this->log( "v3.5.3 Migration: {$cleaned} ungültige Team-URLs entfernt" );
        }
    }

    /**
     * v3.5.0: Venue-Adressen resettet → erzwingt Neuladen aus matchInfo
     */
    private function reset_venue_addresses(): void {
        // Lösche alle leeren sp_address Meta (erzwingt Neuladen)
        $terms = get_terms([
            'taxonomy' => 'sp_venue', 'hide_empty' => false,
            'meta_key' => '_bbb_spielfeld_id', 'meta_compare' => 'EXISTS',
        ]);
        if ( is_wp_error( $terms ) ) return;

        $count = 0;
        foreach ( $terms as $term ) {
            $venue_option = get_option( "taxonomy_{$term->term_id}", [] );
            $addr = $venue_option['sp_address'] ?? '';
            if ( empty( $addr ) ) {
                // Venue von allen Events entfernen → erzwingt Re-Zuweisung
                // (Venue bleibt erhalten, wird beim nächsten Sync mit Adresse aktualisiert)
                $count++;
            }
        }
        if ( $count > 0 ) {
            $this->log( "{$count} Venues ohne Adresse gefunden → werden beim Sync aktualisiert" );
        }
    }

    // ═════════════════════════════════════════
    // FINDERS
    // ═════════════════════════════════════════

    private function find_sp_team_by_permanent_id( int $permanent_id ): int|false {
        if ( ! $permanent_id ) return false;
        // v3.5.0: Direkte DB-Query statt WP_Query (umgeht Object Cache)
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_team' AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_bbb_team_permanent_id' AND pm.meta_value = %d
             ORDER BY p.ID ASC LIMIT 1",
            $permanent_id
        ) );
        return $id ? (int) $id : false;
    }

    private function find_sp_team_by_name( string $team_name ): int|false {
        $normalized = mb_strtolower( trim( $team_name ) );
        $query = new WP_Query([
            'post_type' => 'sp_team', 'post_status' => 'any', 'posts_per_page' => -1,
            'meta_query' => [[ 'key' => '_bbb_team_permanent_id', 'compare' => 'NOT EXISTS' ]],
            'fields' => 'ids', 'no_found_rows' => true,
        ]);
        foreach ( $query->posts as $post_id ) {
            $sp_name = mb_strtolower( trim( get_the_title( $post_id ) ) );
            if ( $sp_name === $normalized ) return $post_id;
            if ( str_contains( $normalized, $sp_name ) || str_contains( $sp_name, $normalized ) ) return $post_id;
        }
        return false;
    }

    private function find_sp_player_by_person_id( int $person_id ): int|false {
        if ( ! $person_id ) return false;
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'
             AND pm.meta_key = '_bbb_person_id' AND pm.meta_value = %d
             LIMIT 1",
            $person_id
        ) );
        return $id ? (int) $id : false;
    }

    private function find_sp_event( int $match_id ): int|false {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_event' AND pm.meta_key = '_bbb_match_id' AND pm.meta_value = %d
             LIMIT 1",
            $match_id
        ) );
        return $id ? (int) $id : false;
    }

    private function find_sp_event_by_date_and_teams( string $datetime, int $home_wp_id, int $away_wp_id ): int|false {
        $date = date( 'Y-m-d', strtotime( $datetime ) );
        $query = new WP_Query([
            'post_type' => 'sp_event', 'post_status' => 'any', 'posts_per_page' => -1,
            'date_query' => [[ 'after' => date( 'Y-m-d', strtotime( "$date -1 day" ) ), 'before' => date( 'Y-m-d', strtotime( "$date +1 day" ) ), 'inclusive' => true ]],
            'meta_query' => [[ 'key' => '_bbb_match_id', 'compare' => 'NOT EXISTS' ]],
            'fields' => 'ids', 'no_found_rows' => true,
        ]);
        foreach ( $query->posts as $post_id ) {
            $sp_teams = get_post_meta( $post_id, 'sp_team' );
            if ( in_array( $home_wp_id, $sp_teams ) && in_array( $away_wp_id, $sp_teams ) ) return $post_id;
        }
        return false;
    }

    // ═════════════════════════════════════════
    // HELPERS
    // ═════════════════════════════════════════

    private function extract_leagues( array $matches ): array {
        $leagues = [];
        foreach ( $matches as $match ) {
            $liga_data = $match['ligaData'] ?? null;
            if ( ! $liga_data ) continue;
            $liga_id = (int) ( $liga_data['ligaId'] ?? 0 );
            if ( $liga_id && ! isset( $leagues[ $liga_id ] ) ) $leagues[ $liga_id ] = $liga_data;
        }
        return $leagues;
    }

    private function ensure_sp_league( array $liga_data, int $bbb_liga_id ): int|false {
        $name = $liga_data['liganame'] ?? "Liga {$bbb_liga_id}";
        $slug = 'bbb-' . $bbb_liga_id;
        $term = get_term_by( 'slug', $slug, 'sp_league' );
        if ( $term ) {
            if ( $term->name !== $name ) wp_update_term( $term->term_id, 'sp_league', [ 'name' => $name ] );
            return $term->term_id;
        }
        $result = wp_insert_term( $name, 'sp_league', [ 'slug' => $slug ] );
        if ( is_wp_error( $result ) ) return false;

        // v3.5.1: akName + geschlecht direkt aus ligaData (immer vorhanden)
        update_term_meta( $result['term_id'], '_bbb_liga_id', $bbb_liga_id );
        update_term_meta( $result['term_id'], '_bbb_ak_name', $liga_data['akName'] ?? '' );
        update_term_meta( $result['term_id'], '_bbb_geschlecht', $liga_data['geschlecht'] ?? '' );
        return $result['term_id'];
    }

    private function ensure_sp_season( array $liga_data ): int|false {
        $season_name = $liga_data['seasonName'] ?? ( ( $liga_data['seasonId'] ?? date( 'Y' ) ) . '/' . ( ( $liga_data['seasonId'] ?? date( 'Y' ) ) + 1 ) );
        $season_slug = sanitize_title( $season_name );
        $term = get_term_by( 'slug', $season_slug, 'sp_season' );
        if ( $term ) return $term->term_id;
        $result = wp_insert_term( $season_name, 'sp_season', [ 'slug' => $season_slug ] );
        if ( is_wp_error( $result ) ) {
            $term = get_term_by( 'name', $season_name, 'sp_season' );
            return $term ? $term->term_id : false;
        }
        return $result['term_id'];
    }

    /**
     * Primären Result-Slug ermitteln (für sp_main_result).
     *
     * SportsPress matcht Ergebnis-Keys über den post_name des sp_result Posts.
     * Je nach Installation kann der Slug 'pts', 'punkte', 't', 'total' etc. sein.
     * Diese Methode nutzt die Einstellungen oder findet den Slug automatisch.
     */
    private function get_main_result_slug(): ?string {
        if ( self::$main_result_slug !== null ) return self::$main_result_slug;

        // 1. Aus Einstellungen: Erster konfigurierter Slug = Primär
        $slugs = $this->get_result_slugs();
        if ( ! empty( $slugs ) ) {
            self::$main_result_slug = $slugs[0];
            return self::$main_result_slug;
        }

        // 2. Fallback: 'pts' prüfen (unser historischer Standard)
        $post = get_page_by_path( 'pts', OBJECT, 'sp_result' );
        if ( $post ) {
            self::$main_result_slug = 'pts';
            return 'pts';
        }

        // 3. Fallback: Ersten sp_result Post nehmen
        $results = get_posts([
            'post_type'      => 'sp_result',
            'posts_per_page' => 1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ]);
        if ( ! empty( $results ) ) {
            self::$main_result_slug = $results[0]->post_name;
            $this->log( "Result-Slug erkannt: '{$results[0]->post_name}' (Titel: '{$results[0]->post_title}')" );
            return self::$main_result_slug;
        }

        return null;
    }

    /** @var string[]|null Gecachte Result-Slugs aus Einstellungen */
    private static ?array $result_slugs_cache = null;

    /**
     * Alle konfigurierten Result-Slugs holen.
     *
     * Einstellung: bbb_sync_result_slugs = "t,pts" (Komma-getrennt)
     * Basketball-typisch: Gesamtergebnis wird in 't' (Total) UND 'pts' geschrieben,
     * damit sowohl Teaser (sp_main_result → t) als auch Event-Editor (pts) funktionieren.
     *
     * @return string[] Array von Slugs, leer wenn nichts konfiguriert
     */
    public function get_result_slugs(): array {
        if ( self::$result_slugs_cache !== null ) return self::$result_slugs_cache;

        $raw = get_option( 'bbb_sync_result_slugs', '' );
        $slugs = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        self::$result_slugs_cache = $slugs;
        return $slugs;
    }

    private function set_meta_if_not_null( int $post_id, string $key, mixed $value ): void {
        if ( $value !== null ) update_post_meta( $post_id, $key, $value );
    }

    /**
     * Meta nur schreiben wenn aktueller Wert leer/0/nicht gesetzt ist.
     * Schützt manuell eingetragene Daten vor Überschreibung durch den Sync.
     */
    private function set_meta_if_empty( int $post_id, string $key, mixed $value ): void {
        if ( $value === null || $value === '' || $value === 0 ) return;
        $existing = get_post_meta( $post_id, $key, true );
        if ( $existing !== '' && $existing !== '0' && $existing !== false ) return;
        update_post_meta( $post_id, $key, $value );
    }

    /**
     * Meta nur schreiben wenn API-Wert nicht null/leer UND aktueller Wert leer ist.
     * Kombiniert set_meta_if_not_null + set_meta_if_empty.
     */
    private function set_meta_safe( int $post_id, string $key, mixed $value, bool $is_update ): void {
        if ( $value === null ) return;
        if ( $is_update ) {
            $this->set_meta_if_empty( $post_id, $key, $value );
        } else {
            update_post_meta( $post_id, $key, $value );
        }
    }

    private function log( string $message, string $level = 'info' ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( "[BBB-Sync][{$level}] {$message}" );
        $logs   = get_option( 'bbb_sync_logs', [] );
        $logs[] = [ 'time' => current_time( 'mysql' ), 'level' => $level, 'message' => $message ];
        $logs = array_slice( $logs, -200 );
        update_option( 'bbb_sync_logs', $logs, false );
    }

    public function get_stats(): array {
        return $this->stats;
    }
}
