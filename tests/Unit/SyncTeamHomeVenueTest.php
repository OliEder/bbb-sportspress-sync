<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für BBB_Sync_Engine::sync_team_home_venue() (v1.1.6).
 *
 * Deckt das append-only-Verhalten ab: Ein am Heimteam bereits vorhandener
 * Home-Court (sp_venue-Taxonomie am sp_team-Post) darf durch den Sync nie
 * ersetzt werden, nur ergänzt.
 */
final class SyncTeamHomeVenueTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_engine(): \BBB_Sync_Engine {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call_sync_team_home_venue( \BBB_Sync_Engine $engine, int $team_wp_id, int $venue_term_id ): void {
        $method = new ReflectionMethod( \BBB_Sync_Engine::class, 'sync_team_home_venue' );
        $method->setAccessible( true );
        $method->invoke( $engine, $team_wp_id, $venue_term_id );
    }

    public function test_adds_venue_when_team_has_no_home_venue_yet(): void {
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->with( 42, 'sp_venue', [ 'fields' => 'ids' ] )
            ->andReturn( [] );

        Functions\expect( 'wp_set_object_terms' )
            ->once()
            ->with( 42, 7, 'sp_venue', true );

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_does_not_write_when_venue_already_assigned(): void {
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( [ 7 ] );

        Functions\expect( 'wp_set_object_terms' )->never();

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_appends_new_venue_keeping_existing_ones(): void {
        // Team hat bereits Venue #3 als Home-Court (z. B. manuell gepflegt).
        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( [ 3 ] );

        // Venue #7 wird per append=true ergänzt, #3 bleibt (append überschreibt nicht).
        Functions\expect( 'wp_set_object_terms' )
            ->once()
            ->with( 42, 7, 'sp_venue', true );

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }

    public function test_aborts_on_wp_error(): void {
        Functions\when( 'is_wp_error' )->justReturn( true );

        Functions\expect( 'wp_get_object_terms' )
            ->once()
            ->andReturn( (object) [] );

        Functions\expect( 'wp_set_object_terms' )->never();

        $this->call_sync_team_home_venue( $this->make_engine(), 42, 7 );
    }
}
