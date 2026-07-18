<?php
/**
 * GitHub Release Update Checker
 *
 * Prüft ob auf GitHub eine neuere Plugin-Version verfügbar ist
 * und integriert sich in das WordPress-Update-System.
 *
 * Cache: 12h via Transient (reduziert API-Calls, GitHub Rate Limit = 60/h ohne Auth)
 *
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BBB_GitHub_Updater' ) ) {
	return;
}

class BBB_GitHub_Updater {

    private string $plugin_file;
    private string $github_owner;
    private string $github_repo;
    private string $current_version;

    /**
     * Hinweis: Der GitHub-Repo-Name dient als kanonischer Plugin-Slug
     * (statt dirname($plugin_file)). Läuft die Installation z.B. wegen
     * eines früheren Update-Fehlers unter einem abweichenden Ordnernamen
     * (z.B. "bbb-sportspress-sync-1"), heilt after_install() den
     * Ordnernamen bei jedem weiteren Update wieder auf den korrekten
     * Slug zurück, statt sich selbst-referenziell auf den falschen
     * Namen zu verlassen.
     */
    public function __construct( string $plugin_file, string $github_owner, string $github_repo, string $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->github_owner    = $github_owner;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;

        add_filter( 'site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
    }

    public function check_update( $transient ): mixed {
        if ( empty( $transient->checked ) ) {
			return $transient;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote ) {
			return $transient;
        }

        if ( version_compare( $this->current_version, $remote['version'], '<' ) ) {
            $transient->response[ $this->plugin_file ] = (object) [
                'slug'        => $this->github_repo,
                'plugin'      => $this->plugin_file,
                'new_version' => $remote['version'],
                'url'         => "https://github.com/{$this->github_owner}/{$this->github_repo}",
                'package'     => $remote['download_url'],
                'icons'       => [],
                'banners'     => [],
            ];
        } else {
            $transient->no_update[ $this->plugin_file ] = (object) [
                'slug'        => $this->github_repo,
                'plugin'      => $this->plugin_file,
                'new_version' => $this->current_version,
                'url'         => "https://github.com/{$this->github_owner}/{$this->github_repo}",
            ];
        }

        return $transient;
    }

    public function plugin_info( $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action ) {
			return $result;
        }
        if ( ( $args->slug ?? '' ) !== $this->github_repo ) {
			return $result;
        }

        $remote = $this->get_remote_info();
        if ( ! $remote ) {
			return $result;
        }

        return (object) [
            'name'          => $remote['name'],
            'slug'          => $this->github_repo,
            'version'       => $remote['version'],
            'author'        => '<a href="https://github.com/' . esc_attr( $this->github_owner ) . '">Oliver-Marcus Eder</a>',
            'homepage'      => "https://github.com/{$this->github_owner}/{$this->github_repo}",
            'download_link' => $remote['download_url'],
            'requires'      => '6.0',
            'requires_php'  => '8.1',
            'tested'        => get_bloginfo( 'version' ),
            'last_updated'  => $remote['published_at'],
            'sections'      => [
                'description' => $remote['description'] ?? '',
                'changelog'   => nl2br( esc_html( $remote['changelog'] ) ),
            ],
        ];
    }

    public function after_install( $response, array $hook_extra, array $result ): mixed {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $result;
        }

        global $wp_filesystem;

        // Vor dem Move prüfen: active_plugins verweist noch auf den alten Pfad.
        $was_active      = is_plugin_active( $this->plugin_file );
        $install_dir     = $result['destination'];
        $proper_dir      = trailingslashit( dirname( $install_dir ) ) . $this->github_repo;
        $new_plugin_file = $this->plugin_file;

        if ( $install_dir !== $proper_dir ) {
            $wp_filesystem->move( $install_dir, $proper_dir );
            $result['destination']      = $proper_dir;
            $result['destination_name'] = $this->github_repo;
            // Ordner wurde umbenannt → Reaktivierung muss den neuen Pfad nutzen,
            // der alte $this->plugin_file existiert danach nicht mehr.
            $new_plugin_file = trailingslashit( $this->github_repo ) . basename( $this->plugin_file );
        }

        if ( $was_active ) {
            activate_plugin( $new_plugin_file );
        }

        return $result;
    }

    private function get_remote_info(): ?array {
        $transient_key = "bbb_github_update_{$this->github_repo}";
        $cached        = get_transient( $transient_key );
        if ( false !== $cached ) {
			return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_owner,
            $this->github_repo
        );

        $response = wp_remote_get(
            $url,
            [
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'BBB-WordPress-Plugin/' . $this->current_version,
				],
			]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_transient( $transient_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) {
			return null;
        }

        $version = ltrim( $data['tag_name'], 'v' );

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

        set_transient( $transient_key, $info, 12 * HOUR_IN_SECONDS );

        return $info;
    }
}
