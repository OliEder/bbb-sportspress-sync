<?php
/**
 * GitHub Release Update Checker
 *
 * Prüft ob auf GitHub eine neuere Plugin-Version verfügbar ist
 * und integriert sich in das WordPress-Update-System.
 *
 * Funktionsweise:
 *   1. Hook in site_transient_update_plugins → prüft GitHub Releases API
 *   2. Vergleicht GitHub-Tag (z.B. v1.5.1) mit lokaler Plugin-Version
 *   3. Zeigt Update-Hinweis im Dashboard + Plugin-Liste
 *   4. Download-Link zeigt auf die Zip-Datei aus dem GitHub Release
 *
 * Cache: 12h via Transient (reduziert API-Calls, GitHub Rate Limit = 60/h ohne Auth)
 *
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_GitHub_Updater' ) ) return;

class BBB_GitHub_Updater {

    private string $plugin_file;     // z.B. 'bbb-live-tables/bbb-live-tables.php'
    private string $plugin_slug;     // z.B. 'bbb-live-tables'
    private string $github_owner;    // z.B. 'OliEder'
    private string $github_repo;     // z.B. 'bbb-live-tables'
    private string $current_version; // z.B. '1.5.0'

    /**
     * @param string $plugin_file     Plugin-Basename (plugin_basename(__FILE__))
     * @param string $github_owner    GitHub Username/Organisation
     * @param string $github_repo     GitHub Repository-Name
     * @param string $current_version Aktuelle Plugin-Version
     */
    public function __construct( string $plugin_file, string $github_owner, string $github_repo, string $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname( $plugin_file );
        $this->github_owner    = $github_owner;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;

        add_filter( 'site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
    }

    /**
     * WordPress fragt regelmäßig nach Plugin-Updates.
     * Hier hängen wir uns ein und prüfen GitHub.
     */
    public function check_update( $transient ): mixed {
        if ( empty( $transient->checked ) ) return $transient;

        $remote = $this->get_remote_info();
        if ( ! $remote ) return $transient;

        if ( version_compare( $this->current_version, $remote['version'], '<' ) ) {
            $transient->response[ $this->plugin_file ] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $remote['version'],
                'url'         => "https://github.com/{$this->github_owner}/{$this->github_repo}",
                'package'     => $remote['download_url'],
                'icons'       => [],
                'banners'     => [],
            ];
        } else {
            // Kein Update → aus no_update melden (verhindert "Unbekanntes Plugin" Warnung)
            $transient->no_update[ $this->plugin_file ] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $this->current_version,
                'url'         => "https://github.com/{$this->github_owner}/{$this->github_repo}",
            ];
        }

        return $transient;
    }

    /**
     * Plugin-Info Dialog (Klick auf "Details ansehen" im Update-Hinweis).
     */
    public function plugin_info( $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== $this->plugin_slug ) return $result;

        $remote = $this->get_remote_info();
        if ( ! $remote ) return $result;

        return (object) [
            'name'            => $remote['name'],
            'slug'            => $this->plugin_slug,
            'version'         => $remote['version'],
            'author'          => '<a href="https://github.com/' . esc_attr( $this->github_owner ) . '">Oliver-Marcus Eder</a>',
            'homepage'        => "https://github.com/{$this->github_owner}/{$this->github_repo}",
            'download_link'   => $remote['download_url'],
            'requires'        => '6.0',
            'requires_php'    => '8.1',
            'tested'          => get_bloginfo( 'version' ),
            'last_updated'    => $remote['published_at'],
            'sections'        => [
                'description'  => $remote['description'] ?? '',
                'changelog'    => nl2br( esc_html( $remote['changelog'] ) ),
            ],
        ];
    }

    /**
     * Nach der Installation: Ordnername korrigieren.
     *
     * GitHub Zips haben oft den Ordnernamen "repo-main" oder "repo-v1.5.0".
     * WordPress erwartet aber exakt den Plugin-Slug als Ordnernamen.
     */
    public function after_install( $response, array $hook_extra, array $result ): mixed {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $result;
        }

        global $wp_filesystem;

        $install_dir = $result['destination'];
        $proper_dir  = trailingslashit( dirname( $install_dir ) ) . $this->plugin_slug;

        if ( $install_dir !== $proper_dir ) {
            $wp_filesystem->move( $install_dir, $proper_dir );
            $result['destination'] = $proper_dir;
        }

        // Plugin reaktivieren
        if ( is_plugin_active( $this->plugin_file ) ) {
            activate_plugin( $this->plugin_file );
        }

        return $result;
    }

    /**
     * GitHub Release-Info laden (mit 12h Cache).
     *
     * @return array|null { version, download_url, changelog, name, description, published_at }
     */
    private function get_remote_info(): ?array {
        $transient_key = "bbb_github_update_{$this->plugin_slug}";
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_owner,
            $this->github_repo
        );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'BBB-WordPress-Plugin/' . $this->current_version,
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Bei Fehler kurz cachen (1h) um API nicht zu spammen
            set_transient( $transient_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) return null;

        // Version aus Tag extrahieren: "v1.5.0" → "1.5.0"
        $version = ltrim( $data['tag_name'], 'v' );

        // Download-URL: Zip-Asset aus Release oder Source-Zip als Fallback
        $download_url = '';
        foreach ( $data['assets'] ?? [] as $asset ) {
            if ( str_ends_with( $asset['name'], '.zip' ) ) {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }
        if ( ! $download_url ) {
            $download_url = $data['zipball_url'] ?? '';
        }

        $info = [
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => $data['body'] ?? '',
            'name'         => $data['name'] ?? $this->github_repo,
            'description'  => $data['body'] ?? '',
            'published_at' => $data['published_at'] ?? '',
        ];

        // 12h cachen
        set_transient( $transient_key, $info, 12 * HOUR_IN_SECONDS );

        return $info;
    }
}
