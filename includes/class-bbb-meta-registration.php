<?php
/**
 * BBB Meta REST Registration.
 *
 * Registriert die BBB-Sync-Meta-Felder (`_bbb_*`) für die REST API. Ohne
 * register_post_meta()/register_term_meta() liefert die REST API für diese
 * Felder "rest_no_route", da alle mit führendem Unterstrich beginnenden
 * Meta-Keys von WordPress als "protected meta" behandelt werden – protected
 * Meta bleibt unsichtbar, selbst mit show_in_rest => true, solange keine
 * explizite Registrierung inkl. auth_callback vorliegt.
 *
 * Betroffene Felder: siehe Datenmodell-Mapping in
 * docs/arc42-bbb-sportspress-sync.adoc, Abschnitt "Datenmodell-Mapping BBB →
 * SportsPress".
 *
 * Hinweis: sp_team, sp_event, sp_player, sp_list, sp_table (Post Types) sowie
 * sp_league, sp_venue (Taxonomies) werden nicht von diesem Plugin, sondern von
 * SportsPress selbst registriert. Ob diese Post Types/Taxonomies ihrerseits
 * show_in_rest => true gesetzt haben (Voraussetzung für eine REST-Route
 * überhaupt), liegt außerhalb dieses Plugins und ist hier nicht geprüft –
 * ggf. auf der Live-Site via GET /wp-json/wp/v2/types bzw.
 * /wp-json/wp/v2/taxonomies verifizieren.
 *
 * @since 1.1.10
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_Meta_Registration' ) ) {
    return;
}

class BBB_Meta_Registration {

    public function __construct() {
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    public function register_meta(): void {
        $post_meta_args = [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static function (): bool {
                return current_user_can( 'edit_posts' );
            },
        ];

        $term_meta_args = [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static function (): bool {
                return current_user_can( 'manage_options' );
            },
        ];

        $post_meta = [
            'sp_team'   => [
                '_bbb_team_permanent_id' => 'BBB Team Permanent-ID (Primary Key)',
            ],
            'sp_event'  => [
                '_bbb_match_id' => 'BBB Match-ID (Primary Key)',
                '_bbb_liga_id'  => 'BBB Liga-ID des Spiels',
            ],
            'sp_player' => [
                '_bbb_person_id' => 'BBB Person-ID (Primary Key)',
            ],
            'sp_list'   => [
                '_bbb_team_wp_id' => 'SP Team Post-ID der zugehörigen Spielerliste',
            ],
            'sp_table'  => [
                '_bbb_liga_id' => 'BBB Liga-ID der Tabelle (Primary Key)',
            ],
        ];

        foreach ( $post_meta as $post_type => $fields ) {
            foreach ( $fields as $meta_key => $description ) {
                register_post_meta(
                    $post_type,
                    $meta_key,
                    array_merge( $post_meta_args, [ 'description' => $description ] )
                );
            }
        }

        $term_meta = [
            'sp_league' => [
                '_bbb_liga_id' => 'BBB Liga-ID (Primary Key)',
            ],
            'sp_venue'  => [
                '_bbb_spielfeld_id' => 'BBB Spielfeld-ID (Primary Key)',
            ],
        ];

        foreach ( $term_meta as $taxonomy => $fields ) {
            foreach ( $fields as $meta_key => $description ) {
                register_term_meta(
                    $taxonomy,
                    $meta_key,
                    array_merge( $term_meta_args, [ 'description' => $description ] )
                );
            }
        }
    }
}
