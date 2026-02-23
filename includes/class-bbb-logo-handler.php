<?php
/**
 * BBB Logo Handler
 *
 * Lädt Club-Logos von der BBB API und speichert sie als WordPress Media.
 * Setzt das Logo als Featured Image auf alle sp_team Posts des Clubs.
 *
 * Logik: Ein Logo pro Verein (clubId), nicht pro Team.
 * Der API-Endpoint ist team-basiert (/media/team/{permanentId}/logo),
 * aber das Logo ist identisch für alle Teams eines Clubs.
 * → Erstes Team eines Clubs liefert das Logo, alle anderen bekommen dasselbe.
 *
 * Cache: 6 Monate per Option bbb_club_logo_{clubId}.
 */

defined( 'ABSPATH' ) || exit;

class BBB_Logo_Handler {

    private BBB_Api_Client $api;

    /**
     * Runtime-Cache: clubId → attachment_id
     * Verhindert doppelte API-Calls innerhalb eines Sync-Laufs.
     */
    private array $club_logo_cache = [];

    public function __construct( BBB_Api_Client $api ) {
        $this->api = $api;
    }

    /**
     * Ensure team has the club logo as featured image.
     *
     * Strategie:
     * 1. Prüfe ob clubId schon ein Logo hat (Option oder Runtime-Cache)
     * 2. Wenn ja → attachment_id wiederverwenden
     * 3. Wenn nein → Logo vom API laden, speichern, cachen
     * 4. Featured Image setzen
     *
     * @param int $team_wp_id        SP team post ID
     * @param int $team_permanent_id BBB permanent team ID (für API-Call)
     * @param int $club_id           BBB club ID (für Caching)
     * @return int|false Attachment ID or false
     */
    public function maybe_sync_logo( int $team_wp_id, int $team_permanent_id, int $club_id ): int|false {
        if ( ! $club_id || ! $team_permanent_id ) {
            return false;
        }

        // ── 1. Runtime-Cache (innerhalb eines Sync-Laufs) ──
        if ( isset( $this->club_logo_cache[ $club_id ] ) ) {
            $attachment_id = $this->club_logo_cache[ $club_id ];
            set_post_thumbnail( $team_wp_id, $attachment_id );
            return $attachment_id;
        }

        // ── 2. Persistent Cache (6 Monate) ──
        $option_key    = "bbb_club_logo_{$club_id}";
        $cached        = get_option( $option_key, [] );
        $cache_duration = 6 * MONTH_IN_SECONDS;

        if (
            ! empty( $cached['attachment_id'] )
            && ! empty( $cached['fetched_at'] )
            && ( time() - (int) $cached['fetched_at'] ) < $cache_duration
        ) {
            // Prüfe ob Attachment noch existiert
            if ( wp_attachment_is_image( $cached['attachment_id'] ) ) {
                $this->club_logo_cache[ $club_id ] = $cached['attachment_id'];
                set_post_thumbnail( $team_wp_id, $cached['attachment_id'] );
                return $cached['attachment_id'];
            }
            // Attachment gelöscht → Cache invalidieren
        }

        // ── 3. Logo von API laden ──
        $png_data = $this->api->get_team_logo( $team_permanent_id );

        if ( is_wp_error( $png_data ) || empty( $png_data ) ) {
            return false;
        }

        $this->api->throttle();

        // ── 4. In Media Library speichern ──
        $filename      = "bbb-club-{$club_id}.png";
        $attachment_id = $this->save_to_media_library( $png_data, $filename, $team_wp_id );

        if ( ! $attachment_id ) {
            return false;
        }

        // ── 5. Cachen ──
        update_option( $option_key, [
            'attachment_id' => $attachment_id,
            'fetched_at'    => time(),
            'source_team'   => $team_permanent_id,
        ], false );

        $this->club_logo_cache[ $club_id ] = $attachment_id;

        // ── 6. Featured Image setzen ──
        set_post_thumbnail( $team_wp_id, $attachment_id );

        return $attachment_id;
    }

    /**
     * Save raw PNG data to WordPress media library.
     * Updates existing file if attachment with same name exists.
     */
    private function save_to_media_library( string $png_data, string $filename, int $parent_id ): int|false {
        // Check if attachment already exists (avoid duplicates)
        $existing = $this->find_existing_attachment( $filename );
        if ( $existing ) {
            $filepath = get_attached_file( $existing );
            if ( $filepath ) {
                file_put_contents( $filepath, $png_data );
                // Regenerate thumbnails
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $metadata = wp_generate_attachment_metadata( $existing, $filepath );
                wp_update_attachment_metadata( $existing, $metadata );
                return $existing;
            }
        }

        // Upload directory
        $upload_dir = wp_upload_dir();
        $filepath   = $upload_dir['path'] . '/' . $filename;

        if ( file_put_contents( $filepath, $png_data ) === false ) {
            return false;
        }

        $attachment = [
            'post_mime_type' => 'image/png',
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment( $attachment, $filepath, $parent_id );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        return $attachment_id;
    }

    /**
     * Find existing attachment by filename.
     */
    private function find_existing_attachment( string $filename ): int|false {
        $title = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );

        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'title'          => $title,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return $query->have_posts() ? $query->posts[0] : false;
    }
}
