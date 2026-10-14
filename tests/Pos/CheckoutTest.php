<?php

use BeltoftCommerce\Pos\Checkout;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Vat\Field as VatField;

class PosCheckoutTest extends WP_UnitTestCase {

	private int $pos;
	private int $b2b;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->pos = self::factory()->user->create( array( 'role' => 'pos_operator' ) );
		$this->b2b = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function sample_fields(): array {
		return array(
			'billing'  => array(
				'billing_first_name' => array( 'required' => true ),
				'billing_email'      => array( 'required' => true ),
			),
			'shipping' => array( 'shipping_address_1' => array( 'required' => true ) ),
		);
	}

	public function test_nothing_is_required_of_a_walk_in_customer() {
		wp_set_current_user( $this->pos );
		$out = Checkout::fields( $this->sample_fields() );

		$this->assertFalse( $out['billing']['billing_first_name']['required'] );
		$this->assertFalse( $out['billing']['billing_email']['required'], 'An invented email is worse than none.' );
		$this->assertArrayNotHasKey( 'shipping', $out, 'The customer is holding it.' );
		$this->assertSame( Checkout::walkin_name(), $out['billing']['billing_first_name']['default'] );
	}

	public function test_trade_checkout_is_untouched() {
		wp_set_current_user( $this->b2b );
		$in  = $this->sample_fields();
		$out = Checkout::fields( $in );

		$this->assertSame( $in, $out );
		$this->assertTrue( Checkout::no_shipping( true ), 'Trade still ships.' );
	}

	/**
	 * The till must not collect customers' VAT numbers onto the operator.
	 *
	 * The NIF itself is handled by the checkout page's own field, which posts
	 * billing_nif and records it on the order. What this guards is the other
	 * half: Vat\Field keeps a VAT number against the ACCOUNT, and at a counter
	 * the account is the till's.
	 */
	public function test_the_operator_account_never_collects_a_customers_vat_number() {
		wp_set_current_user( $this->pos );

		$order = new WC_Order();
		Checkout::name_the_sale( $order, array() );

		$this->assertSame(
			'',
			(string) get_user_meta( $this->pos, VatField::META_KEY, true )
		);
	}

	public function test_a_sale_with_no_name_still_gets_one() {
		wp_set_current_user( $this->pos );

		$order = new WC_Order();
		Checkout::name_the_sale( $order, array() );

		$this->assertSame( Checkout::walkin_name(), $order->get_billing_first_name() );
	}

	public function test_the_account_bound_vat_field_does_not_render_at_the_till() {
		wp_set_current_user( $this->pos );

		ob_start();
		VatField::render();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		wp_set_current_user( $this->b2b );
		ob_start();
		VatField::render();
		$this->assertNotSame( '', trim( (string) ob_get_clean() ), 'Trade still sees it.' );
	}

	public function test_a_counter_sale_is_filed_under_the_shop_address_not_the_operators() {
		wp_set_current_user( $this->pos );

		$this->assertSame( 'encomendas@mindthetrash.pt', Checkout::walkin_email() );
		$this->assertSame( Checkout::walkin_email(), Checkout::prefill( 'pos@mindthetrash.pt', 'billing_email' ) );

		$fields = Checkout::fields( array( 'billing' => array( 'billing_email' => array( 'required' => true ) ) ) );
		$this->assertSame( Checkout::walkin_email(), $fields['billing']['billing_email']['default'] );

		$order = new WC_Order();
		Checkout::name_the_sale( $order, array() );
		$this->assertSame( Checkout::walkin_email(), $order->get_billing_email() );
	}

	public function test_a_customer_who_gives_an_address_keeps_it() {
		wp_set_current_user( $this->pos );

		$order = new WC_Order();
		$order->set_billing_email( 'cliente@example.com' );
		Checkout::name_the_sale( $order, array() );

		$this->assertSame( 'cliente@example.com', $order->get_billing_email(), 'Only an empty address is filled in.' );
	}

