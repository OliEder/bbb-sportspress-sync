<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BBB_Api_Client response handling and data extraction.
 *
 * HTTP layer (wp_remote_get etc.) is stubbed via Brain Monkey so no real
 * network calls are made.
 */
class ApiClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub WP functions called by BBB_Api_Client::log()
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2024-01-01 00:00:00' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mock_http( int $status, string $body ): void {
        Functions\when( 'wp_remote_get' )->justReturn( [ 'mock' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
    }

    private function api_response( array $data ): string {
        return (string) json_encode( [ 'status' => '0', 'data' => $data ] );
    }

    // ── get_club_matches ──────────────────────────────────────────────────────

    public function test_get_club_matches_returns_club_and_matches(): void {
        $this->mock_http( 200, $this->api_response( [
            'club'    => [ 'name' => 'TuS Lünen', 'id' => 42 ],
            'matches' => [ [ 'matchId' => 1 ], [ 'matchId' => 2 ] ],
        ] ) );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertIsArray( $result );
        $this->assertEquals( 'TuS Lünen', $result['club']['name'] );
        $this->assertCount( 2, $result['matches'] );
    }

    public function test_get_club_matches_returns_empty_arrays_when_data_missing(): void {
        $this->mock_http( 200, $this->api_response( [] ) );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertEquals( [], $result['club'] );
        $this->assertEquals( [], $result['matches'] );
    }

    public function test_get_club_matches_propagates_wp_error(): void {
        Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_error', 'Connection refused' ) );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertEquals( 'http_error', $result->get_error_code() );
    }

    // ── HTTP / JSON error handling ────────────────────────────────────────────

    public function test_non_200_status_returns_wp_error(): void {
        $this->mock_http( 404, 'Not Found' );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertEquals( 'bbb_api_http_error', $result->get_error_code() );
    }

    public function test_invalid_json_returns_wp_error(): void {
        $this->mock_http( 200, '{ not valid json {{' );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertEquals( 'bbb_api_json_error', $result->get_error_code() );
    }

    public function test_api_status_non_zero_returns_wp_error(): void {
        $this->mock_http( 200, (string) json_encode( [
            'status'  => '1',
            'message' => 'Verein nicht gefunden',
        ] ) );

        $result = ( new BBB_Api_Client() )->get_club_matches( 42 );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertEquals( 'bbb_api_error', $result->get_error_code() );
        $this->assertEquals( 'Verein nicht gefunden', $result->get_error_message() );
    }

    // ── get_team_matches ──────────────────────────────────────────────────────

    public function test_get_team_matches_returns_team_and_matches(): void {
        $this->mock_http( 200, $this->api_response( [
            'team'    => [ 'name' => 'U16 männlich', 'teamPermanentId' => 99 ],
            'matches' => [ [ 'matchId' => 10 ] ],
        ] ) );

        $result = ( new BBB_Api_Client() )->get_team_matches( 99 );

        $this->assertEquals( 'U16 männlich', $result['team']['name'] );
        $this->assertCount( 1, $result['matches'] );
    }

    // ── get_liga_spielplan ────────────────────────────────────────────────────

    public function test_get_liga_spielplan_returns_liga_data_and_matches(): void {
        $this->mock_http( 200, $this->api_response( [
            'ligaData' => [ 'ligaId' => 789, 'ligaName' => 'Kreisliga A' ],
            'matches'  => array_fill( 0, 14, [ 'matchId' => 1 ] ),
        ] ) );

        $result = ( new BBB_Api_Client() )->get_liga_spielplan( 789 );

        $this->assertEquals( 'Kreisliga A', $result['liga_data']['ligaName'] );
        $this->assertCount( 14, $result['matches'] );
    }
}
