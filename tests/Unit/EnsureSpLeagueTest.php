<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für BBB_Sync_Engine::ensure_sp_league() (v1.1.4 Liga/Turnier-Fix).
 *
 * Deckt die beiden Verhaltensänderungen ab:
 *  - Meta (_bbb_liga_id, _bbb_ak_name, _bbb_geschlecht) wird bei JEDEM Sync
 *    aktualisiert, nicht nur bei Erstanlage.
 *  - tableExists (true/false/null) wird als '_bbb_table_exists' Meta
 *    ('1'/'0'/nicht gesetzt) persistiert; null überschreibt nicht.
 */
final class EnsureSpLeagueTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function call( array $liga_data, int $bbb_liga_id ) {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        $instance   = $reflection->newInstanceWithoutConstructor();

        $method = new ReflectionMethod( \BBB_Sync_Engine::class, 'ensure_sp_league' );
        $method->setAccessible( true );

        return $method->invoke( $instance, $liga_data, $bbb_liga_id );
    }

    public function test_new_term_with_table_exists_true_sets_meta_1(): void {
        Functions\expect( 'get_term_by' )->once()->andReturn( false );
        Functions\expect( 'wp_insert_term' )->once()->andReturn( [ 'term_id' => 99 ] );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\expect( 'wp_update_term' )->never();

        // Restliche Meta-Aufrufe (liga_id, ak_name, geschlecht) werden ebenfalls
        // getätigt, aber hier nicht einzeln geprüft.
        Functions\expect( 'update_term_meta' )
            ->once()
            ->with( 99, '_bbb_table_exists', '1' );
        Functions\expect( 'update_term_meta' )->zeroOrMoreTimes()->andReturn( true );

        $result = $this->call( [ 'liganame' => 'Bezirksliga', 'tableExists' => true ], 42 );

        $this->assertSame( 99, $result );
    }

    public function test_new_term_with_table_exists_false_sets_meta_0(): void {
        Functions\expect( 'get_term_by' )->once()->andReturn( false );
        Functions\expect( 'wp_insert_term' )->once()->andReturn( [ 'term_id' => 5 ] );
        Functions\when( 'is_wp_error' )->justReturn( false );

        Functions\expect( 'update_term_meta' )
            ->once()
            ->with( 5, '_bbb_table_exists', '0' );
        Functions\expect( 'update_term_meta' )->zeroOrMoreTimes()->andReturn( true );

        $result = $this->call( [ 'liganame' => 'Pokal', 'tableExists' => false ], 7 );

        $this->assertSame( 5, $result );
    }

    public function test_null_table_exists_never_writes_table_exists_meta(): void {
        Functions\expect( 'get_term_by' )->once()->andReturn( false );
        Functions\expect( 'wp_insert_term' )->once()->andReturn( [ 'term_id' => 3 ] );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\expect( 'update_term_meta' )->zeroOrMoreTimes()->andReturn( true );

        Functions\expect( 'update_term_meta' )
            ->never()
            ->with( 3, '_bbb_table_exists', \Mockery::any() );

        $this->call( [ 'liganame' => 'Unbekannt' ], 1 ); // kein 'tableExists' Key
    }

    public function test_existing_term_still_updates_meta(): void {
        $existing_term = (object) [ 'term_id' => 55, 'name' => 'Kreisliga' ];

        Functions\expect( 'get_term_by' )->once()->andReturn( $existing_term );
        Functions\expect( 'wp_insert_term' )->never();
        Functions\expect( 'wp_update_term' )->never(); // Name unverändert

        Functions\expect( 'update_term_meta' )
            ->once()
            ->with( 55, '_bbb_liga_id', 42 );
        Functions\expect( 'update_term_meta' )->zeroOrMoreTimes()->andReturn( true );

        $result = $this->call( [ 'liganame' => 'Kreisliga', 'tableExists' => true ], 42 );

        $this->assertSame( 55, $result );
    }

    public function test_existing_term_with_changed_name_calls_wp_update_term(): void {
        $existing_term = (object) [ 'term_id' => 55, 'name' => 'Alter Name' ];

        Functions\expect( 'get_term_by' )->once()->andReturn( $existing_term );
        Functions\expect( 'wp_update_term' )
            ->once()
            ->with( 55, 'sp_league', [ 'name' => 'Neuer Name' ] );
        Functions\when( 'update_term_meta' )->justReturn( true );

        $this->call( [ 'liganame' => 'Neuer Name' ], 42 );
    }

    public function test_wp_error_on_insert_returns_false(): void {
        Functions\expect( 'get_term_by' )->once()->andReturn( false );
        Functions\expect( 'wp_insert_term' )->once()->andReturn( (object) [] );
        Functions\when( 'is_wp_error' )->justReturn( true );
        Functions\expect( 'update_term_meta' )->never();

        $result = $this->call( [ 'liganame' => 'Fehlerfall' ], 42 );

        $this->assertFalse( $result );
    }
}
