<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests für BBB_SportsPress_Meta_Registration (REST-Sichtbarkeit nativer
 * SportsPress-Felder, v1.1.11). Gleiches Testmuster wie
 * MetaRegistrationTest.php: register_post_meta() wird gemockt, alle Aufrufe
 * werden pro (Post Type, Meta-Key)-Paar eingesammelt und geprüft.
 */
final class SportsPressMetaRegistrationTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_registration(): \BBB_SportsPress_Meta_Registration {
        $reflection = new ReflectionClass( \BBB_SportsPress_Meta_Registration::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Ruft register_meta() auf und sammelt alle register_post_meta()-Aufrufe,
     * geschlüsselt als "<post_type>:<meta_key>".
     */
    private function register_and_capture(): array {
        $captured = [];

        Functions\expect( 'register_post_meta' )
            ->times( 11 )
            ->andReturnUsing(
                function ( string $post_type, string $meta_key, array $args ) use ( &$captured ) {
                    $captured[ "{$post_type}:{$meta_key}" ] = $args;
                    return true;
                }
            );

        $this->make_registration()->register_meta();

        return $captured;
    }

    public function test_registers_all_eleven_expected_pairs(): void {
        $captured = $this->register_and_capture();

        $expected_keys = [
            'sp_event:sp_team',
            'sp_event:sp_player',
            'sp_event:sp_players',
            'sp_event:sp_results',
            'sp_event:sp_status',
            'sp_event:sp_main_result',
            'sp_event:sp_day',
            'sp_event:sp_format',
            'sp_team:sp_abbreviation',
            'sp_team:sp_short_name',
            'sp_team:sp_url',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $captured );
        }
        $this->assertCount( 11, $captured );
    }

    public function test_multi_value_fields_have_single_false(): void {
        $captured = $this->register_and_capture();

        $this->assertFalse( $captured['sp_event:sp_team']['single'] );
        $this->assertFalse( $captured['sp_event:sp_player']['single'] );
    }

    public function test_single_value_fields_have_single_true(): void {
        $captured = $this->register_and_capture();

        $single_true_keys = [
            'sp_event:sp_players',
            'sp_event:sp_results',
            'sp_event:sp_status',
            'sp_event:sp_main_result',
            'sp_event:sp_day',
            'sp_event:sp_format',
            'sp_team:sp_abbreviation',
            'sp_team:sp_short_name',
            'sp_team:sp_url',
        ];

        foreach ( $single_true_keys as $key ) {
            $this->assertTrue( $captured[ $key ]['single'], "single mismatch for {$key}" );
        }
    }

    public function test_object_type_fields(): void {
        $captured = $this->register_and_capture();

        $this->assertSame( 'object', $captured['sp_event:sp_players']['type'] );
        $this->assertSame( 'object', $captured['sp_event:sp_results']['type'] );
    }

    public function test_integer_type_fields(): void {
        $captured = $this->register_and_capture();

        foreach ( [ 'sp_event:sp_team', 'sp_event:sp_player', 'sp_event:sp_day' ] as $key ) {
            $this->assertSame( 'integer', $captured[ $key ]['type'], "type mismatch for {$key}" );
        }
    }

    public function test_string_type_fields(): void {
        $captured = $this->register_and_capture();

        foreach ( [ 'sp_event:sp_status', 'sp_event:sp_format', 'sp_team:sp_abbreviation', 'sp_team:sp_short_name', 'sp_team:sp_url' ] as $key ) {
            $this->assertSame( 'string', $captured[ $key ]['type'], "type mismatch for {$key}" );
        }
    }

    /**
     * sp_main_result enthält den Slug der Haupt-Ergebnis-Spalte (z.B. 'pts', 't'),
     * nicht die Punktzahl – ein Integer-Typ würde den Slug zu 0 umwandeln.
     */
    public function test_main_result_is_string_slug_not_integer(): void {
        $captured = $this->register_and_capture();

        $this->assertSame( 'string', $captured['sp_event:sp_main_result']['type'] );
    }

    public function test_all_pairs_have_show_in_rest_and_callable_auth_callback(): void {
        $captured = $this->register_and_capture();

        foreach ( $captured as $key => $args ) {
            $this->assertTrue( $args['show_in_rest'], "show_in_rest mismatch for {$key}" );
            $this->assertArrayHasKey( 'auth_callback', $args );
            $this->assertIsCallable( $args['auth_callback'] );
        }
    }

    public function test_auth_callback_checks_edit_posts(): void {
        $captured = $this->register_and_capture();

        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'edit_posts' )
            ->andReturn( true );

        $this->assertTrue( ( $captured['sp_event:sp_team']['auth_callback'] )() );
    }
}
