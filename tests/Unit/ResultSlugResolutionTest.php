<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für die Ermittlung der Ergebnis-Spalten (v1.1.12).
 *
 * SportsPress zeigt in Teasern/Event-Blöcken nur die Spalte, deren Slug in der
 * Option `sportspress_primary_result` steht (ohne Option: die letzte Spalte).
 * Das Plugin muss das Ergebnis daher immer auch in diese Spalte schreiben –
 * unabhängig davon, welche Slugs in `bbb_sync_result_slugs` angehakt sind.
 */
final class ResultSlugResolutionTest extends TestCase {
    use MockeryPHPUnitIntegration;

    /** @var array<string,mixed> Simulierte WP-Optionen. */
    private array $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->options = [];
        Functions\when( 'get_option' )->alias(
            function ( string $key, $default = false ) {
                return $this->options[ $key ] ?? $default;
            }
        );

        $this->reset_engine_caches();
    }

    protected function tearDown(): void {
        $this->reset_engine_caches();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Statische Caches der Engine zurücksetzen, damit Tests sich nicht beeinflussen. */
    private function reset_engine_caches(): void {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        foreach ( [ 'main_result_slug', 'result_slugs_cache', 'sp_primary_result_slug' ] as $name ) {
            if ( $reflection->hasProperty( $name ) ) {
                $reflection->getProperty( $name )->setValue( null, null );
            }
        }
    }

    private function make_engine(): \BBB_Sync_Engine {
        $reflection = new ReflectionClass( \BBB_Sync_Engine::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function column( string $slug ): object {
        return (object) [ 'post_name' => $slug ];
    }

    /** get_posts()-Erwartung: letzte sp_result-Spalte (menu_order absteigend). */
    private function expect_last_column_lookup( array $return ): void {
        Functions\expect( 'get_posts' )
            ->once()
            ->with(
                Mockery::on(
                    static function ( $args ): bool {
                        return 'sp_result' === ( $args['post_type'] ?? null )
                            && 'menu_order' === ( $args['orderby'] ?? null )
                            && 'DESC' === ( $args['order'] ?? null )
                            && 1 === ( $args['posts_per_page'] ?? null );
                    }
                )
            )
            ->andReturn( $return );
    }

    // ─────────────────────────────────────────
    // SportsPress-Primärergebnis
    // ─────────────────────────────────────────

    public function test_primary_slug_uses_sportspress_option_when_column_exists(): void {
        $this->options['sportspress_primary_result'] = 't';
        Functions\expect( 'get_page_by_path' )
            ->once()
            ->with( 't', Mockery::any(), 'sp_result' )
            ->andReturn( $this->column( 't' ) );

        $this->assertSame( 't', $this->make_engine()->get_sportspress_primary_result_slug() );
    }

    public function test_primary_slug_falls_back_to_last_column_when_option_empty(): void {
        $this->expect_last_column_lookup( [ $this->column( 't' ) ] );

        $this->assertSame( 't', $this->make_engine()->get_sportspress_primary_result_slug() );
    }

    public function test_primary_slug_ignores_stale_option_pointing_to_missing_column(): void {
        $this->options['sportspress_primary_result'] = 'gone';
        Functions\expect( 'get_page_by_path' )->once()->andReturn( null );
        $this->expect_last_column_lookup( [ $this->column( 't' ) ] );

        $this->assertSame( 't', $this->make_engine()->get_sportspress_primary_result_slug() );
    }

    public function test_primary_slug_is_null_without_any_result_column(): void {
        $this->expect_last_column_lookup( [] );

        $this->assertNull( $this->make_engine()->get_sportspress_primary_result_slug() );
    }

    // ─────────────────────────────────────────
    // Ziel-Slugs für das Schreiben
    // ─────────────────────────────────────────

    public function test_target_slugs_append_sportspress_primary_to_configured(): void {
        $this->options['bbb_sync_result_slugs']      = 'pts';
        $this->options['sportspress_primary_result'] = 't';
        Functions\when( 'get_page_by_path' )->justReturn( $this->column( 't' ) );

        $this->assertSame( [ 'pts', 't' ], $this->make_engine()->get_target_result_slugs() );
    }

    public function test_target_slugs_do_not_duplicate_primary(): void {
        $this->options['bbb_sync_result_slugs']      = 't,pts';
        $this->options['sportspress_primary_result'] = 't';
        Functions\when( 'get_page_by_path' )->justReturn( $this->column( 't' ) );

        $this->assertSame( [ 't', 'pts' ], $this->make_engine()->get_target_result_slugs() );
    }

    public function test_target_slugs_use_primary_alone_when_nothing_configured(): void {
        $this->options['sportspress_primary_result'] = 't';
        Functions\when( 'get_page_by_path' )->justReturn( $this->column( 't' ) );

        $this->assertSame( [ 't' ], $this->make_engine()->get_target_result_slugs() );
    }

    public function test_target_slugs_keep_configured_when_no_primary_resolvable(): void {
        $this->options['bbb_sync_result_slugs'] = 'pts';
        Functions\when( 'get_posts' )->justReturn( [] );

        $this->assertSame( [ 'pts' ], $this->make_engine()->get_target_result_slugs() );
    }

    // ─────────────────────────────────────────
    // sp_main_result (Primär-Slug des Plugins)
    // ─────────────────────────────────────────

    private function call_main_result_slug( \BBB_Sync_Engine $engine ): ?string {
        $method = new ReflectionMethod( \BBB_Sync_Engine::class, 'get_main_result_slug' );
        return $method->invoke( $engine );
    }

    public function test_main_result_slug_prefers_first_configured_slug(): void {
        $this->options['bbb_sync_result_slugs'] = 't,pts';
        Functions\expect( 'get_posts' )->never();

        $this->assertSame( 't', $this->call_main_result_slug( $this->make_engine() ) );
    }

    public function test_main_result_slug_falls_back_to_sportspress_primary_not_first_column(): void {
        // SportsPress zeigt die LETZTE Spalte; das Plugin darf nicht die erste nehmen.
        $this->expect_last_column_lookup( [ $this->column( 't' ) ] );

        $this->assertSame( 't', $this->call_main_result_slug( $this->make_engine() ) );
    }

    public function test_main_result_slug_is_null_without_any_result_column(): void {
        // ensure_sportspress_setup() erkennt daran, dass 'pts' angelegt werden muss.
        $this->expect_last_column_lookup( [] );

        $this->assertNull( $this->call_main_result_slug( $this->make_engine() ) );
    }
}
