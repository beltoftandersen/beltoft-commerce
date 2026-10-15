<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Vat\OrderEvidence;
use BeltoftCommerce\Vat\TaxDisplay;
use BeltoftCommerce\Vat\Validator;

class OrderEvidenceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '_transient_beltoft_vies_%'" );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		unset( $_POST['beltoft_vat_number'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function mock_vies( string $outcome ): void {
		add_filter( 'pre_http_request', function () use ( $outcome ) {
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

	private function order_for( string $vat, string $country ): WC_Order {
		$order = new WC_Order();
		$order->save();

		OrderEvidence::write( $order, Validator::decide( $vat, $country ) );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_a_valid_es_number_stores_a_consultation_number() {
		$this->mock_vies( 'valid' );
		$order = $this->order_for( 'ESB12345678', 'ES' );

		$this->assertSame( 'valid', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
		$this->assertSame( 'WAPI-TEST-1', $order->get_meta( OrderEvidence::REQUEST_ID ) );
		$this->assertNotEmpty( $order->get_meta( OrderEvidence::REQUEST_DATE ) );
		$this->assertSame( 'ACME SL', $order->get_meta( OrderEvidence::REGISTERED ) );
	}

	public function test_a_pt_number_is_domestic_with_no_consultation_number() {
		$order = $this->order_for( 'PT999999999', 'PT' );

		$this->assertSame( 'domestic', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
		$this->assertSame( '', $order->get_meta( OrderEvidence::REQUEST_ID ) );
	}

	public function test_an_unreachable_check_is_recorded_and_noted_for_review() {
		$this->mock_vies( 'unreachable' );
		$order = $this->order_for( 'ESB12345678', 'ES' );

		$this->assertSame( 'unverified', $order->get_meta( OrderEvidence::CHECK_STATUS ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'VIES', $notes[0]->content );
	}

	public function test_all_four_required_meta_keys_are_present() {
		$this->mock_vies( 'valid' );
		$order = $this->order_for( 'ESB12345678', 'ES' );

		foreach ( array(
			OrderEvidence::VAT_NUMBER,
			OrderEvidence::CHECK_STATUS,
			OrderEvidence::REQUEST_ID,
			OrderEvidence::REQUEST_DATE,
		) as $key ) {
			$this->assertNotSame( '', (string) $order->get_meta( $key ), "{$key} must be recorded" );
		}
	}

	public function test_b2b_sessions_see_ex_iva_and_retail_sees_incl() {
		update_option( 'woocommerce_tax_display_shop', 'incl' );

		wp_set_current_user( 0 );
		$this->assertSame( 'incl', get_option( 'woocommerce_tax_display_shop' ) );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );
		$this->assertSame( 'excl', get_option( 'woocommerce_tax_display_shop' ) );
	}

	/**
	 * Display and exemption are independent: a B2B customer with no valid
	 * VAT number sees ex-IVA prices and still pays Portuguese IVA.
	 */
	public function test_a_b2b_customer_without_a_vat_number_is_not_exempt() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertSame( 'excl', get_option( 'woocommerce_tax_display_shop' ) );
		$this->assertFalse( Validator::decide( '', 'PT' )->exempt );
	}

	/** An order placed with the checkout's VAT field, the way WooCommerce builds it. */
	private function checkout_order( int $user_id, string $vat, bool $exempt ): WC_Order {
		$order = new WC_Order();
		$order->set_customer_id( $user_id );
		$order->add_meta_data( 'is_vat_exempt', $exempt ? 'yes' : 'no', true );
		$order->save();

		$_POST['beltoft_vat_number'] = $vat;
		OrderEvidence::attach( $order, array( 'billing_country' => substr( $vat, 0, 2 ) ) );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	private function partner_confirmed( string $vat ): int {
		$user = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		update_user_meta( $user, '_vat_number', $vat );
		update_user_meta( $user, OrderEvidence::CHECK_STATUS, 'valid' );
		update_user_meta( $user, OrderEvidence::REQUEST_ID, 'WAPI-ACCOUNT' );
		update_user_meta( $user, OrderEvidence::REQUEST_DATE, gmdate( 'c', time() - 3 * DAY_IN_SECONDS ) );
		update_user_meta( $user, OrderEvidence::REGISTERED, 'Partner ApS' );

		return $user;
	}

	/**
	 * VIES down at checkout, totals zero-rated on the account's confirmation:
	 * the order carries that confirmation, not "IVA has been charged".
	 */
	public function test_an_outage_on_a_zero_rated_order_records_the_accounts_confirmation() {
		$this->mock_vies( 'unreachable' );
		$order = $this->checkout_order( $this->partner_confirmed( 'DK40817921' ), 'DK40817921', true );

		$this->assertSame( 'valid', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
		$this->assertSame( 'WAPI-ACCOUNT', $order->get_meta( OrderEvidence::REQUEST_ID ) );
		$this->assertSame( 'Partner ApS', $order->get_meta( OrderEvidence::REGISTERED ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'WAPI-ACCOUNT', $notes[0]->content );
		$this->assertStringNotContainsString( 'IVA has been charged', $notes[0]->content );
	}

	public function test_an_outage_on_an_order_that_was_charged_iva_stays_for_review() {
		$this->mock_vies( 'unreachable' );
		$order = $this->checkout_order( $this->partner_confirmed( 'DK40817921' ), 'DK40817921', false );

		$this->assertSame( 'unverified', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
	}

	/** The account's confirmation is of its own number, not of one typed at checkout. */
	public function test_an_outage_does_not_lend_the_accounts_confirmation_to_another_number() {
		$this->mock_vies( 'unreachable' );
		$order = $this->checkout_order( $this->partner_confirmed( 'DK40817921' ), 'DK12345678', true );

		$this->assertSame( 'unverified', $order->get_meta( OrderEvidence::CHECK_STATUS ) );
	}
}
