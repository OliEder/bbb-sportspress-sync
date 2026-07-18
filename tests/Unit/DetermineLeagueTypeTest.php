<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für BBB_Admin_Page::determine_league_type() (v1.1.4 Liga/Turnier-Fix).
 */
final class DetermineLeagueTypeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function call( string $table_exists_meta ): string {
        $reflection = new ReflectionClass( \BBB_Admin_Page::class );
        $instance   = $reflection->newInstanceWithoutConstructor();

        $method = new ReflectionMethod( \BBB_Admin_Page::class, 'determine_league_type' );
        $method->setAccessible( true );

        return $method->invoke( $instance, $table_exists_meta );
    }

    public function test_meta_zero_means_tournament(): void {
        $this->assertSame( 'tournament', $this->call( '0' ) );
    }

    public function test_meta_one_means_league(): void {
        $this->assertSame( 'league', $this->call( '1' ) );
    }

    public function test_empty_meta_means_league(): void {
        $this->assertSame( 'league', $this->call( '' ) );
    }
}
