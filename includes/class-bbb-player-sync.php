<?php
/**
 * BBB Player Sync v3.5.0
 *
 * v3.5.0 FIXES:
 *   1. STAT-MAPPING: Auch 0-Werte schreiben (SP braucht alle Felder für korrekte Aggregation)
 *   2. TEAM-ZUORDNUNG: sp_team als multiple post_meta UND sp_current_team setzen
 *   3. SPIELERLISTEN: Robuster Check mit direct DB query statt WP_Query Cache
 *   4. DEBUG: Komplettes Stat-Mapping für ersten Spieler loggen
 *
 * BBB Boxscore Stat-Felder:
 *   pts, twoPoints{made,attempted}, threePoints{made,attempted},
 *   wt{made,attempted} (=Field Goals), onePoints{made,attempted} (=Free Throws),
 *   ro, rd, rt, as, st, to, bs, fouls, eff, esz
 */

defined( 'ABSPATH' ) || exit;

class BBB_Player_Sync {

    private BBB_Api_Client $api;
    private int $sync_user_id = 0;
    private int $players_created = 0;
    private int $players_updated = 0;
    private int $players_skipped = 0;

    /**
     * BBB Stat-Feld → SportsPress Alias-Liste.
     */
    private const STAT_ALIASES = [
        'pts'   => [ 'pts' ],
        'ro'    => [ 'off', 'oreb' ],
        'rd'    => [ 'def', 'dreb' ],
        'rt'    => [ 'reb' ],
        'as'    => [ 'ast' ],
        'st'    => [ 'stl' ],
        'to'    => [ 'to' ],
        'bs'    => [ 'blk' ],
        'fouls' => [ 'pf' ],
        'eff'   => [ 'eff' ],
        'esz'   => [ 'min' ],
    ];

    /**
     * Nested Stat-Felder: bbb_parent => [ [made_aliases], [attempted_aliases] ]
     */
    private const STAT_ALIASES_NESTED = [
        'wt'          => [ [ 'fgm' ], [ 'fga' ] ],
        'twoPoints'   => [ [ 'twom', '2pm' ], [ 'twoa', '2pa' ] ],
        'threePoints' => [ [ '3pm', 'tpm' ], [ '3pa', 'tpa' ] ],
        'onePoints'   => [ [ 'ftm' ], [ 'fta' ] ],
    ];

    /** Cache: resolved slug map */
    private ?array $resolved_slugs = null;

    /** Cache: konfiguriertes Stat-Mapping aus Einstellungen */
    private ?array $configured_mapping = null;

    public function __construct( BBB_Api_Client $api, int $sync_user_id = 0 ) {
        $this->api = $api;
        $this->sync_user_id = $sync_user_id;
    }

    /**
     * Sync-User-ID nachträglich setzen (nach ensure_sync_user).
     */
    public function set_sync_user_id( int $user_id ): void {
        $this->sync_user_id = $user_id;
    }

