<?php
/**
 * BBB Tables Integration
 *
 * Registriert Filter-Callbacks für bbb-live-tables Standalone-Plugin.
 * Liefert SportsPress-Daten (Logos, Team-Links, Event-Links, Farben, Liga-Optionen)
 * über die Filter-Hooks des Standalone-Plugins.
 *
 * Wird nur geladen wenn bbb-live-tables aktiv ist (BBB_TABLES_VERSION definiert).
 *
 * @since 3.6.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Tables_Integration {

    public function __construct() {
        // Team-Logo: SP Featured Image
        add_filter( 'bbb_table_team_logo_url', [ $this, 'team_logo_url' ], 10, 2 );

        // Team-Link: SP Team Permalink
        add_filter( 'bbb_table_team_url', [ $this, 'team_url' ], 10, 2 );

        // Event-Link: SP Event Permalink
        add_filter( 'bbb_table_event_url', [ $this, 'event_url' ], 10, 2 );

        // Theme-Farben: SportsPress/Themeboy
        add_filter( 'bbb_table_theme_colors', [ $this, 'theme_colors' ] );

        // Eigene Team-IDs: aus SP-Team-Posts
        add_filter( 'bbb_table_own_team_ids', [ $this, 'own_team_ids' ], 10, 2 );

        // Liga-Optionen: sp_league Taxonomy
        add_filter( 'bbb_table_liga_options', [ $this, 'liga_options' ], 10, 2 );
    }

    /**
     * Team-Logo aus SP Featured Image.
     */
    public function team_logo_url( string $url, int $team_pid ): string {
        $sp_team_id = $this->find_sp_team( $team_pid );
        if ( ! $sp_team_id ) return $url;

        $thumb_id = get_post_thumbnail_id( $sp_team_id );
        if ( ! $thumb_id ) return $url;

        $img = wp_get_attachment_image_src( $thumb_id, 'sportspress-fit-icon' );
        return $img[0] ?? $url;
    }

    /**
     * Team-Link aus SP Team Permalink.
     */
    public function team_url( string $url, int $team_pid ): string {
        $sp_team_id = $this->find_sp_team( $team_pid );
        return $sp_team_id ? get_permalink( $sp_team_id ) : $url;
    }

    /**
     * Event-Link aus SP Event Permalink.
     */
    public function event_url( string $url, int $match_id ): string {
        global $wpdb;

        $sp_event_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
             WHERE p.post_type = 'sp_event'
               AND p.post_status = 'publish'
               AND m.meta_key = '_bbb_match_id'
               AND m.meta_value = %s
             LIMIT 1",
            $match_id
        ) );

        return $sp_event_id ? get_permalink( $sp_event_id ) : $url;
    }

    /**
     * Theme-Farben aus SportsPress/Themeboy.
     */
    public function theme_colors( array $colors ): array {
        // Goodlayers Theme Options (höchste Prio)
        if ( function_exists( 'gdlr_core_get_option' ) ) {
            $gdlr = gdlr_core_get_option( 'button_background_color' );
            if ( $gdlr ) $colors['primary'] = $gdlr;
            $gdlr_link = gdlr_core_get_option( 'link_color' );
            if ( $gdlr_link ) $colors['link'] = $gdlr_link;
        }

        // SportsPress Frontend CSS Colors
        $sp = array_filter( (array) get_option( 'sportspress_frontend_css_colors', [] ) );
        if ( ! empty( $sp['primary'] ) )    $colors['primary'] = $sp['primary'];
        if ( ! empty( $sp['link'] ) )       $colors['link']    = $sp['link'];
        if ( ! empty( $sp['heading'] ) )    $colors['heading'] = $sp['heading'];

        // Themeboy Fallback
        $tb = array_filter( (array) get_option( 'themeboy', [] ) );
        if ( empty( $sp ) && ! empty( $tb ) ) {
            if ( ! empty( $tb['primary'] ) ) $colors['primary'] = $tb['primary'];
        }

        return $colors;
    }

    /**
     * Eigene Team-IDs aus SP-Team-Posts.
     */
    public function own_team_ids( array $ids, int $club_id ): array {
        global $wpdb;

        if ( ! $club_id ) return $ids;

        // Teams mit _bbb_club_id oder _bbb_is_own_team Meta
        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT m2.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id
             INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id
             WHERE p.post_type = 'sp_team'
               AND p.post_status = 'publish'
               AND m1.meta_key = '_bbb_club_id'
               AND m1.meta_value = %s
               AND m2.meta_key = '_bbb_team_permanent_id'",
            $club_id
        ) );

        return ! empty( $results ) ? array_map( 'intval', $results ) : $ids;
    }

    /**
     * Liga-Optionen aus sp_league Taxonomy.
     */
    public function liga_options( array $options, string $type_filter = '' ): array {
        global $wpdb;

        $terms = get_terms( [
            'taxonomy'   => 'sp_league',
            'hide_empty' => false,
            'meta_key'   => '_bbb_liga_id',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) return $options;

        // Liga-IDs mit sp_table sammeln (für Filterung)
        $table_liga_ids = [];
        if ( $type_filter ) {
            $rows = $wpdb->get_results(
                "SELECT DISTINCT m.meta_value
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
                 WHERE p.post_type = 'sp_table'
                   AND p.post_status = 'publish'
                   AND m.meta_key = '_bbb_liga_id'"
            );
            foreach ( $rows as $row ) {
                $table_liga_ids[] = (int) $row->meta_value;
            }
        }

        foreach ( $terms as $term ) {
            $liga_id = (int) get_term_meta( $term->term_id, '_bbb_liga_id', true );
            if ( ! $liga_id ) continue;

            // Typ-Filter anwenden
            $has_table = in_array( $liga_id, $table_liga_ids, true );
            if ( $type_filter === 'league' && ! $has_table ) continue;
            if ( $type_filter === 'tournament' && $has_table ) continue;

            $options[] = [
                'liga_id' => $liga_id,
                'label'   => $term->name,
                'slug'    => $term->slug,
            ];
        }

        return $options;
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    /**
     * SP Team Post-ID via _bbb_team_permanent_id finden.
     * Ergebnis wird pro Request gecached.
     */
    private function find_sp_team( int $team_pid ): ?int {
        static $cache = [];

        if ( isset( $cache[ $team_pid ] ) ) return $cache[ $team_pid ];

        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
             WHERE p.post_type = 'sp_team'
               AND p.post_status = 'publish'
               AND m.meta_key = '_bbb_team_permanent_id'
               AND m.meta_value = %s
             LIMIT 1",
            $team_pid
        ) );

        $cache[ $team_pid ] = $id ? (int) $id : null;
        return $cache[ $team_pid ];
    }
}
