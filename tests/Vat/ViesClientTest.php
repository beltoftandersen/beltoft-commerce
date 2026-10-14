<?php

use BeltoftCommerce\Vat\ViesClient;

class ViesClientTest extends WP_UnitTestCase {

	private int $requests = 0;

	public function setUp(): void {
		parent::setUp();
		$this->requests = 0;
		delete_transient( 'beltoft_vies_ES_B12345678' );
		delete_transient( 'beltoft_vies_ES_B00000000' );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function mock_response( array $body, int $status = 200 ): void {
		add_filter( 'pre_http_request', function () use ( $body, $status ) {
			$this->requests++;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $status, 'message' => 'OK' ),
			);
		}, 10, 3 );
	}

	private function mock_error( string $message ): void {
		add_filter( 'pre_http_request', function () use ( $message ) {
			$this->requests++;

			return new WP_Error( 'http_request_failed', $message );
		}, 10, 3 );
	}

	public function test_a_valid_number_returns_valid_with_a_consultation_number() {
		$this->mock_response( array( 'valid' => true, 'requestIdentifier' => 'WAPIAAAAX123', 'name' => 'ACME SL' ) );

		$result = ViesClient::check( 'ES', 'B12345678' );

		$this->assertSame( 'valid', $result->status );
		$this->assertSame( 'WAPIAAAAX123', $result->request_identifier );
		$this->assertSame( 'ACME SL', $result->registered_name );
	}

	public function test_an_invalid_number_returns_invalid() {
		$this->mock_response( array( 'valid' => false ) );

		$this->assertSame( 'invalid', ViesClient::check( 'ES', 'B00000000' )->status );
	}

	/**
	 * A member state being down must never look like a bad VAT number.
	 * Blocking checkout because a Spanish government endpoint is offline is
	 * a real revenue cost. Architecture §2.10.
	 */
	public function test_a_timeout_returns_unreachable_and_never_invalid() {
		$this->mock_error( 'cURL error 28: Operation timed out' );

		$result = ViesClient::check( 'ES', 'B12345678' );

		$this->assertSame( 'unreachable', $result->status );
		$this->assertNotSame( 'invalid', $result->status );
		$this->assertNotEmpty( $result->error );
	}

	/**
	 * Prevents a VIES API change from silently blocking every EU B2B
	 * checkout: anything that is not an explicit valid/invalid is treated
	 * as unreachable, never as invalid.
	 */
	public function test_an_unexpected_response_shape_is_unreachable_not_invalid() {
		$this->mock_response( array( 'somethingElse' => true ) );

		$this->assertSame( 'unreachable', ViesClient::check( 'ES', 'B12345678' )->status );
	}

	public function test_a_server_error_is_unreachable() {
		$this->mock_response( array( 'message' => 'MS_UNAVAILABLE' ), 503 );

		$this->assertSame( 'unreachable', ViesClient::check( 'ES', 'B12345678' )->status );
	}

	/**
	 * VIES rate-limits by concurrency, and a customer reloading checkout
	 * must not generate a second call.
	 */
	public function test_a_result_is_cached_and_not_requested_twice() {
		$this->mock_response( array( 'valid' => true, 'requestIdentifier' => 'X1' ) );

		ViesClient::check( 'ES', 'B12345678' );
		ViesClient::check( 'ES', 'B12345678' );

		$this->assertSame( 1, $this->requests );
	}

	public function test_an_unreachable_result_is_not_cached() {
		// Caching an outage would extend it well past the actual downtime.
		$this->mock_error( 'timed out' );

		ViesClient::check( 'ES', 'B12345678' );
		ViesClient::check( 'ES', 'B12345678' );

		$this->assertSame( 2, $this->requests );
	}
}
