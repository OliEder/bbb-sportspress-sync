<?php

namespace BBB\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests für write_file() in BBB_Logo_Handler (v1.1.7).
 *
 * Ersetzt den direkten file_put_contents()-Aufruf durch die WordPress
 * Filesystem API (WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents).
 */
final class LogoHandlerWriteFileTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wp_filesystem'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_handler(): \BBB_Logo_Handler {
        $reflection = new ReflectionClass( \BBB_Logo_Handler::class );
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call( string $method_name, array $args ) {
        $method = new ReflectionMethod( \BBB_Logo_Handler::class, $method_name );
        $method->setAccessible( true );
        return $method->invokeArgs( $this->make_handler(), $args );
    }

    public function test_write_file_uses_wp_filesystem_put_contents(): void {
        $wp_filesystem = Mockery::mock();
        $wp_filesystem->shouldReceive( 'put_contents' )
            ->once()
            ->with( '/uploads/logo.png', 'PNGDATA', FS_CHMOD_FILE )
            ->andReturn( true );
        $GLOBALS['wp_filesystem'] = $wp_filesystem;

        $result = $this->call( 'write_file', [ '/uploads/logo.png', 'PNGDATA' ] );

        $this->assertTrue( $result );
    }

    public function test_write_file_returns_false_on_filesystem_failure(): void {
        $wp_filesystem = Mockery::mock();
        $wp_filesystem->shouldReceive( 'put_contents' )
            ->once()
            ->andReturn( false );
        $GLOBALS['wp_filesystem'] = $wp_filesystem;

        $result = $this->call( 'write_file', [ '/uploads/logo.png', 'PNGDATA' ] );

        $this->assertFalse( $result );
    }

    public function test_write_file_returns_false_when_filesystem_unavailable(): void {
        $GLOBALS['wp_filesystem'] = null;

        $result = $this->call( 'write_file', [ '/uploads/logo.png', 'PNGDATA' ] );

        $this->assertFalse( $result );
    }
}
