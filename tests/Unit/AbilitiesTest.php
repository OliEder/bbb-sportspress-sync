<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests für BBB_Abilities (WordPress Abilities API, v1.1.9).
 *
 * Prüft sowohl die Registrierung (Kategorie + 3 Abilities über
 * wp_register_ability_category()/wp_register_ability()) als auch die
 * execute_callback-Logik der drei Abilities (trigger-sync, get-sync-status,
 * get-sync-history), da diese WP-6.9+-Funktionen in wp-stubs.php nicht
 * existieren und daher gestubbt werden müssen.
 */
final class AbilitiesTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_abilities(): \BBB_Abilities {
        $reflection = new ReflectionClass( \BBB_Abilities::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Ruft register_abilities() auf und sammelt die an wp_register_ability()
     * übergebenen Argumente pro Ability-ID ein.
     */
    private function register_and_capture(): array {
        $captured = [];
        Functions\when( 'wp_register_ability_category' )->justReturn( null );
        Functions\expect( 'wp_register_ability' )
            ->times( 3 )
            ->andReturnUsing(
                function ( string $id, array $args ) use ( &$captured ) {
                    $captured[ $id ] = $args;
                    return null;
                }
            );

        $this->make_abilities()->register_abilities();

        return $captured;
    }

    // ── register_category() ───────────────────────────────────────────────

    public function test_register_category_registers_expected_label(): void {
        $captured = null;
        Functions\expect( 'wp_register_ability_category' )
            ->once()
            ->andReturnUsing(
                function ( string $id, array $args ) use ( &$captured ) {
                    $captured = [ $id, $args ];
                    return null;
                }
            );

        $this->make_abilities()->register_category();

        $this->assertSame( 'bbb-sportspress-sync', $captured[0] );
        $this->assertSame( 'BBB SportsPress Sync', $captured[1]['label'] );
    }

    // ── register_abilities() wiring ───────────────────────────────────────

    public function test_register_abilities_registers_three_abilities(): void {
        $captured = $this->register_and_capture();

        $this->assertArrayHasKey( 'bbb-sportspress-sync/trigger-sync', $captured );
        $this->assertArrayHasKey( 'bbb-sportspress-sync/get-sync-status', $captured );
        $this->assertArrayHasKey( 'bbb-sportspress-sync/get-sync-history', $captured );
    }

    public function test_trigger_sync_meta_marks_non_readonly(): void {
        $captured = $this->register_and_capture();

        $this->assertFalse( $captured['bbb-sportspress-sync/trigger-sync']['meta']['annotations']['readonly'] );
    }

    public function test_get_sync_status_meta_marks_readonly(): void {
        $captured = $this->register_and_capture();

        $this->assertTrue( $captured['bbb-sportspress-sync/get-sync-status']['meta']['annotations']['readonly'] );
    }

    // ── check_permission() ────────────────────────────────────────────────

    public function test_check_permission_delegates_to_current_user_can(): void {
        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( true );

        $this->assertTrue( $this->make_abilities()->check_permission() );
    }

    // ── execute_trigger_sync() ────────────────────────────────────────────

    public function test_trigger_sync_schedules_when_idle_and_teams_exist(): void {
        Functions\expect( 'get_transient' )->with( 'bbb_sync_progress' )->andReturn( false );
        Functions\expect( 'get_option' )->with( 'bbb_sync_own_teams', [] )->andReturn( [ 'team-1' ] );
        Functions\expect( 'set_transient' )
            ->once()
            ->with( 'bbb_sync_progress', Mockery::type( 'array' ), 600 );
        Functions\expect( 'wp_schedule_single_event' )
            ->once()
            ->with( Mockery::type( 'integer' ), 'bbb_sync_cron_event' );

        $result = $this->make_abilities()->execute_trigger_sync();

        $this->assertTrue( $result['scheduled'] );
        $this->assertFalse( $result['already_running'] );
    }

    public function test_trigger_sync_reports_already_running_when_lock_fresh(): void {
        Functions\expect( 'get_transient' )
            ->with( 'bbb_sync_progress' )
            ->andReturn(
                [
                    'running'    => true,
                    'started_at' => time(),
                ]
            );

        $result = $this->make_abilities()->execute_trigger_sync();

        $this->assertFalse( $result['scheduled'] );
        $this->assertTrue( $result['already_running'] );
    }

    public function test_trigger_sync_resets_stale_lock_and_schedules(): void {
        Functions\expect( 'get_transient' )
            ->with( 'bbb_sync_progress' )
            ->andReturn(
                [
                    'running'    => true,
                    'started_at' => time() - 301,
                ]
            );
        Functions\expect( 'delete_transient' )->once()->with( 'bbb_sync_progress' );
        Functions\expect( 'get_option' )->with( 'bbb_sync_own_teams', [] )->andReturn( [ 'team-1' ] );
        Functions\expect( 'set_transient' )->once();
        Functions\expect( 'wp_schedule_single_event' )->once();

        $result = $this->make_abilities()->execute_trigger_sync();

        $this->assertTrue( $result['scheduled'] );
    }

    public function test_trigger_sync_reports_nothing_to_do_without_teams(): void {
        Functions\expect( 'get_transient' )->with( 'bbb_sync_progress' )->andReturn( false );
        Functions\expect( 'get_option' )->with( 'bbb_sync_own_teams', [] )->andReturn( [] );

        $result = $this->make_abilities()->execute_trigger_sync();

        $this->assertFalse( $result['scheduled'] );
        $this->assertFalse( $result['already_running'] );
        $this->assertNotEmpty( $result['message'] );
    }

    // ── execute_get_sync_status() ─────────────────────────────────────────

    public function test_get_sync_status_merges_progress_and_history(): void {
        Functions\expect( 'get_transient' )
            ->with( 'bbb_sync_progress' )
            ->andReturn(
                [
                    'running'       => true,
                    'phase'         => 'processing',
                    'current_label' => 'Team 3 von 5',
                    'started_at'    => 1000,
                ]
            );
        $options = [
            'bbb_sync_history'   => [
                [ 'time' => '2026-07-01 10:00:00' ],
                [ 'time' => '2026-07-15 10:00:00' ],
            ],
            'bbb_sync_own_teams' => [ 'a', 'b', 'c' ],
        ];
        Functions\expect( 'get_option' )
            ->andReturnUsing( fn( string $key, $default = null ) => $options[ $key ] ?? $default );

        $result = $this->make_abilities()->execute_get_sync_status();

        $this->assertTrue( $result['running'] );
        $this->assertSame( 'processing', $result['phase'] );
        $this->assertSame( 3, $result['registered_teams'] );
        $this->assertSame( '2026-07-15 10:00:00', $result['last_run']['time'] );
    }

    public function test_get_sync_status_returns_null_last_run_without_history(): void {
        Functions\expect( 'get_transient' )->with( 'bbb_sync_progress' )->andReturn( false );
        Functions\expect( 'get_option' )->with( 'bbb_sync_history', [] )->andReturn( [] );
        Functions\expect( 'get_option' )->with( 'bbb_sync_own_teams', [] )->andReturn( [] );

        $result = $this->make_abilities()->execute_get_sync_status();

        $this->assertFalse( $result['running'] );
        $this->assertSame( 'idle', $result['phase'] );
        $this->assertNull( $result['last_run'] );
    }

    // ── execute_get_sync_history() ────────────────────────────────────────

    public function test_get_sync_history_returns_newest_first_limited(): void {
        Functions\expect( 'get_option' )
            ->with( 'bbb_sync_history', [] )
            ->andReturn(
                [
                    [ 'time' => 'run-1' ],
                    [ 'time' => 'run-2' ],
                    [ 'time' => 'run-3' ],
                ]
            );

        $result = $this->make_abilities()->execute_get_sync_history( [ 'limit' => 2 ] );

        $this->assertCount( 2, $result );
        $this->assertSame( 'run-3', $result[0]['time'] );
        $this->assertSame( 'run-2', $result[1]['time'] );
    }

    public function test_get_sync_history_defaults_limit_to_ten(): void {
        Functions\expect( 'get_option' )
            ->with( 'bbb_sync_history', [] )
            ->andReturn( [ [ 'time' => 'run-1' ] ] );

        $result = $this->make_abilities()->execute_get_sync_history( [] );

        $this->assertCount( 1, $result );
    }

    public function test_get_sync_history_clamps_limit_to_fifty(): void {
        Functions\expect( 'get_option' )
            ->with( 'bbb_sync_history', [] )
            ->andReturn( array_fill( 0, 60, [ 'time' => 'run' ] ) );

        $result = $this->make_abilities()->execute_get_sync_history( [ 'limit' => 999 ] );

        $this->assertCount( 50, $result );
    }
}
