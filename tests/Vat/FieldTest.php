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

	/** The same number typed another way at checkout keeps its VIES proof. */
	public function test_the_same_number_in_another_format_keeps_its_proof() {
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user, Field::META_KEY, 'DE123456789' );
		update_user_meta( $user, '_vat_check_status', 'valid' );
		update_user_meta( $user, '_vies_request_identifier', 'WAPI-KEEP' );

		$_POST['beltoft_vat_number'] = 'de 123 456 789';
		$customer                    = new WC_Customer( $user );
		Field::save_to_customer( $customer, array() );
		$customer->save();

		$this->assertSame( 'DE123456789', get_user_meta( $user, Field::META_KEY, true ) );
		$this->assertSame( 'WAPI-KEEP', get_user_meta( $user, '_vies_request_identifier', true ) );
	}

	public function test_a_different_number_clears_the_proof() {
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user, Field::META_KEY, 'DE123456789' );
		update_user_meta( $user, '_vies_request_identifier', 'WAPI-OLD' );

		$_POST['beltoft_vat_number'] = 'DE999999999';
		$customer                    = new WC_Customer( $user );
		Field::save_to_customer( $customer, array() );
		$customer->save();

		$this->assertSame( 'DE999999999', get_user_meta( $user, Field::META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user, '_vies_request_identifier', true ) );
	}
}
