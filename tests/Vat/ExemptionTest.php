<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Vat\Exemption;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Vat\Field;

class ExemptionTest extends WP_UnitTestCase {

	private int $user;

	/** WC()->customer as it was, put back after the tests replace it. */
	private $customer;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '_transient_beltoft_vies_%'" );
		$this->user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $this->user );
		Exemption::reset();
		unset( $_POST['beltoft_vat_number'], $_POST['billing_country'] );
		// Reverse charge is a trade-site decision; see the retail test below.
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->customer       = WC()->customer;
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		unset( $_POST['beltoft_vat_number'], $_POST['billing_country'] );
		Exemption::reset();
		wp_set_current_user( 0 );
		WC()->customer = $this->customer;
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function cache_vies( string $country, string $number, string $status ): void {
		set_transient( 'beltoft_vies_' . $country . '_' . $number, array(
			'status'             => $status,
			'request_identifier' => 'valid' === $status ? 'WAPIAAAA' : '',
			'registered_name'    => 'valid' === $status ? 'Test SL' : '',
		), HOUR_IN_SECONDS );
	}

	private function forbid_http(): void {
		add_filter( 'pre_http_request', function () {
			$this->fail( 'VIES was called during total calculation' );
		} );
	}

	/** Reverse charge needs the billing address in the number's country. */
	private function billing_in( string $country ): void {
		WC()->customer = new WC_Customer( $this->user );
		WC()->customer->set_billing_country( $country );
	}

	public function test_a_valid_number_with_billing_in_portugal_is_not_exempt() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );
		$this->billing_in( 'PT' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_no_vat_number_is_not_exempt() {
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_portuguese_number_is_never_exempt() {
		// Domestic sale. A valid NIF is not a zero-rating trigger.
		update_user_meta( $this->user, Field::META_KEY, 'PT501234567' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_non_eu_number_is_not_exempt() {
		update_user_meta( $this->user, Field::META_KEY, 'CH123456789' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_an_eu_number_with_a_cached_valid_answer_is_exempt() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );
		$this->billing_in( 'ES' );

		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_an_eu_number_with_a_cached_invalid_answer_is_not_exempt() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'invalid' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_an_unchecked_eu_number_fails_closed_without_calling_vies() {
		/*
		 * The whole point of the non-blocking path: totals are recalculated
		 * on nearly every request, so this must neither zero-rate on a guess
		 * nor sit on a 10s member-state timeout.
		 */
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->forbid_http();

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_posted_number_beats_the_one_on_file() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );

		// Customer types a different, unverified number at checkout.
		$_POST['beltoft_vat_number'] = 'ESB99999999';
		$_POST['billing_country']    = 'ES';

		$this->assertFalse( Exemption::is_exempt() );
	}

	/**
	 * Emptying the field is how a partner buys as a normal sale. The submit
	 * posts the field empty; that is an answer, not a missing field, and it
	 * must not fall back to the number on file and zero-rate the order.
	 */
	public function test_a_field_cleared_at_checkout_charges_iva() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );

		$_POST['beltoft_vat_number'] = '';
		$_POST['billing_country']    = 'ES';

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_field_cleared_in_the_checkout_review_charges_iva() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );

		Exemption::capture_review( 'billing_country=ES&beltoft_vat_number=&billing_city=Madrid' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_the_checkout_review_payload_is_read() {
		$this->cache_vies( 'ES', 'B12345678', 'valid' );

		// The review posts the form as one url-encoded blob, not as $_POST
		// fields, which is why Field::posted() alone is not enough.
		Exemption::capture_review( 'billing_country=ES&beltoft_vat_number=ESB12345678&billing_city=Madrid' );

		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_apply_sets_the_flag_on_the_customer() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );

		$this->billing_in( 'ES' );
		WC()->customer->set_is_vat_exempt( false );

		Exemption::apply();

		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
	}

	public function test_apply_clears_a_stale_flag() {
		// Customer removed their VAT number; the session flag must not stick.
		WC()->customer = new WC_Customer( $this->user );
		WC()->customer->set_is_vat_exempt( true );

		Exemption::apply();

		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
	}

	private function account_evidence( string $number, string $status, int $age_days ): void {
		update_user_meta( $this->user, Field::META_KEY, $number );
		update_user_meta( $this->user, 'billing_country', substr( $number, 0, 2 ) );
		update_user_meta( $this->user, '_vat_check_status', $status );
		update_user_meta( $this->user, '_vies_request_date', gmdate( 'c', time() - $age_days * DAY_IN_SECONDS ) );
	}

	public function test_lapsed_cache_uses_a_recent_valid_account_answer_without_calling_vies() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'valid', 2 );
		$this->billing_in( 'DK' );

		$this->assertTrue( Exemption::is_exempt() );
		$this->assertTrue( (bool) as_has_scheduled_action( Exemption::REFRESH_HOOK, array( $this->user ), 'beltoft-commerce' ), 'a background refresh is queued' );
	}

	public function test_an_old_account_answer_charges_iva() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'valid', 31 );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_an_invalid_or_unverified_account_answer_charges_iva() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'invalid', 1 );
		$this->assertFalse( Exemption::is_exempt() );
		Exemption::reset();
		$this->account_evidence( 'DK40817921', 'unverified', 1 );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_different_number_typed_at_checkout_does_not_borrow_the_account_answer() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'valid', 1 );
		$_POST['beltoft_vat_number'] = 'DK12345678';
		$_POST['billing_country']    = 'DK';
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_cached_invalid_answer_wins_over_the_account() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'valid', 1 );
		$this->cache_vies( 'DK', '40817921', 'invalid' );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_refresh_stores_the_new_vies_answer() {
		$this->account_evidence( 'DK40817921', 'valid', 20 );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'valid' => true, 'requestIdentifier' => 'WAPINEW', 'name' => 'beltoft.net' ) ), 'headers' => array(), 'cookies' => array() );
		} );
		Exemption::refresh( $this->user );

		$this->assertSame( 'valid', get_user_meta( $this->user, '_vat_check_status', true ) );
		$this->assertSame( 'WAPINEW', get_user_meta( $this->user, '_vies_request_identifier', true ) );
		$this->assertLessThan( 60, time() - strtotime( get_user_meta( $this->user, '_vies_request_date', true ) ) );
	}

	public function test_refresh_during_an_outage_changes_nothing() {
		$this->account_evidence( 'DK40817921', 'valid', 5 );
		$before = get_user_meta( $this->user, '_vies_request_date', true );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'timeout' );
		} );
		Exemption::refresh( $this->user );
		$this->assertSame( $before, get_user_meta( $this->user, '_vies_request_date', true ) );
		$this->assertSame( 'valid', get_user_meta( $this->user, '_vat_check_status', true ) );
	}

	/**
	 * The retail site has no VAT field, no check and no evidence, so the
	 * account's number must not zero-rate a purchase there.
	 */
	public function test_a_valid_account_number_is_not_exempt_on_the_retail_host() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->cache_vies( 'ES', 'B12345678', 'valid' );
		$this->billing_in( 'ES' );

		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_the_lapsed_cache_fallback_does_not_apply_on_the_retail_host() {
		$this->forbid_http();
		$this->account_evidence( 'DK40817921', 'valid', 2 );
		$this->billing_in( 'DK' );

		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( Exemption::is_exempt() );
	}

	/** The checkout's own VIES check is what the totals after it use. */
	public function test_forget_drops_the_memoised_answer() {
		update_user_meta( $this->user, Field::META_KEY, 'ESB12345678' );
		$this->billing_in( 'ES' );
		$this->forbid_http();

		$this->assertFalse( Exemption::is_exempt(), 'nothing cached yet' );

		$this->cache_vies( 'ES', 'B12345678', 'valid' );
		$this->assertFalse( Exemption::is_exempt(), 'memoised' );

		Exemption::forget();
		$this->assertTrue( Exemption::is_exempt() );
	}
}
