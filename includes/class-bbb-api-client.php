<?php
/**
 * BBB API Client v3.0
 *
 * Wrapper für die Basketball-Bund.net REST API.
 * Serverseitig – kein CORS-Proxy nötig.
 *
 * API-Basis: https://www.basketball-bund.net/rest
 * Auth: Keine (öffentliche API)
 * Rate Limit: ~1 req/sec empfohlen
 *
 * Endpoints:
 *   PRIMARY:
 *     /rest/club/id/{clubId}/actualmatches     → Team-Discovery (1-2x/Saison)
 *     /rest/team/id/{permanentId}/matches       → Haupt-Sync eigene Spiele
 *     /rest/competition/spielplan/id/{ligaId}   → ALLE Liga-Spiele (für Tabellen)
 *   SECONDARY (on-demand):
 *     /rest/match/id/{matchId}/matchInfo         → Spielfeld-Discovery
 *     /rest/match/id/{matchId}/boxscore          → Spieler-Import
 *     /media/team/{permanentId}/logo             → Team-Logo
 *     /rest/competition/table/id/{ligaId}        → Liga-Tabelle (optional)
 */

defined( 'ABSPATH' ) || exit;

class BBB_Api_Client {

    private string $base_url;
    private float $rate_limit_delay = 1.0;

    public function __construct() {
        $this->base_url = BBB_API_BASE_URL;
    }

    // ─────────────────────────────────────────
    // TEAM DISCOVERY (1-2x pro Saison)
    // ─────────────────────────────────────────

    /**
     * Alle aktuellen Matches eines Vereins laden.
     *
     * Zweck: Team-Discovery – eigene Teams identifizieren.
     * Frequenz: 1-2x pro Saison, manuell ausgelöst.
     *
     * @param int $club_id    BBB Vereins-ID (z.B. 1234)
     * @param int $range_days Zeitraum in Tagen (default: 365)
     * @return array|WP_Error { club: {...}, matches: [...] }
     */
    public function get_club_matches( int $club_id, int $range_days = 365 ): array|WP_Error {
        $response = $this->get(
            "/club/id/{$club_id}/actualmatches?justHome=false&rangeDays={$range_days}"
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'club'    => $response['data']['club'] ?? [],
            'matches' => $response['data']['matches'] ?? [],
        ];
    }

    // ─────────────────────────────────────────
    // TEAM MATCHES (Haupt-Sync, regelmäßig)
    // ─────────────────────────────────────────

    /**
     * Alle Matches eines Teams laden (via teamPermanentId).
     *
     * Zweck: Regulärer Sync – 1 Call pro eigenem Team.
     * Liefert zusätzlich Team-Metadaten: teamAkj, teamGender, teamNumber, club.
     *
     * @param int $team_permanent_id BBB Team Permanent ID
     * @return array|WP_Error { team: {...}, matches: [...] }
     */
    public function get_team_matches( int $team_permanent_id ): array|WP_Error {
        $response = $this->get( "/team/id/{$team_permanent_id}/matches" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'team'    => $response['data']['team'] ?? [],
            'matches' => $response['data']['matches'] ?? [],
        ];
    }

    // ─────────────────────────────────────────
    // MATCH DETAILS (on-demand)
    // ─────────────────────────────────────────

