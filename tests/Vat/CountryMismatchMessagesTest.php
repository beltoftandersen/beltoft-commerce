<?php

use BeltoftCommerce\Registration\Registrar;
use BeltoftCommerce\Vat\AccountEndpoint;

/**
 * Registration and the fiscal-data page refuse a VAT number from another
 * member state than the applicant's or partner's country, and say why.
 */
class CountryMismatchMessagesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		add_filter( 'pre_http_request', function () {
			$this->fail( 'A country mismatch is decided without VIES.' );
		} );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function call( string $class, string $method, ...$args ) {
		$m = new ReflectionMethod( $class, $method );
		$m->setAccessible( true );

		return $m->invoke( null, ...$args );
	}

	public function test_registration_refuses_a_number_from_another_country() {
		$result = $this->call( Registrar::class, 'validate_nif', 'ESB12345678', 'PT' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'país', $result->get_error_message() );
	}

	public function test_the_account_page_explains_a_country_mismatch() {
		$message = $this->call( AccountEndpoint::class, 'refusal_message', 'country_mismatch' );

		$this->assertStringContainsString( 'faturação', $message );
	}
}
