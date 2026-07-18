<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für den Venue-Lookup-Fehler-Cache (v1.1.6).
 *
 * Vermeidet wiederholte matchInfo-API-Calls für Spiele, bei denen die
 * Venue-Ermittlung bereits einmal endgültig fehlgeschlagen ist (z. B.
 * gelöschte/nicht mehr erreichbare BBB-Spiele).
 */
final class VenueLookupCacheTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_engine(): \BBB_Sync_Engine {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call( string $method_name, array $args ) {
        $method = new ReflectionMethod( \BBB_Sync_Engine::class, $method_name );
        $method->setAccessible( true );
        return $method->invokeArgs( $this->make_engine(), $args );
    }

    public function test_is_cached_as_failed_returns_false_when_no_transient_set(): void {
        Functions\expect( 'get_transient' )
            ->once()
            ->with( 'bbb_venue_lookup_failed_12345' )
            ->andReturn( false );

        $result = $this->call( 'is_venue_lookup_cached_as_failed', [ 12345 ] );

        $this->assertFalse( $result );
    }

    public function test_is_cached_as_failed_returns_true_when_transient_set(): void {
        Functions\expect( 'get_transient' )
            ->once()
            ->with( 'bbb_venue_lookup_failed_12345' )
            ->andReturn( '1' );

        $result = $this->call( 'is_venue_lookup_cached_as_failed', [ 12345 ] );

        $this->assertTrue( $result );
    }

    public function test_cache_venue_lookup_failure_sets_transient_for_one_day(): void {
        Functions\expect( 'set_transient' )
            ->once()
            ->with( 'bbb_venue_lookup_failed_12345', '1', DAY_IN_SECONDS );

        $this->call( 'cache_venue_lookup_failure', [ 12345 ] );
    }
}
