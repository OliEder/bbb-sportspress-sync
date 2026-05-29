<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BBB_Admin_Page sanitization callbacks.
 *
 * These are WordPress settings API callbacks: they read directly from $_POST
 * and return a sanitized value. No nonce check needed here – options.php owns
 * that responsibility.
 */
class AdminSanitizeTest extends TestCase {

    private BBB_Admin_Page $page;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub all WP hook-registration functions called in the constructor.
        Functions\when( 'add_action' )->justReturn( null );
        Functions\when( 'add_filter' )->justReturn( null );

        // Stub WP helper functions used inside the sanitize methods.
        Functions\when( 'wp_unslash' )->alias( fn( $v ) => $v );
        Functions\when( 'sanitize_key' )->alias( function ( string $key ): string {
            return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => (string) json_encode( $v ) );

        $this->page = new BBB_Admin_Page();
    }

    protected function tearDown(): void {
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── sanitize_result_slugs ─────────────────────────────────────────────────

    public function test_sanitize_result_slugs_joins_with_comma(): void {
        $_POST['bbb_sync_result_slugs_arr'] = [ 'pts', 'reb', 'ast' ];

        $this->assertEquals( 'pts,reb,ast', $this->page->sanitize_result_slugs( '' ) );
    }

    public function test_sanitize_result_slugs_filters_empty_entries(): void {
        $_POST['bbb_sync_result_slugs_arr'] = [ 'pts', '', 'reb' ];

        $this->assertEquals( 'pts,reb', $this->page->sanitize_result_slugs( '' ) );
    }

    public function test_sanitize_result_slugs_sanitizes_slug_values(): void {
        $_POST['bbb_sync_result_slugs_arr'] = [ 'MY_STAT', 'reB' ];

        // sanitize_key lowercases and strips chars not in [a-z0-9_-]
        $this->assertEquals( 'my_stat,reb', $this->page->sanitize_result_slugs( '' ) );
    }

    public function test_sanitize_result_slugs_returns_empty_for_non_array(): void {
        $_POST['bbb_sync_result_slugs_arr'] = 'not-an-array';

        $this->assertEquals( '', $this->page->sanitize_result_slugs( '' ) );
    }

    public function test_sanitize_result_slugs_returns_empty_when_missing(): void {
        unset( $_POST['bbb_sync_result_slugs_arr'] );

        $this->assertEquals( '', $this->page->sanitize_result_slugs( '' ) );
    }

    // ── sanitize_stat_mapping ─────────────────────────────────────────────────

    public function test_sanitize_stat_mapping_returns_json_string(): void {
        $_POST['bbb_sync_stat_map'] = [ 'pts' => 'pts', 'ro' => 'reb' ];

        $result  = $this->page->sanitize_stat_mapping( '' );
        $decoded = json_decode( $result, true );

        $this->assertIsArray( $decoded );
        $this->assertEquals( 'pts', $decoded['pts'] );
        $this->assertEquals( 'reb', $decoded['ro'] );
    }

    public function test_sanitize_stat_mapping_skips_empty_value_entries(): void {
        $_POST['bbb_sync_stat_map'] = [ 'pts' => 'pts', 'ro' => '' ];

        $decoded = json_decode( $this->page->sanitize_stat_mapping( '' ), true );

        $this->assertArrayHasKey( 'pts', $decoded );
        $this->assertArrayNotHasKey( 'ro', $decoded );
    }

    public function test_sanitize_stat_mapping_returns_empty_for_non_array(): void {
        $_POST['bbb_sync_stat_map'] = 'invalid';

        $this->assertEquals( '', $this->page->sanitize_stat_mapping( '' ) );
    }

    public function test_sanitize_stat_mapping_returns_empty_for_all_invalid_entries(): void {
        $_POST['bbb_sync_stat_map'] = [ '' => 'pts', 'ro' => '' ];

        $this->assertEquals( '', $this->page->sanitize_stat_mapping( '' ) );
    }
}
