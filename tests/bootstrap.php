<?php
/**
 * PHPUnit bootstrap – defines WP stubs so plugin classes can be loaded
 * without a real WordPress installation.
 */

// WordPress constants used by the plugin files
define( 'ABSPATH', __DIR__ . '/' );
define( 'BBB_SYNC_VERSION', '1.1.4' );
define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
define( 'WP_DEBUG', false );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── Minimal WP_Error stub ─────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

// is_wp_error() is used throughout the plugin; Brain Monkey only stubs WP
// functions registered via Functions\when/expect, so we define it globally
// as a plain PHP function that works with our WP_Error stub.
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

// ── Load plugin classes ───────────────────────────────────────────────────────

$plugin_includes = dirname( __DIR__ ) . '/includes/';

require_once $plugin_includes . 'class-bbb-api-client.php';
require_once $plugin_includes . 'class-bbb-logo-handler.php';
require_once $plugin_includes . 'class-bbb-player-sync.php';
require_once $plugin_includes . 'class-bbb-sync-engine.php';
require_once $plugin_includes . 'class-bbb-admin-page.php';
