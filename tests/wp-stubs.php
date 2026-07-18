<?php
/**
 * Minimal WP stubs (WP_Error, is_wp_error()) for isolated unit tests.
 *
 * Must be require_once'd from bootstrap.php *after* vendor/autoload.php,
 * so that Patchwork (loaded via Brain Monkey) has already registered its
 * stream wrapper. Only functions defined in files included after that
 * point can later be redefined per-test via Brain Monkey's
 * Functions\when()/expect() — see Patchwork\Exceptions\DefinedTooEarly.
 */

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
// as a plain PHP function that works with our WP_Error stub. Individual
// tests may override it per-test via Functions\when('is_wp_error').
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
    }
}