    /**
     * Match-Info: Spielfeld, Schiedsrichter, Viertelergebnisse.
     *
     * Zweck: Spielfeld-Discovery – nur aufrufen wenn spielfeld.id unbekannt.
     *
     * @param int $match_id BBB Match-ID
     * @return array|WP_Error
     */
    public function get_match_info( int $match_id ): array|WP_Error {
        $response = $this->get( "/match/id/{$match_id}/matchInfo" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['data'] ?? [];
    }

    /**
     * Boxscore: Spieler-Statistiken + Aufstellungen.
     *
     * Zweck: Spieler-Import – nur für beendete Spiele.
     * Achtung: Anonymisierte Spieler (U8-U14) haben playerId: 0.
     *
     * @param int $match_id BBB Match-ID
     * @return array|WP_Error
     */
    public function get_boxscore( int $match_id ): array|WP_Error {
        $response = $this->get( "/match/id/{$match_id}/boxscore" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['data'] ?? [];
    }

    // ─────────────────────────────────────────
    // ASSETS
    // ─────────────────────────────────────────

    /**
     * Team-Logo als PNG laden.
     *
     * Zweck: Featured Image für sp_team.
     * Cache: 6 Monate.
     *
     * @param int $team_permanent_id BBB Team Permanent ID
     * @return string|WP_Error Raw PNG binary data
     */
    public function get_team_logo( int $team_permanent_id ): string|WP_Error {
        $url = "https://www.basketball-bund.net/media/team/{$team_permanent_id}/logo";
        $this->log( "GET {$url}" );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
        ]);

        if ( is_wp_error( $response ) ) {
            $this->log( 'Logo Error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'bbb_logo_error', "HTTP {$code} für Logo Team {$team_permanent_id}" );
        }

        return wp_remote_retrieve_body( $response );
    }

    // ─────────────────────────────────────────
    // LEAGUE DATA (optional)
    // ─────────────────────────────────────────

    /**
     * Liga-Spielplan: ALLE Matches einer Liga laden.
     *
     * Zweck: Vollständige Tabelle – auch Spiele ohne eigene Beteiligung.
     * Frequenz: Bei jedem Sync, pro Liga 1 Call.
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error { liga_data: {...}, matches: [...] }
     */
    public function get_liga_spielplan( int $liga_id ): array|WP_Error {
        $response = $this->get( "/competition/spielplan/id/{$liga_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'liga_data' => $response['data']['ligaData'] ?? [],
            'matches'   => $response['data']['matches'] ?? [],
        ];
    }

    /**
     * Liga-Tabelle laden.
     *
     * Hinweis: SportsPress berechnet Tabellen automatisch aus sp_event Ergebnissen.
     * Dieser Call ist nur nötig wenn die BBB-Tabelle direkt angezeigt werden soll.
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error
     */
    public function get_tabelle( int $liga_id ): array|WP_Error {
        $response = $this->get( "/competition/table/id/{$liga_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'liga_data' => $response['data']['ligaData'] ?? [],
            'entries'   => $response['data']['tabelle']['entries'] ?? [],
        ];
    }

    // ─────────────────────────────────────────
    // COMPETITION / TOURNAMENT (KO-Wettbewerbe)
    // ─────────────────────────────────────────

    /**
     * Liga-Spieltag laden (für KO/Pokal-Brackets).
     *
     * Liefert spieltage[] (alle Runden), matches[] (pro Runde),
     * prevSpieltag/nextSpieltag (Navigation).
     *
     * @param int $liga_id    BBB Liga-ID
     * @param int $matchday   Spieltag-Nummer (1-basiert)
     * @return array|WP_Error { liga_data, spieltage, matches, prev, next }
     */
    public function get_liga_matchday( int $liga_id, int $matchday = 1 ): array|WP_Error {
        $response = $this->get( "/competition/id/{$liga_id}/matchday/{$matchday}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $response['data'] ?? [];

        return [
            'liga_data'  => $data['ligaData'] ?? [],
            'spieltage'  => $data['spieltage'] ?? [],
            'matches'    => $data['matches'] ?? [],
            'prev'       => $data['prevSpieltag'] ?? null,
            'next'       => $data['nextSpieltag'] ?? null,
        ];
    }

    /**
     * Alle Spieltage einer KO-Liga laden (iteriert über Matchdays).
     *
     * Holt Spieltag 1, prüft ob weitere existieren, und lädt alle.
     * Ergebnis: Komplette Tournament-Struktur mit allen Runden.
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error { liga_data, rounds: [ spieltag => { name, matches[] } ] }
     */
    public function get_tournament_rounds( int $liga_id ): array|WP_Error {
        // Lade Spieltag 1 → enthält spieltage[] mit allen verfügbaren Runden
        $first = $this->get_liga_matchday( $liga_id, 1 );

        if ( is_wp_error( $first ) ) {
            return $first;
        }

        $spieltage = $first['spieltage'] ?? [];
        $liga_data = $first['liga_data'] ?? [];

        $rounds = [
            1 => [
                'name'    => $spieltage[0]['bezeichnung'] ?? '1. Runde',
                'matches' => $first['matches'] ?? [],
            ],
        ];

        // Weitere Runden laden wenn vorhanden
        foreach ( $spieltage as $st ) {
            $nr = (int) ( $st['spieltag'] ?? 0 );
            if ( $nr <= 1 || $nr === 0 ) continue;

            $this->throttle();
            $round = $this->get_liga_matchday( $liga_id, $nr );

            if ( is_wp_error( $round ) ) continue;

            $rounds[ $nr ] = [
                'name'    => $st['bezeichnung'] ?? "{$nr}. Runde",
                'matches' => $round['matches'] ?? [],
            ];
        }

        ksort( $rounds );

        return [
            'liga_data' => $liga_data,
            'rounds'    => $rounds,
        ];
    }

    // ─────────────────────────────────────────
    // HTTP METHODS
    // ─────────────────────────────────────────

    private function get( string $endpoint ): array|WP_Error {
        $url = $this->base_url . $endpoint;
        $this->log( "GET {$url}" );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Accept' => 'application/json' ],
        ]);

        return $this->handle_response( $response );
    }

    private function handle_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            $this->log( 'HTTP Error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $this->log( "HTTP {$code}: {$body}", 'error' );
            return new WP_Error( 'bbb_api_http_error', "HTTP {$code}", [ 'status' => $code ] );
        }

        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'JSON Parse Error: ' . json_last_error_msg(), 'error' );
            return new WP_Error( 'bbb_api_json_error', json_last_error_msg() );
        }

        // BBB API: status "0" = success
        if ( ( $data['status'] ?? '1' ) !== '0' ) {
            $message = $data['message'] ?? 'Unknown API error';
            $this->log( "API Error: {$message}", 'error' );
            return new WP_Error( 'bbb_api_error', $message );
        }

        return $data;
    }

    /**
     * Rate limit throttle. Public for sync engine.
     */
    public function throttle(): void {
        usleep( (int) ( $this->rate_limit_delay * 1_000_000 ) );
    }

    private function log( string $message, string $level = 'info' ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[BBB-API][{$level}] {$message}" );
        }

        $logs   = get_option( 'bbb_sync_logs', [] );
        $logs[] = [
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
        ];
        $logs = array_slice( $logs, -200 );
        update_option( 'bbb_sync_logs', $logs, false );
    }
}
