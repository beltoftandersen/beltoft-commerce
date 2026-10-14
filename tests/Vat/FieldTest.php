<?php

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Vat\Field;

class FieldTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		wc_clear_notices();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_POST['beltoft_vat_number'], $_POST['billing_country'] );
		remove_all_filters( 'pre_http_request' );
		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * The generic "could not be validated" sent people to retype a number
	 * that was fine; what is wrong is the billing country, so say that.
	 */
	public function test_a_number_from_another_country_than_billing_says_so() {
		add_filter( 'pre_http_request', function () {
			$this->fail( 'A country mismatch is decided without VIES.' );
		} );

		$_POST['beltoft_vat_number'] = 'ESB12345678';
		$_POST['billing_country']    = 'PT';

		Field::validate();

		$errors = wc_get_notices( 'error' );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'billing', $errors[0]['notice'] );
	}
}
