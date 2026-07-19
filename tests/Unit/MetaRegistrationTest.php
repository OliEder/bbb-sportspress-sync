<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests für BBB_Meta_Registration (REST-Sichtbarkeit von _bbb_*-Meta-Feldern, v1.1.10).
 *
 * register_post_meta()/register_term_meta() existieren nicht in wp-stubs.php
 * (dort werden nur die von der Plugin-Logik selbst benötigten WP-Funktionen
 * gestubbt), daher werden sie hier gezielt gemockt, um die an sie
 * übergebenen Argumente pro (Post Type/Taxonomy, Meta-Key)-Paar einzusammeln
 * und zu prüfen.
 */
final class MetaRegistrationTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_registration(): \BBB_Meta_Registration {
        $reflection = new ReflectionClass( \BBB_Meta_Registration::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Ruft register_meta() auf und sammelt alle register_post_meta()- bzw.
     * register_term_meta()-Aufrufe, geschlüsselt als "post:<post_type>:<meta_key>"
     * bzw. "term:<taxonomy>:<meta_key>".
     */
    private function register_and_capture(): array {
        $captured = [];

        Functions\expect( 'register_post_meta' )
            ->times( 6 )
            ->andReturnUsing(
                function ( string $post_type, string $meta_key, array $args ) use ( &$captured ) {
                    $captured[ "post:{$post_type}:{$meta_key}" ] = $args;
                    return true;
                }
            );

        Functions\expect( 'register_term_meta' )
            ->times( 2 )
            ->andReturnUsing(
                function ( string $taxonomy, string $meta_key, array $args ) use ( &$captured ) {
                    $captured[ "term:{$taxonomy}:{$meta_key}" ] = $args;
                    return true;
                }
            );

        $this->make_registration()->register_meta();

        return $captured;
    }

    public function test_registers_all_eight_expected_pairs(): void {
        $captured = $this->register_and_capture();

        $expected_keys = [
            'post:sp_team:_bbb_team_permanent_id',
            'post:sp_event:_bbb_match_id',
            'post:sp_event:_bbb_liga_id',
            'post:sp_player:_bbb_person_id',
            'post:sp_list:_bbb_team_wp_id',
            'post:sp_table:_bbb_liga_id',
            'term:sp_league:_bbb_liga_id',
            'term:sp_venue:_bbb_spielfeld_id',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $captured );
        }
        $this->assertCount( 8, $captured );
    }

    public function test_all_pairs_share_common_schema_args(): void {
        $captured = $this->register_and_capture();

        foreach ( $captured as $key => $args ) {
            $this->assertSame( 'integer', $args['type'], "type mismatch for {$key}" );
            $this->assertTrue( $args['single'], "single mismatch for {$key}" );
            $this->assertTrue( $args['show_in_rest'], "show_in_rest mismatch for {$key}" );
            $this->assertArrayHasKey( 'auth_callback', $args );
            $this->assertIsCallable( $args['auth_callback'] );
        }
    }

    public function test_post_meta_auth_callback_checks_edit_posts(): void {
        $captured = $this->register_and_capture();

        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'edit_posts' )
            ->andReturn( true );

        $this->assertTrue( ( $captured['post:sp_team:_bbb_team_permanent_id']['auth_callback'] )() );
    }

    public function test_term_meta_auth_callback_checks_manage_options(): void {
        $captured = $this->register_and_capture();

        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( false );

        $this->assertFalse( ( $captured['term:sp_league:_bbb_liga_id']['auth_callback'] )() );
    }
}