    /**
     * Sync players + stats from a finished match's boxscore.
     *
     * @param int   $match_id          BBB Match-ID
     * @param int   $event_wp_id       WP Post-ID des sp_event
     * @param array $team_wp_map       [ permanent_id => wp_team_id ]
     * @param array $own_permanent_ids  Wenn nicht leer: nur diese Teams synchen (eigene Spieler)
     */
    public function sync_from_match(
        int $match_id, int $event_wp_id, array $team_wp_map, array $own_permanent_ids = []
    ): array {
        $this->players_created = 0;
        $this->players_updated = 0;
        $this->players_skipped = 0;

        // Already synced?
        if ( get_post_meta( $event_wp_id, '_bbb_boxscore_synced', true ) === '1' ) {
            return $this->get_stats();
        }

        $boxscore_data = $this->api->get_boxscore( $match_id );

        if ( is_wp_error( $boxscore_data ) ) {
            $this->log( "Boxscore API-Fehler Match {$match_id}: " . $boxscore_data->get_error_message() );
            update_post_meta( $event_wp_id, '_bbb_boxscore_synced', 'error' );
            return $this->get_stats();
        }

        $this->api->throttle();

        $stats = $this->get_stats();
        $stats['boxscore_data'] = $boxscore_data;

        $match_boxscore = $boxscore_data['matchBoxscore'] ?? null;

        if ( $match_boxscore === null ) {
            $this->log( "Kein Boxscore für Match {$match_id} (vermutlich Mini-Liga)" );
            update_post_meta( $event_wp_id, '_bbb_boxscore_synced', 'no_data' );
            $stats = $this->get_stats();
            $stats['boxscore_data'] = $boxscore_data;
            return $stats;
        }

        $stat_type = (int) ( $boxscore_data['statisticType'] ?? 1 );

        $home_permanent_id  = (int) ( $boxscore_data['homeTeam']['teamPermanentId'] ?? 0 );
        $guest_permanent_id = (int) ( $boxscore_data['guestTeam']['teamPermanentId'] ?? 0 );

        $sides = [
            [ 'stats' => $match_boxscore['homePlayerStats'] ?? [], 'permanent_id' => $home_permanent_id ],
            [ 'stats' => $match_boxscore['guestPlayerStats'] ?? [], 'permanent_id' => $guest_permanent_id ],
        ];

        // Event-Performance: team_wp_id => [ player_wp_id => [ slug => value ] ]
        $event_performance = [];

        // v3.5.0: Debug-Flags
        $stat_keys_logged = false;
        $mapped_stats_logged = false;
        $raw_stats_logged = false;

        foreach ( $sides as $side ) {
            $pid = $side['permanent_id'];

            // Filter: Nur eigene Teams synchen wenn $own_permanent_ids gesetzt
            if ( ! empty( $own_permanent_ids ) && ! in_array( $pid, $own_permanent_ids, true ) ) {
                continue;
            }

            $team_wp_id = $team_wp_map[ $pid ] ?? null;
            if ( ! $team_wp_id ) continue;

            if ( ! isset( $event_performance[ $team_wp_id ] ) ) {
                $event_performance[ $team_wp_id ] = [];
            }

            foreach ( $side['stats'] as $player_stat ) {
                // v3.5.0: Debug - Rohe Stat-Keys UND Werte loggen (einmalig)
                if ( ! $stat_keys_logged ) {
                    $keys = array_keys( $player_stat );
                    $this->log( "Boxscore Stat-Keys: [" . implode( ', ', $keys ) . "]" );
                    $stat_keys_logged = true;
                }
                if ( ! $raw_stats_logged ) {
                    // Nur die relevanten Felder loggen (nicht das ganze Objekt)
                    $debug_fields = [];
                    foreach ( array_keys( self::STAT_ALIASES ) as $k ) {
                        if ( isset( $player_stat[ $k ] ) ) $debug_fields[ $k ] = $player_stat[ $k ];
                    }
                    foreach ( array_keys( self::STAT_ALIASES_NESTED ) as $k ) {
                        if ( isset( $player_stat[ $k ] ) ) $debug_fields[ $k ] = $player_stat[ $k ];
                    }
                    $this->log( 'Raw BBB Stats (erster Spieler): ' . wp_json_encode( $debug_fields ) );
                    $raw_stats_logged = true;
                }

                $player_wrapper = $player_stat['player'] ?? $player_stat;
                $wp_player_id = $this->sync_player( $player_wrapper, $team_wp_id, $event_wp_id );

                if ( $wp_player_id ) {
                    $mapped_stats = $this->map_bbb_stats( $player_stat );
                    $event_performance[ $team_wp_id ][ $wp_player_id ] = $mapped_stats;

                    if ( ! $mapped_stats_logged ) {
                        $this->log( 'Mapped Stats (erster Spieler): ' . wp_json_encode( $mapped_stats ) );
                        $mapped_stats_logged = true;
                    }
                }
            }
        }

        if ( ! empty( $event_performance ) ) {
            $this->write_event_performance( $event_wp_id, $event_performance );
        }

        update_post_meta( $event_wp_id, '_bbb_boxscore_synced', '1' );

        $this->log( sprintf(
            'Boxscore Match %d (statType=%d): %d erstellt, %d aktualisiert, %d übersprungen, %d Teams',
            $match_id, $stat_type, $this->players_created, $this->players_updated,
            $this->players_skipped, count( $event_performance )
        ));

        $stats = $this->get_stats();
        $stats['boxscore_data'] = $boxscore_data;
        return $stats;
    }

