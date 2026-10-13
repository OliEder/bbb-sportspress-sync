<?php
/**
 * BBB SportsPress-native Meta REST Registration.
 *
 * Registriert die von SportsPress selbst definierten, aber von diesem Plugin
 * geschriebenen Meta-Felder (`sp_*`, ohne Unterstrich-Prefix) für die REST API.
 * Seit WP 4.7 muss jedes Meta-Feld – unabhängig vom Unterstrich-Prefix –
 * explizit per register_post_meta()/register_term_meta() registriert werden,
 * um im REST-`meta`-Objekt zu erscheinen. Ohne diese Registrierung bleiben
 * z.B. Spielergebnisse (`sp_results`) oder Team-Zuordnungen (`sp_team`) für
 * externe REST-Konsumenten unsichtbar, obwohl SportsPress selbst diese Felder
 * im Backend/Frontend anzeigt.
 *
 * Ergänzt `BBB_Meta_Registration` (registriert die plugineigenen `_bbb_*`-IDs);
 * diese Klasse kümmert sich ausschließlich um die nativen SportsPress-Felder,
 * die dieses Plugin beim Sync befüllt (Team-Zuordnung, Ergebnisse,
 * Spieler-Performance, Team-Stammdaten).
 *
 * Bewusst NICHT enthalten: Venue-Adress-/Geo-Felder (`sp_address`,
 * `sp_latitude`, `sp_longitude`) – SportsPress speichert diese überwiegend
 * über eine WP-Option `taxonomy_{term_id}`, nicht als echtes Term-Meta;
 * register_term_meta() kann Options-Daten nicht erfassen. Ebenfalls nicht
 * enthalten: `sp_format`/`sp_precision` auf `sp_result`-Posts (Konfiguration
 * der Ergebnis-Spalten selbst, keine für externe Konsumenten relevanten
 * Spieldaten).
 *
 * Bekannte Einschränkung: `sp_players` und `sp_results` werden als `object`-
 * Typ ohne festes Schema registriert (Sub-Struktur ist dynamisch, abhängig
 * von der jeweiligen Liga-/Performance-Konfiguration). WordPress' REST-Meta-
 * Mechanismus ist für das Lesen solcher Felder vorgesehen (bisher nicht gegen
 * eine Live-Instanz verifiziert), verlangt aber beim Schreiben
 * über REST i.d.R. ein Schema mit `properties` zur Validierung – ein
 * Schreibversuch über REST kann für genau diese zwei Felder daher von WP
 * abgelehnt werden. Das ist eine bekannte WP-Einschränkung bei dynamischen
 * Strukturen, kein Bug dieser Klasse.
 *
 * @since 1.1.11
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_SportsPress_Meta_Registration' ) ) {
    return;
}

class BBB_SportsPress_Meta_Registration {

    public function __construct() {
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    public function register_meta(): void {
        $auth_callback = static function (): bool {
            return current_user_can( 'edit_posts' );
        };

        $event_fields = [
            'sp_team'        => [
                'type'        => 'integer',
                'single'      => false,
                'description' => 'Home-/Away-Team-Zuordnung (SP Team Post-IDs)',
            ],
            'sp_player'      => [
                'type'        => 'integer',
                'single'      => false,
                'description' => 'Im Spiel eingesetzte Spieler (SP Player Post-IDs)',
            ],
            'sp_players'     => [
                'type'        => 'object',
                'single'      => true,
                'description' => 'Spieler-Performance-Stats je Team/Spieler (dynamische Stat-Spalten)',
            ],
            'sp_results'     => [
                'type'        => 'object',
                'single'      => true,
                'description' => 'Ergebnis je Team/Ergebnis-Spalte',
            ],
            'sp_status'      => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Spielstatus (z.B. scheduled, results)',
            ],
            'sp_main_result' => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Slug der Haupt-Ergebnis-Spalte (z.B. pts, t) – Schlüssel in sp_results',
            ],
            'sp_day'         => [
                'type'        => 'integer',
                'single'      => true,
                'description' => 'Spieltag',
            ],
            'sp_format'      => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Format der Ergebnis-Erfassung (z.B. league)',
            ],
        ];

        $team_fields = [
            'sp_abbreviation' => [
                'type'        => 'string',
                'single'      => true,
                'description' => '3-stelliges Team-Kürzel',
            ],
            'sp_short_name'   => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Team-Kurzname',
            ],
            'sp_url'          => [
                'type'        => 'string',
                'single'      => true,
                'description' => 'Team-Homepage-URL',
            ],
        ];

        foreach ( $event_fields as $meta_key => $args ) {
            register_post_meta(
                'sp_event',
                $meta_key,
                array_merge(
                    $args,
                    [
                        'show_in_rest'  => true,
                        'auth_callback' => $auth_callback,
                    ]
                )
            );
        }

        foreach ( $team_fields as $meta_key => $args ) {
            register_post_meta(
                'sp_team',
                $meta_key,
                array_merge(
                    $args,
                    [
                        'show_in_rest'  => true,
                        'auth_callback' => $auth_callback,
                    ]
                )
            );
        }
    }
}
