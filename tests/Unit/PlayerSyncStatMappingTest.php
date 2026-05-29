<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for BBB_Player_Sync stat constant definitions.
 *
 * These constants drive BBB → SportsPress stat field mapping. A regression
 * here would silently break all player statistics after a sync.
 */
class PlayerSyncStatMappingTest extends TestCase {

    private ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        $this->ref = new ReflectionClass( BBB_Player_Sync::class );
    }

    // ── STAT_ALIASES ──────────────────────────────────────────────────────────

    public function test_stat_aliases_contains_all_required_basketball_stats(): void {
        $aliases  = $this->ref->getConstant( 'STAT_ALIASES' );
        $required = [ 'pts', 'ro', 'rd', 'rt', 'as', 'st', 'to', 'bs', 'fouls', 'eff', 'esz' ];

        foreach ( $required as $stat ) {
            $this->assertArrayHasKey( $stat, $aliases, "Missing required BBB stat alias: {$stat}" );
        }
    }

    public function test_stat_aliases_each_value_is_non_empty_array(): void {
        $aliases = $this->ref->getConstant( 'STAT_ALIASES' );

        foreach ( $aliases as $key => $value ) {
            $this->assertIsArray( $value, "STAT_ALIASES[{$key}] must be an array" );
            $this->assertNotEmpty( $value, "STAT_ALIASES[{$key}] must not be empty" );
        }
    }

    public function test_stat_aliases_pts_maps_to_pts(): void {
        $aliases = $this->ref->getConstant( 'STAT_ALIASES' );

        $this->assertContains( 'pts', $aliases['pts'] );
    }

    public function test_stat_aliases_esz_maps_to_minutes(): void {
        $aliases = $this->ref->getConstant( 'STAT_ALIASES' );

        // esz = Einsatzzeit (minutes played) → should map to 'min'
        $this->assertContains( 'min', $aliases['esz'] );
    }

    // ── STAT_ALIASES_NESTED ───────────────────────────────────────────────────

    public function test_stat_aliases_nested_contains_all_shot_types(): void {
        $nested   = $this->ref->getConstant( 'STAT_ALIASES_NESTED' );
        $required = [ 'wt', 'twoPoints', 'threePoints', 'onePoints' ];

        foreach ( $required as $key ) {
            $this->assertArrayHasKey( $key, $nested, "Missing nested stat group: {$key}" );
        }
    }

    public function test_stat_aliases_nested_each_has_made_and_attempted_arrays(): void {
        $nested = $this->ref->getConstant( 'STAT_ALIASES_NESTED' );

        foreach ( $nested as $key => [ $made, $attempted ] ) {
            $this->assertIsArray( $made, "{$key}[0] (made) must be array" );
            $this->assertIsArray( $attempted, "{$key}[1] (attempted) must be array" );
            $this->assertNotEmpty( $made, "{$key} made aliases must not be empty" );
            $this->assertNotEmpty( $attempted, "{$key} attempted aliases must not be empty" );
        }
    }

    public function test_stat_aliases_nested_free_throws_maps_ftm_fta(): void {
        $nested = $this->ref->getConstant( 'STAT_ALIASES_NESTED' );

        $this->assertContains( 'ftm', $nested['onePoints'][0] );
        $this->assertContains( 'fta', $nested['onePoints'][1] );
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function test_constructor_accepts_api_client(): void {
        $mock_api = $this->createMock( BBB_Api_Client::class );
        $sync     = new BBB_Player_Sync( $mock_api, 1 );

        $this->assertInstanceOf( BBB_Player_Sync::class, $sync );
    }
}
