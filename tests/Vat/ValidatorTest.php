<?php

use BeltoftCommerce\Vat\Validator;

class ValidatorTest extends WP_UnitTestCase {

	private int $requests = 0;

	public function setUp(): void {
		parent::setUp();
		$this->requests = 0;
		$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '_transient_beltoft_vies_%'" );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function mock_vies( string $outcome ): void {
		add_filter( 'pre_http_request', function () use ( $outcome ) {
			$this->requests++;

			if ( 'unreachable' === $outcome ) {
				return new WP_Error( 'http_request_failed', 'timed out' );
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array(
					'valid'             => 'valid' === $outcome,
					'requestIdentifier' => 'WAPI-TEST-1',
					'name'              => 'ACME SL',
				) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		}, 10, 3 );
	}

	public function test_an_empty_field_is_a_consumer_sale() {
		$d = Validator::decide( '', 'PT' );

		$this->assertFalse( $d->exempt );
		$this->assertFalse( $d->blocks_checkout );
		$this->assertSame( '', $d->status );
	}

	/**
	 * A domestic sale is a domestic sale, and a valid PT number is not a
	 * zero-rating trigger. Portuguese numbers are never sent to VIES.
	 */
	public function test_a_portuguese_number_is_domestic_and_never_reaches_vies() {
		$this->mock_vies( 'valid' );

		$d = Validator::decide( 'PT999999999', 'PT' );

		$this->assertSame( 'domestic', $d->status );
		$this->assertFalse( $d->exempt );
		$this->assertFalse( $d->blocks_checkout );
		$this->assertSame( 0, $this->requests, 'no VIES call may be made for a PT number' );
	}

	public function test_a_portuguese_number_without_a_prefix_is_also_domestic() {
		$this->mock_vies( 'valid' );

		$d = Validator::decide( '999999999', 'PT' );

		$this->assertSame( 'domestic', $d->status );
		$this->assertSame( 0, $this->requests );
	}

	public function test_a_valid_spanish_number_is_exempt() {
		$this->mock_vies( 'valid' );

		$d = Validator::decide( 'ESB12345678', 'ES' );

		$this->assertTrue( $d->exempt );
		$this->assertSame( 'valid', $d->status );
		$this->assertSame( 'WAPI-TEST-1', $d->request_identifier );
	}

	public function test_an_invalid_number_blocks_checkout() {
		$this->mock_vies( 'invalid' );

		$d = Validator::decide( 'ESB00000000', 'ES' );

		$this->assertTrue( $d->blocks_checkout );
		$this->assertFalse( $d->exempt );
		$this->assertSame( 'invalid', $d->status );
	}

	/**
	 * The order completes, IVA is charged, and it is flagged for manual
	 * review. Architecture §2.10.
	 */
	public function test_an_unreachable_vies_completes_the_order_with_iva_charged() {
		$this->mock_vies( 'unreachable' );

		$d = Validator::decide( 'ESB12345678', 'ES' );

		$this->assertFalse( $d->blocks_checkout );
		$this->assertFalse( $d->exempt );
		$this->assertSame( 'unverified', $d->status );
		$this->assertNotEmpty( $d->error );
	}

	public function test_a_non_eu_number_is_out_of_scope_and_does_not_block() {
		$this->mock_vies( 'valid' );

		$d = Validator::decide( 'US123456', 'US' );

		$this->assertFalse( $d->exempt );
		$this->assertFalse( $d->blocks_checkout );
		$this->assertSame( 0, $this->requests, 'non-EU numbers are not sent to VIES' );
	}
}
