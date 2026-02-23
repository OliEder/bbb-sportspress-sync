<?php
/**
 * BBB Tournament Bracket – Goodlayers Page Builder Element
 *
 * Registriert ein Goodlayers/BigSlam Page Builder Element für das Turnier-Bracket.
 * Funktioniert mit gdlr_core Framework (BigSlam, flavsuspended, Flavor Theme etc.)
 *
 * Das Element nutzt die bestehende Shortcode-Logik von BBB_Tournament_Bracket.
 * Settings werden im visuellen Page Builder konfiguriert.
 *
 * @since 3.6.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Goodlayers_Bracket {

    public function __construct() {
        // Filter + Shortcodes immer registrieren.
        // Wenn gdlr_core nicht aktiv ist, wird der Filter nie aufgerufen → kein Overhead.
        add_filter( 'gdlr_core_page_builder_module', [ $this, 'register_element' ] );
        add_filter( 'gdlr_core_page_builder_module', [ $this, 'register_table_element' ] );
        add_action( 'init', [ $this, 'register_shortcode' ] );
        add_action( 'init', [ $this, 'register_table_shortcode' ] );
    }

    /**
     * Page Builder Element registrieren.
     *
     * Definiert das Element mit allen Einstellungsfeldern für den visuellen Editor.
     * Nutzt gdlr_core Feldtypen: text, combobox, select, checkbox, etc.
     */
    public function register_element( array $modules ): array {
        $modules['bbb-bracket'] = [
            'name'     => esc_html__( 'BBB Turnier-Bracket', 'bbb-sportspress-sync' ),
            'category' => esc_html__( 'Sport', 'bbb-sportspress-sync' ),
            'icon'     => 'fa-trophy',
            'options'  => $this->get_element_options(),
        ];

        return $modules;
    }

    /**
     * Element-Optionen (Settings) für den Page Builder.
     *
     * Stellt die gleichen Einstellungen wie der Gutenberg-Block bereit:
     * Liga-Auswahl, Modus, Darstellung, Erweitert.
     */
    private function get_element_options(): array {
        // Liga-Optionen dynamisch laden
        $liga_options = $this->get_liga_options();

        return [

            // ── Datenquelle ──
            'liga-source' => [
                'title' => esc_html__( 'Liga-Auswahl', 'bbb-sportspress-sync' ),
                'type'  => 'combobox',
                'options' => $liga_options,
                'description' => esc_html__( 'Turnier/Pokal aus den gesyncten Ligen wählen. Für manuelle Eingabe "Eigene Liga-ID" wählen.', 'bbb-sportspress-sync' ),
            ],
            'liga-id' => [
                'title'     => esc_html__( 'Liga-ID (manuell)', 'bbb-sportspress-sync' ),
                'type'      => 'text',
                'default'   => '',
                'condition' => [ 'liga-source' => 'custom' ],
                'description' => esc_html__( 'BBB Liga-ID des Turniers (z.B. 47976).', 'bbb-sportspress-sync' ),
            ],
            'title' => [
                'title'   => esc_html__( 'Titel', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '',
                'description' => esc_html__( 'Überschreibt den automatischen Titel. Leer = Liga-Name aus API.', 'bbb-sportspress-sync' ),
            ],

            // ── Turnier-Modus ──
            'mode' => [
                'title'   => esc_html__( 'Turnier-Modus', 'bbb-sportspress-sync' ),
                'type'    => 'combobox',
                'options' => [
                    'ko'      => esc_html__( 'KO (Single Elimination)', 'bbb-sportspress-sync' ),
                    'playoff' => esc_html__( 'Playoff (Best-of-N)', 'bbb-sportspress-sync' ),
                ],
                'default' => 'ko',
            ],
            'best-of' => [
                'title'     => esc_html__( 'Best of', 'bbb-sportspress-sync' ),
                'type'      => 'combobox',
                'options'   => [
                    '3' => 'Best of 3 (2 Siege)',
                    '5' => 'Best of 5 (3 Siege)',
                    '7' => 'Best of 7 (4 Siege)',
                ],
                'default'   => '5',
                'condition' => [ 'mode' => 'playoff' ],
            ],

            // ── Darstellung ──
            'show-dates' => [
                'title'   => esc_html__( 'Spieldaten anzeigen', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'enable',
            ],
            'show-logos' => [
                'title'   => esc_html__( 'Team-Logos anzeigen', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'enable',
            ],

            // ── Erweitert ──
            'highlight-own' => [
                'title'   => esc_html__( 'Eigenes Team hervorheben', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'enable',
            ],
            'cache' => [
                'title'   => esc_html__( 'Cache-Dauer (Sekunden)', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '3600',
                'description' => esc_html__( '0 = kein Cache. Standard: 3600 (1 Stunde).', 'bbb-sportspress-sync' ),
            ],
        ];
    }

    /**
     * Liga-Optionen für das Dropdown aufbereiten.
     *
     * Liest gesyncte Ligen (ohne Tabelle = Turniere) aus sp_league Terms.
     * Fügt "Eigene Liga-ID" Option für manuelle Eingabe hinzu.
     */
    private function get_liga_options(): array {
        $options = [
            '' => esc_html__( '— Turnier wählen —', 'bbb-sportspress-sync' ),
        ];

        // Ligen ohne Tabelle = Turniere/Pokale
        $terms = get_terms( [
            'taxonomy'   => 'sp_league',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'meta_query' => [
                [
                    'key'     => '_bbb_liga_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            $options['custom'] = esc_html__( 'Eigene Liga-ID eingeben', 'bbb-sportspress-sync' );
            return $options;
        }

        // sp_table Liga-IDs sammeln
        global $wpdb;
        $table_liga_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_table'
             AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_bbb_liga_id'"
        );
        $table_liga_ids = array_map( 'intval', $table_liga_ids );

        foreach ( $terms as $term ) {
            $liga_id = (int) get_term_meta( $term->term_id, '_bbb_liga_id', true );
            if ( ! $liga_id ) continue;

            // Nur Turniere (ohne Tabelle)
            if ( in_array( $liga_id, $table_liga_ids, true ) ) continue;

            $ak_name    = get_term_meta( $term->term_id, '_bbb_ak_name', true ) ?: '';
            $geschlecht = get_term_meta( $term->term_id, '_bbb_geschlecht', true ) ?: '';

            $label = $term->name;
            if ( $ak_name && $ak_name !== 'Senioren' ) {
                $suffix = $ak_name;
                if ( $geschlecht ) $suffix .= ' ' . $geschlecht;
                $label = sprintf( '%s (%s)', $term->name, $suffix );
            }

            $options[ (string) $liga_id ] = $label;
        }

        $options['custom'] = esc_html__( '✏️ Eigene Liga-ID eingeben', 'bbb-sportspress-sync' );

        return $options;
    }

    /**
     * Shortcode für Goodlayers registrieren.
     *
     * Goodlayers rendert Page Builder Elemente als Shortcodes:
     *   [gdlr_core_bbb_bracket liga-source="47976" mode="ko" ...]
     */
    public function register_shortcode(): void {
        add_shortcode( 'gdlr_core_bbb_bracket', [ $this, 'render_shortcode' ] );
    }

    /**
     * Goodlayers-Shortcode rendern.
     *
     * Mappt Goodlayers-Attribute auf BBB Bracket Shortcode-Parameter.
     */
    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga-source'    => '',
            'liga-id'        => '',
            'title'          => '',
            'mode'           => 'ko',
            'best-of'        => '5',
            'show-dates'     => 'enable',
            'show-logos'     => 'enable',
            'highlight-own'  => 'enable',
            'cache'          => '3600',
        ], $atts, 'gdlr_core_bbb_bracket' );

        // Liga-ID bestimmen: Dropdown-Wert oder manuelle Eingabe
        $liga_id = 0;
        if ( $atts['liga-source'] === 'custom' ) {
            $liga_id = (int) $atts['liga-id'];
        } elseif ( is_numeric( $atts['liga-source'] ) ) {
            $liga_id = (int) $atts['liga-source'];
        }

        if ( ! $liga_id ) {
            return '<p class="bbb-bracket-error" role="alert">'
                 . esc_html__( 'Bitte ein Turnier auswählen oder eine Liga-ID eingeben.', 'bbb-sportspress-sync' )
                 . '</p>';
        }

        // Auf BBB Bracket Shortcode-Parameter mappen
        $bracket_atts = [
            'liga_id'        => $liga_id,
            'title'          => $atts['title'],
            'highlight_club' => $atts['highlight-own'] === 'enable' ? (int) get_option( 'bbb_sync_club_id', 0 ) : 0,
            'cache'          => (int) $atts['cache'],
            'show_dates'     => $atts['show-dates'] === 'enable' ? 'true' : 'false',
            'show_logos'     => $atts['show-logos'] === 'enable' ? 'true' : 'false',
            'mode'           => $atts['mode'],
            'best_of'        => (int) $atts['best-of'],
        ];

        // BBB Bracket rendern
        $bracket = new BBB_Tournament_Bracket();
        $output  = $bracket->render_shortcode( $bracket_atts );

        // Goodlayers Wrapper
        $wrapper_class = 'gdlr-core-bbb-bracket-item gdlr-core-item-pdlr gdlr-core-item-pdb';
        return '<div class="' . esc_attr( $wrapper_class ) . '">' . $output . '</div>';
    }

    // ═════════════════════════════════════════
    // TABELLEN-ELEMENT (LIVE, DSGVO-KONFORM)
    // ═════════════════════════════════════════

    /**
     * Tabellen Page Builder Element registrieren.
     */
    public function register_table_element( array $modules ): array {
        $modules['bbb-table'] = [
            'name'     => esc_html__( 'BBB Liga-Tabelle (Live)', 'bbb-sportspress-sync' ),
            'category' => esc_html__( 'Sport', 'bbb-sportspress-sync' ),
            'icon'     => 'fa-table',
            'options'  => $this->get_table_options(),
        ];

        return $modules;
    }

    /**
     * Tabellen-Element Optionen.
     */
    private function get_table_options(): array {
        $liga_options = $this->get_liga_options_with_table();

        return [
            'liga-source' => [
                'title'   => esc_html__( 'Liga-Auswahl', 'bbb-sportspress-sync' ),
                'type'    => 'combobox',
                'options' => $liga_options,
                'description' => esc_html__( 'Liga mit Tabelle auswählen. Daten werden live von basketball-bund.net geladen.', 'bbb-sportspress-sync' ),
            ],
            'liga-id' => [
                'title'     => esc_html__( 'Liga-ID (manuell)', 'bbb-sportspress-sync' ),
                'type'      => 'text',
                'default'   => '',
                'condition' => [ 'liga-source' => 'custom' ],
            ],
            'title' => [
                'title'   => esc_html__( 'Titel', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '',
                'description' => esc_html__( 'Leer = Liga-Name aus API.', 'bbb-sportspress-sync' ),
            ],
            'show-logos' => [
                'title'   => esc_html__( 'Team-Logos anzeigen', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'enable',
            ],
            'columns-desktop' => [
                'title'   => esc_html__( 'Spalten Desktop', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '',
                'description' => esc_html__( 'Komma-separiert, z.B.: platz,teamname,anzSpiele,s,n,koerbe,gegenkoerbe,korbdiff. Leer = Standard.', 'bbb-sportspress-sync' ),
            ],
            'columns-mobile' => [
                'title'   => esc_html__( 'Spalten Mobil', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '',
                'description' => esc_html__( 'Für schmale Bildschirme (< 600px). Leer = Standard (platz,teamname,s,n,gb,korbdiff).', 'bbb-sportspress-sync' ),
            ],
            'team-display-desktop' => [
                'title'   => esc_html__( 'Team-Anzeige Desktop', 'bbb-sportspress-sync' ),
                'type'    => 'combobox',
                'options' => [
                    'full'      => 'Logo + Name',
                    'short'     => 'Logo + Kurzname',
                    'logo'      => 'Nur Logo',
                    'nameShort' => 'Nur Kurzname',
                ],
                'default' => 'full',
            ],
            'team-display-mobile' => [
                'title'   => esc_html__( 'Team-Anzeige Mobil', 'bbb-sportspress-sync' ),
                'type'    => 'combobox',
                'options' => [
                    'full'      => 'Logo + Name',
                    'short'     => 'Logo + Kurzname',
                    'logo'      => 'Nur Logo',
                    'nameShort' => 'Nur Kurzname',
                ],
                'default' => 'short',
            ],
            'highlight-own' => [
                'title'   => esc_html__( 'Eigenes Team hervorheben', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'enable',
            ],
            'show-gb' => [
                'title'   => esc_html__( 'Games Behind (GB) anzeigen', 'bbb-sportspress-sync' ),
                'type'    => 'checkbox',
                'default' => 'disable',
                'description' => esc_html__( 'Zeigt den Rückstand zum Tabellenersten in Spielen.', 'bbb-sportspress-sync' ),
            ],
            'cache' => [
                'title'   => esc_html__( 'Cache-Dauer (Sekunden)', 'bbb-sportspress-sync' ),
                'type'    => 'text',
                'default' => '900',
                'description' => esc_html__( 'DSGVO: Daten werden nur kurzzeitig gecached, nicht dauerhaft gespeichert.', 'bbb-sportspress-sync' ),
            ],
        ];
    }

    /**
     * Liga-Optionen: Nur Ligen MIT Tabelle.
     */
    private function get_liga_options_with_table(): array {
        $options = [ '' => esc_html__( '— Liga wählen —', 'bbb-sportspress-sync' ) ];

        $terms = get_terms( [
            'taxonomy'   => 'sp_league',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'meta_query' => [
                [ 'key' => '_bbb_liga_id', 'compare' => 'EXISTS' ],
            ],
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            $options['custom'] = esc_html__( 'Eigene Liga-ID eingeben', 'bbb-sportspress-sync' );
            return $options;
        }

        // sp_table Liga-IDs
        global $wpdb;
        $table_liga_ids = array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'sp_table'
             AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_bbb_liga_id'"
        ) );

        foreach ( $terms as $term ) {
            $liga_id = (int) get_term_meta( $term->term_id, '_bbb_liga_id', true );
            if ( ! $liga_id ) continue;
            if ( ! in_array( $liga_id, $table_liga_ids, true ) ) continue; // Nur MIT Tabelle

            $ak_name    = get_term_meta( $term->term_id, '_bbb_ak_name', true ) ?: '';
            $geschlecht = get_term_meta( $term->term_id, '_bbb_geschlecht', true ) ?: '';

            $label = $term->name;
            if ( $ak_name && $ak_name !== 'Senioren' ) {
                $suffix = $ak_name;
                if ( $geschlecht ) $suffix .= ' ' . $geschlecht;
                $label = sprintf( '%s (%s)', $term->name, $suffix );
            }

            $options[ (string) $liga_id ] = $label;
        }

        $options['custom'] = esc_html__( '✏️ Eigene Liga-ID eingeben', 'bbb-sportspress-sync' );
        return $options;
    }

    /**
     * Tabellen-Shortcode registrieren.
     */
    public function register_table_shortcode(): void {
        add_shortcode( 'gdlr_core_bbb_table', [ $this, 'render_table_shortcode' ] );
    }

    /**
     * Goodlayers-Tabellen-Shortcode rendern.
     */
    public function render_table_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga-source'           => '',
            'liga-id'               => '',
            'title'                 => '',
            'show-logos'            => 'enable',
            'columns-desktop'       => '',
            'columns-mobile'        => '',
            'team-display-desktop'  => 'full',
            'team-display-mobile'   => 'short',
            'highlight-own'         => 'enable',
            'show-gb'               => 'disable',
            'cache'                 => '900',
        ], $atts, 'gdlr_core_bbb_table' );

        $liga_id = 0;
        if ( $atts['liga-source'] === 'custom' ) {
            $liga_id = (int) $atts['liga-id'];
        } elseif ( is_numeric( $atts['liga-source'] ) ) {
            $liga_id = (int) $atts['liga-source'];
        }

        if ( ! $liga_id ) {
            return '<p class="bbb-table-error" role="alert">'
                 . esc_html__( 'Bitte eine Liga auswählen oder Liga-ID eingeben.', 'bbb-sportspress-sync' )
                 . '</p>';
        }

        $table_atts = [
            'liga_id'              => $liga_id,
            'title'                => $atts['title'],
            'highlight_club'       => $atts['highlight-own'] === 'enable' ? (int) get_option( 'bbb_sync_club_id', 0 ) : 0,
            'cache'                => (int) $atts['cache'],
            'show_logos'           => $atts['show-logos'] === 'enable' ? 'true' : 'false',
            'columns_desktop'      => $atts['columns-desktop'],
            'columns_mobile'       => $atts['columns-mobile'],
            'team_display_desktop' => $atts['team-display-desktop'],
            'team_display_mobile'  => $atts['team-display-mobile'],
            'show_gb'              => $atts['show-gb'] === 'enable' ? 'true' : 'false',
        ];

        $table = new BBB_Live_Table();
        $output = $table->render_shortcode( $table_atts );

        $wrapper_class = 'gdlr-core-bbb-table-item gdlr-core-item-pdlr gdlr-core-item-pdb';
        return '<div class="' . esc_attr( $wrapper_class ) . '">' . $output . '</div>';
    }
}
