<?php

use BeltoftCommerce\Checkout\B2BCheckoutPage;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Vat\Exemption;
use BeltoftCommerce\Vat\Field;
use BeltoftCommerce\Vat\OrderEvidence;
use BeltoftCommerce\Vat\Validator;

/** VAT behaviour fixed after the 2026-10-17 review. */
class ReviewFixesTest extends WP_UnitTestCase {

	private int $vies_calls = 0;

	public function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '_transient_beltoft_vies_%'" );
		add_filter( 'pre_http_request', function () {
			$this->vies_calls++;

			return new WP_Error( 'http_request_failed', 'blocked in tests' );
		} );
		Exemption::reset();
		wc_clear_notices();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_POST['beltoft_vat_number'], $_POST['billing_country'] );
		Exemption::reset();
		wc_clear_notices();
		parent::tearDown();
	}

	/** Northern Ireland: an XI number billed in GB is checked with VIES like any EU number. */
	public function test_a_northern_ireland_number_is_an_eu_number() {
		set_transient( 'beltoft_vies_XI_123456789', array( 'status' => 'valid', 'request_identifier' => 'WAPI-XI', 'registered_name' => 'NI Ltd' ), HOUR_IN_SECONDS );

		$decision = Validator::decide( 'XI123456789', 'GB', false );

		$this->assertSame( 'valid', $decision->status );
		$this->assertTrue( $decision->exempt );
	}

	/** The retail checkout has no VAT field; its review must not reach VIES. */
	public function test_the_retail_checkout_review_does_not_call_vies() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		Exemption::capture_review( 'beltoft_vat_number=ES12345678Z&billing_country=ES' );
		Exemption::capture_review( 'beltoft_vat_number[]=x&billing_country=ES' );

		$this->assertSame( 0, $this->vies_calls );
	}

	/** The order records what the checkout learnt; it does not ask VIES again. */
	public function test_order_evidence_makes_no_second_vies_call() {
		$order = new WC_Order();
		$order->save();
		$_POST['beltoft_vat_number'] = 'ESB12345678';

		OrderEvidence::attach( $order, array( 'billing_country' => 'ES' ) );

		$this->assertSame( 0, $this->vies_calls );
		$this->assertSame( 'unverified', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
	}

	/** Nothing readable, or a bad Portuguese check digit, is refused at checkout. */
	public function test_the_checkout_field_refuses_what_the_fiscal_page_refuses() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		foreach ( array( '-', '123456780' ) as $number ) {
			wc_clear_notices();
			$_POST['beltoft_vat_number'] = $number;
			$_POST['billing_country']    = 'PT';

			Field::validate();

			$this->assertSame( 1, wc_notice_count( 'error' ), $number );
		}
	}

	/** Another home country does not get Portugal's check-digit test. */
	public function test_a_domestic_number_elsewhere_is_not_held_to_the_portuguese_rule() {
		$this->assertFalse( Validator::domestic_number_ok( '123456780' ) );

		add_filter( 'beltoft_commerce_config', static fn( $c ) => array_merge( $c, array( 'home_country' => 'ES' ) ) );

		$this->assertTrue( Validator::domestic_number_ok( 'B12345678' ) );
	}

	/** Trade orders go through the classic checkout, where VAT is checked and recorded. */
	public function test_the_store_api_checkout_is_refused_on_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertWPError( B2BCheckoutPage::refuse_store_api_checkout( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) ) );
		$this->assertNull( B2BCheckoutPage::refuse_store_api_checkout( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' ) ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertNull( B2BCheckoutPage::refuse_store_api_checkout( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) ) );
	}
}
