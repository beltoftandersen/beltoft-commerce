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

	/** What sale 1 left on the till account under WooCommerce's normal behaviour. */
	private function previous_customer_on_the_account(): void {
		foreach ( array(
			'billing_first_name' => 'Ana',
			'billing_last_name'  => 'Silva',
			'billing_company'    => 'Silva Lda',
			'billing_address_1'  => 'Rua X 12',
			'billing_postcode'   => '1000-001',
			'billing_city'       => 'Lisboa',
			'billing_phone'      => '912345678',
			'billing_nif'        => '123456789',
		) as $key => $value ) {
			update_user_meta( $this->pos, $key, $value );
		}
	}

	/**
	 * The till is one account serving a queue of strangers. Whatever sale 1
	 * typed must not appear on sale 2's form - only the walk-in name.
	 */
	public function test_the_next_sale_never_shows_the_previous_customers_details() {
		wp_set_current_user( $this->pos );

		foreach ( array( 'billing_last_name' => 'Silva', 'billing_company' => 'Silva Lda', 'billing_address_1' => 'Rua X 12', 'billing_phone' => '912345678', 'billing_nif' => '123456789' ) as $key => $stored ) {
			$this->assertSame( '', Checkout::prefill( $stored, $key ), $key );
		}

		$this->assertSame( Checkout::walkin_name(), Checkout::prefill( 'Ana', 'billing_first_name' ) );
	}

	public function test_the_till_keeps_the_shop_country() {
		wp_set_current_user( $this->pos );

		$this->assertSame( WC()->countries->get_base_country(), Checkout::prefill( 'ES', 'billing_country' ) );
	}

	public function test_what_the_operator_types_for_this_sale_still_wins() {
		wp_set_current_user( $this->pos );
		$_POST['post_data'] = 'billing_nif=123456789&billing_address_1=Rua+X+12';

		$this->assertSame( '123456789', Checkout::prefill( '', 'billing_nif' ) );
		$this->assertSame( 'Rua X 12', Checkout::prefill( '', 'billing_address_1' ) );

		unset( $_POST['post_data'] );
	}

	public function test_a_till_sale_does_not_save_the_customer_onto_the_till_account() {
		wp_set_current_user( $this->pos );
		$this->assertFalse( Checkout::keep_account_clean( true ) );

		wp_set_current_user( $this->b2b );
		$this->assertTrue( Checkout::keep_account_clean( true ), 'Partners keep their saved details.' );
	}

	/**
	 * Whatever reached the account anyway - the order review saves the
	 * address it is given - is wiped after each sale.
	 */
	public function test_after_a_sale_the_account_holds_only_the_walk_in_name() {
		wp_set_current_user( $this->pos );
		$this->previous_customer_on_the_account();
		WC()->customer = new WC_Customer( $this->pos );
		WC()->customer->set_billing_address_1( 'Rua X 12' );

		Checkout::clear_after_sale();

		$this->assertSame( Checkout::walkin_name(), get_user_meta( $this->pos, 'billing_first_name', true ) );

		foreach ( array( 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_postcode', 'billing_city', 'billing_phone', 'billing_nif' ) as $key ) {
			$this->assertSame( '', (string) get_user_meta( $this->pos, $key, true ), $key );
		}

		$this->assertSame( '', WC()->customer->get_billing_address_1(), 'The session copy is cleared too.' );
	}

	public function test_clearing_after_a_sale_leaves_partners_alone() {
		wp_set_current_user( $this->b2b );
		update_user_meta( $this->b2b, 'billing_company', 'Parceiro Lda' );

		Checkout::clear_after_sale();

		$this->assertSame( 'Parceiro Lda', get_user_meta( $this->b2b, 'billing_company', true ) );
	}
}