    /**
     * Resolve BBB stat key to actual SportsPress performance slug.
     */
    private function resolve_sp_slug( array $aliases ): ?string {
        if ( $this->resolved_slugs === null ) {
            $this->resolved_slugs = [];
            $query = new WP_Query([
                'post_type' => 'sp_performance', 'post_status' => 'publish',
                'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            ]);
            foreach ( $query->posts as $pid ) {
                $post = get_post( $pid );
                if ( $post ) $this->resolved_slugs[ $post->post_name ] = true;
            }
            $this->log( 'SP Performance-Slugs: [' . implode( ', ', array_keys( $this->resolved_slugs ) ) . ']' );
        }

        foreach ( $aliases as $slug ) {
            if ( isset( $this->resolved_slugs[ $slug ] ) ) return $slug;
        }
        return $aliases[0] ?? null;
    }

    /**
     * Konfiguriertes Stat-Mapping aus Einstellungen holen.
     *
     * Format: [ 'pts' => 'pts', 'ro' => 'off', 'wt.made' => 'fgm', ... ]
     * Leer = Fallback auf STAT_ALIASES (Abwärtskompatibel).
     */
    private function get_configured_mapping(): array {
        if ( $this->configured_mapping !== null ) return $this->configured_mapping;
        $raw = get_option( 'bbb_sync_stat_mapping', '' );
        $this->configured_mapping = $raw ? ( json_decode( $raw, true ) ?: [] ) : [];
        return $this->configured_mapping;
    }

    /**
     * Map BBB boxscore stat fields to SportsPress performance slugs.
     *
     * Nutzt konfiguriertes Mapping aus Einstellungen (1:1 Zuordnung).
     * Fallback: STAT_ALIASES (Alias-Liste, abwärtskompatibel).
     *
     * v3.5.0 FIX: Auch 0-Werte schreiben! SportsPress braucht explizite Werte
     * für korrekte Aggregation. Nur NULL/fehlende Felder werden übersprungen.
     */
    private function map_bbb_stats( array $player_stat ): array {
        $config = $this->get_configured_mapping();
        $mapped = [];

        if ( ! empty( $config ) ) {
            // ━━━ Konfiguriertes 1:1 Mapping ━━━

            // Einfache Felder
            foreach ( [ 'pts', 'ro', 'rd', 'rt', 'as', 'st', 'to', 'bs', 'fouls', 'eff', 'esz' ] as $bbb_key ) {
                $sp_slug = $config[ $bbb_key ] ?? null;
                if ( $sp_slug && array_key_exists( $bbb_key, $player_stat ) && $player_stat[ $bbb_key ] !== null ) {
                    $mapped[ $sp_slug ] = (string) (int) $player_stat[ $bbb_key ];
                }
            }

            // Nested Felder (made/attempted)
            $nested = [
                'wt'          => 'wt',
                'twoPoints'   => 'twopoints',   // API-Key => Config-Key (lowercase)
                'threePoints' => 'threepoints',
                'onePoints'   => 'onepoints',
            ];
            foreach ( $nested as $api_parent => $config_prefix ) {
                $parent = $player_stat[ $api_parent ] ?? null;
                if ( ! is_array( $parent ) ) continue;

                $made_slug = $config[ "{$config_prefix}.made" ] ?? null;
                if ( $made_slug && array_key_exists( 'made', $parent ) && $parent['made'] !== null ) {
                    $mapped[ $made_slug ] = (string) (int) $parent['made'];
                }
                $att_slug = $config[ "{$config_prefix}.attempted" ] ?? null;
                if ( $att_slug && array_key_exists( 'attempted', $parent ) && $parent['attempted'] !== null ) {
                    $mapped[ $att_slug ] = (string) (int) $parent['attempted'];
                }
            }
        } else {
            // ━━━ Fallback: Alias-basiertes Mapping (Legacy) ━━━

            foreach ( self::STAT_ALIASES as $bbb_key => $aliases ) {
                if ( array_key_exists( $bbb_key, $player_stat ) && $player_stat[ $bbb_key ] !== null ) {
                    $sp_slug = $this->resolve_sp_slug( $aliases );
                    if ( $sp_slug ) {
                        $mapped[ $sp_slug ] = (string) (int) $player_stat[ $bbb_key ];
                    }
                }
            }

            foreach ( self::STAT_ALIASES_NESTED as $bbb_parent => [ $made_aliases, $attempted_aliases ] ) {
                $parent = $player_stat[ $bbb_parent ] ?? null;
                if ( ! is_array( $parent ) ) continue;

                if ( array_key_exists( 'made', $parent ) && $parent['made'] !== null ) {
                    $sp_slug = $this->resolve_sp_slug( $made_aliases );
                    if ( $sp_slug ) $mapped[ $sp_slug ] = (string) (int) $parent['made'];
                }
                if ( array_key_exists( 'attempted', $parent ) && $parent['attempted'] !== null ) {
                    $sp_slug = $this->resolve_sp_slug( $attempted_aliases );
                    if ( $sp_slug ) $mapped[ $sp_slug ] = (string) (int) $parent['attempted'];
                }
            }
        }

        return $mapped;
    }

    /**
     * Write SportsPress event performance data (sp_players meta).
     *
     * Format: [ team_wp_id => [ player_wp_id => [ slug => value ], 0 => [] ] ]
     */
    private function write_event_performance( int $event_wp_id, array $performance ): void {
        // Bestehende sp_players laden – manuell eingetragene Stats schützen
        $sp_players = get_post_meta( $event_wp_id, 'sp_players', true );
        if ( ! is_array( $sp_players ) ) $sp_players = [];

        $total = 0;

        foreach ( $performance as $team_wp_id => $players ) {
            if ( ! isset( $sp_players[ $team_wp_id ] ) ) {
                $sp_players[ $team_wp_id ] = [];
            }
            foreach ( $players as $player_wp_id => $stats ) {
                // Bestehende Stats für diesen Spieler laden
                $existing_stats = $sp_players[ $team_wp_id ][ $player_wp_id ] ?? [];

                // Nur leere/fehlende Felder füllen, manuell Eingetragenes behalten
                foreach ( $stats as $slug => $value ) {
                    $existing_val = $existing_stats[ $slug ] ?? '';
                    if ( $existing_val === '' || $existing_val === '0' || $existing_val === null ) {
                        $existing_stats[ $slug ] = $value;
                    }
                }
                $sp_players[ $team_wp_id ][ $player_wp_id ] = $existing_stats;
                $total++;

                // v3.5.0: Spieler auch als sp_player zum Event hinzufügen
                $existing_event_players = get_post_meta( $event_wp_id, 'sp_player' );
                if ( ! in_array( (string) $player_wp_id, $existing_event_players, true )
                    && ! in_array( $player_wp_id, $existing_event_players, true ) ) {
                    add_post_meta( $event_wp_id, 'sp_player', $player_wp_id );
                }
            }
            // Team-Totals (SP berechnet automatisch) – nur anlegen wenn nicht vorhanden
            if ( ! isset( $sp_players[ $team_wp_id ][0] ) ) {
                $sp_players[ $team_wp_id ][0] = [];
            }
        }

        update_post_meta( $event_wp_id, 'sp_players', $sp_players );
        $this->log( "Event #{$event_wp_id}: {$total} Spieler-Performance gemerged" );
    }

    /**
     * Sync a single player.
     */
    private function sync_player( array $player_data, int $team_wp_id, int $event_wp_id ): ?int {
        $player_id = (int) ( $player_data['playerId'] ?? 0 );
        $person    = $player_data['person'] ?? [];
        $anonym    = ( $player_data['anonym'] ?? false ) || ( $person['anonym'] ?? false );

        if ( $player_id === 0 || $anonym === true ) {
            $this->players_skipped++;
            return null;
        }

        $vorname   = $person['vorname'] ?? '';
        $nachname  = $person['nachname'] ?? '';
        $full_name = trim( "{$vorname} {$nachname}" );

        if ( empty( $full_name ) || $full_name === '*** ****' ) {
            $this->players_skipped++;
            return null;
        }

        $jersey_no = $player_data['no'] ?? '';
        $person_id = (int) ( $person['id'] ?? 0 );

        // Find existing: person_id (unique) → Name-Fallback
        $existing_id = null;

        // 1. Person-ID (unique über alle Teams – primärer Lookup)
        if ( $person_id ) {
            $existing_id = $this->find_by_person_id( $person_id );
        }

        // 2. Name-Fallback (nur für manuell angelegte Spieler ohne BBB-IDs)
        if ( ! $existing_id ) {
            $existing_id = $this->find_by_name( $full_name );
            if ( $existing_id ) {
                $this->log( "Adoption: '{$full_name}' (SP #{$existing_id}) ← person_id {$person_id}" );
            }
        }

        $is_update = (bool) $existing_id;

        if ( $is_update ) {
            // ═══ UPDATE: Titel + Content NIE überschreiben ═══
            $wp_id = $existing_id;
            // Spieler-Titel nur bei Adoption (noch keine _bbb_person_id) setzen
            if ( ! get_post_meta( $existing_id, '_bbb_person_id', true ) ) {
                wp_update_post( [ 'ID' => $existing_id, 'post_title' => $full_name ] );
            }
            $this->players_updated++;
        } else {
            // ═══ CREATE: Alle Felder setzen ═══
            $post_data = [
                'post_title'  => $full_name,
                'post_type'   => 'sp_player',
                'post_status' => 'publish',
            ];
            if ( $this->sync_user_id ) {
                $post_data['post_author'] = $this->sync_user_id;
            }
            $wp_id = wp_insert_post( $post_data );
            if ( is_wp_error( $wp_id ) || ! $wp_id ) return null;
            $this->players_created++;
        }

        // BBB-interne Meta (Primary Keys – immer aktualisieren)
        if ( $person_id ) update_post_meta( $wp_id, '_bbb_person_id', $person_id );
        update_post_meta( $wp_id, '_bbb_player_id', $player_id );

        // SP-Felder: Nur setzen wenn leer (schützt manuelle Änderungen)
        if ( $jersey_no !== '' && $jersey_no !== '**' && $jersey_no !== '0' ) {
            $existing_number = get_post_meta( $wp_id, 'sp_number', true );
            if ( $existing_number === '' || $existing_number === false ) {
                update_post_meta( $wp_id, 'sp_number', $jersey_no );
            }
        }

        // v3.5.0: Team-Zuordnung robust setzen
        // SportsPress nutzt 'sp_team' als MULTIPLE post_meta (nicht Taxonomy!)
        update_post_meta( $wp_id, 'sp_current_team', $team_wp_id );

        // sp_team: prüfe ob schon zugewiesen (direkte DB-Query)
        global $wpdb;
        $already_assigned = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = 'sp_team' AND meta_value = %s",
            $wp_id, (string) $team_wp_id
        ) );
        if ( ! $already_assigned ) {
            add_post_meta( $wp_id, 'sp_team', $team_wp_id );
        }

        // v3.5.0: Auch sp_past_team setzen (SportsPress zeigt damit die Team-Dropdown-Zuordnung)
        // Taxonomien vom Event übernehmen
        $event_leagues = wp_get_object_terms( $event_wp_id, 'sp_league', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $event_leagues ) && ! empty( $event_leagues ) ) {
            wp_set_object_terms( $wp_id, $event_leagues, 'sp_league', true );
        }
        $event_seasons = wp_get_object_terms( $event_wp_id, 'sp_season', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $event_seasons ) && ! empty( $event_seasons ) ) {
            wp_set_object_terms( $wp_id, $event_seasons, 'sp_season', true );
        }

        return $wp_id;
    }

    /**
     * Ensure player list exists for team + season.
     */
    public function ensure_player_list( int $team_wp_id, int $season_term_id, int $league_term_id ): int|false {
        // v3.5.0: Direkte DB-Query statt WP_Query (Cache-Problem)
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bbb_team_wp_id' AND pm.meta_value = %d
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.term_id = %d
             WHERE p.post_type = 'sp_list' AND p.post_status = 'publish'
             LIMIT 1",
            $team_wp_id, $season_term_id
        ) );

        if ( $existing ) return (int) $existing;

        $team_name = get_the_title( $team_wp_id );
        $season_term = get_term( $season_term_id, 'sp_season' );
        $season_name = ( $season_term && ! is_wp_error( $season_term ) ) ? $season_term->name : '';

        $list_data = [
            'post_type'   => 'sp_list',
            'post_title'  => "{$team_name} – Kader {$season_name}",
            'post_status' => 'publish',
        ];
        if ( $this->sync_user_id ) {
            $list_data['post_author'] = $this->sync_user_id;
        }
        $list_id = wp_insert_post( $list_data );

        if ( is_wp_error( $list_id ) || ! $list_id ) return false;

        update_post_meta( $list_id, '_bbb_team_wp_id', $team_wp_id );
        update_post_meta( $list_id, 'sp_team', $team_wp_id );

        wp_set_object_terms( $list_id, $season_term_id, 'sp_season', false );
        if ( $league_term_id ) {
            wp_set_object_terms( $list_id, $league_term_id, 'sp_league', false );
        }

        $this->log( "Spielerliste: '{$team_name} – Kader {$season_name}' (#{$list_id})" );
        return $list_id;
    }

    /**
     * Add player to player list.
     */
    public function add_player_to_list( int $list_id, int $player_wp_id ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = 'sp_player' AND meta_value = %d",
            $list_id, $player_wp_id
        ) );
        if ( ! $exists ) {
            add_post_meta( $list_id, 'sp_player', $player_wp_id );
        }
    }

    // ─────────────────────────────────────────
    // FINDERS (v3.5.0: Direct DB queries)
    // ─────────────────────────────────────────

    private function find_by_person_id( int $person_id ): int|false {
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

    private function find_by_name( string $full_name ): int|false {
        $normalized = mb_strtolower( trim( $full_name ) );
        $query = new WP_Query([
            'post_type' => 'sp_player', 'post_status' => 'any', 'posts_per_page' => -1,
            'meta_query' => [[ 'key' => '_bbb_person_id', 'compare' => 'NOT EXISTS' ]],
            'fields' => 'ids', 'no_found_rows' => true,
        ]);
        foreach ( $query->posts as $post_id ) {
            if ( mb_strtolower( trim( get_the_title( $post_id ) ) ) === $normalized ) return $post_id;
        }
        return false;
    }

    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( "[BBB-Player] {$message}" );
        $logs   = get_option( 'bbb_sync_logs', [] );
        $logs[] = [ 'time' => current_time( 'mysql' ), 'level' => 'info', 'message' => "[Player] {$message}" ];
        $logs   = array_slice( $logs, -200 );
        update_option( 'bbb_sync_logs', $logs, false );
    }

    public function get_stats(): array {
        return [
            'created' => $this->players_created,
            'updated' => $this->players_updated,
            'skipped' => $this->players_skipped,
        ];
    }
}