	public function test_trade_and_retail_prefills_are_untouched() {
		wp_set_current_user( $this->b2b );
		$this->assertSame( 'parceiro@example.com', Checkout::prefill( 'parceiro@example.com', 'billing_email' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 'shopper@example.com', Checkout::prefill( 'shopper@example.com', 'billing_email' ) );
	}

	/**
	 * The regression this exists for: a default that outlives the answer.
	 *
	 * The order-review AJAX posts the whole form as one serialised `post_data`
	 * string rather than discrete fields, so WC_Checkout::get_value()'s $_POST
	 * check misses it and this filter is reached on every re-render. An
	 * unconditional default then put the walk-in name back over whatever the
	 * operator had typed - on a multi-step checkout, each time they moved on.
	 */
	public function test_a_typed_name_survives_the_order_review_refresh() {
		wp_set_current_user( $this->pos );

		$_POST['post_data'] = 'billing_first_name=Maria+Silva&billing_email=maria%40example.com';

		$this->assertSame( 'Maria Silva', Checkout::prefill( null, 'billing_first_name' ) );
		$this->assertSame( 'maria@example.com', Checkout::prefill( null, 'billing_email' ) );

		unset( $_POST['post_data'] );
	}

	public function test_a_directly_posted_value_also_wins() {
		wp_set_current_user( $this->pos );

		$_POST['billing_first_name'] = 'João';
		$this->assertSame( 'João', Checkout::prefill( null, 'billing_first_name' ) );
		unset( $_POST['billing_first_name'] );
	}

	public function test_the_default_still_applies_when_nothing_has_been_entered() {
		wp_set_current_user( $this->pos );

		$this->assertSame( Checkout::walkin_name(), Checkout::prefill( null, 'billing_first_name' ) );
		$this->assertSame( Checkout::walkin_email(), Checkout::prefill( null, 'billing_email' ) );
	}

	/**
	 * The bug this guards: wc_format_postcode() turns '' into '-' for Portugal,
	 * and WC_Checkout formats before it tests for empty - so an optional
	 * postcode nobody filled in was rejected as invalid on every counter sale.
	 */
	public function test_an_empty_portuguese_postcode_does_not_become_a_dash() {
		wp_set_current_user( $this->pos );

		// Through the real function, because the filter is what has to hold:
		// WooCommerce's own formatting of '' for Portugal is '-', which is
		// what WC_Checkout then rejects as invalid.
		$this->assertSame( '', wc_format_postcode( '', 'PT' ) );
		$this->assertSame( '', Checkout::blank_postcode_stays_blank( '-', 'PT' ) );
	}

	public function test_a_postcode_somebody_typed_is_left_alone() {
		wp_set_current_user( $this->pos );

		$this->assertSame( '1200-430', Checkout::blank_postcode_stays_blank( '1200-430', 'PT' ) );
		$this->assertSame( 'SW1A 1AA', Checkout::blank_postcode_stays_blank( 'SW1A 1AA', 'GB' ) );
	}

	public function test_only_the_till_gets_the_empty_postcode_treatment() {
		wp_set_current_user( $this->b2b );

		// Retail and trade require a postcode, so an empty one must keep
		// failing there rather than passing silently as no address at all.
		$this->assertSame( '-', Checkout::blank_postcode_stays_blank( '-', 'PT' ) );
		$this->assertSame( '-', wc_format_postcode( '', 'PT' ) );
	}

	public function test_the_pickup_point_field_is_removed_at_the_till() {
		wp_set_current_user( $this->pos );

		add_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' );
		add_action( 'woocommerce_checkout_update_order_meta', 'cppw_save_extra_order_meta' );

		Checkout::drop_pickup_point_field();

		$this->assertFalse( has_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' ) );
		$this->assertFalse( has_action( 'woocommerce_checkout_update_order_meta', 'cppw_save_extra_order_meta' ) );
	}

	public function test_the_pickup_point_field_survives_everywhere_else() {
		wp_set_current_user( $this->b2b );

		add_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' );

		Checkout::drop_pickup_point_field();

		$this->assertNotFalse( has_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' ) );

		remove_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' );
	}
}
