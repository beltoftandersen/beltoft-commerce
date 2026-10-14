<?php

use BeltoftCommerce\Pricing\Roles;

class VariationPriceTest extends WP_UnitTestCase {

	private int $variable_id;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();

		$variable = new WC_Product_Variable();
		$variable->set_name( 'Variable under test' );
		$variable->save();

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small', 'large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );
		$variable->save();

		// retail 100 / 120, b2b 60 / 70
		foreach ( array( array( 'small', '100', '60' ), array( 'large', '120', '70' ) ) as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $variable->get_id() );
			$variation->set_attributes( array( 'size' => $spec[0] ) );
			$variation->set_regular_price( $spec[1] );
			$variation->save();
			update_post_meta( $variation->get_id(), '_price_group_b2b_standard', $spec[2] );
		}

		WC_Product_Variable::sync( $variable->get_id() );

		$this->variable_id = $variable->get_id();
		$this->b2b_user    = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function min_price(): string {
		$prices = wc_get_product( $this->variable_id )->get_variation_prices( true );

		return (string) reset( $prices['price'] );
	}

	/**
	 * Regression: WC_Product_Variation overrides get_hook_prefix() to
	 * `woocommerce_product_variation_get_`, so the `woocommerce_product_get_`
	 * filters never fire for a variation object. Hooking only the latter
	 * left every variation returning retail prices to B2B customers while
	 * the parent's price *range* resolved correctly — a range that
	 * disagreed with the price of the variation actually selected.
	 */
	public function test_an_individual_variation_object_resolves_by_group() {
		wp_set_current_user( $this->b2b_user );

		$variation_id = wc_get_product( $this->variable_id )->get_children()[0];

		$this->assertSame( '60', wc_get_product( $variation_id )->get_price() );
	}

	public function test_an_individual_variation_is_retail_for_a_guest() {
		wp_set_current_user( 0 );

		$variation_id = wc_get_product( $this->variable_id )->get_children()[0];

		$this->assertSame( '100', wc_get_product( $variation_id )->get_price() );
	}

	public function test_edit_context_still_returns_the_raw_stored_price() {
		wp_set_current_user( $this->b2b_user );

		$variation_id = wc_get_product( $this->variable_id )->get_children()[0];

		$this->assertSame( '100', wc_get_product( $variation_id )->get_price( 'edit' ) );
	}

	/**
	 * Regression: WooCommerce 11 core (ScheduledSalePriceReconciler) and
	 * WooCommerce Subscriptions (WCS_ATT_Product_Price_Filters) both sit on
	 * the price filter at priority 99 and overwrite earlier values. The
	 * resolver is the single source of truth for a group's price, so it
	 * must run after them. Neither plugin is loaded in this suite, so this
	 * simulates one.
	 */
	public function test_the_resolver_wins_over_a_late_priority_competitor() {
		add_filter( 'woocommerce_product_variation_get_price', function () {
			return '999';
		}, 99 );

		wp_set_current_user( $this->b2b_user );
		$variation_id = wc_get_product( $this->variable_id )->get_children()[0];

		$this->assertSame( '60', wc_get_product( $variation_id )->get_price() );
	}

	public function test_retail_sees_the_retail_range() {
		wp_set_current_user( 0 );

		$this->assertSame( '100.00', $this->min_price() );
	}

	public function test_b2b_sees_the_b2b_range() {
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( '60.00', $this->min_price() );
	}

	/**
	 * Without the pricing group in the variation price cache hash, groups
	 * collide and one sees the other's range. WooCommerce 11.0 changed the
	 * default hashing behaviour and the platform advisory is explicit that
	 * extensions returning prices which vary by user must participate in
	 * this filter. Architecture §2.5.
	 */
	public function test_warming_as_retail_then_reading_as_b2b_does_not_leak() {
		wp_set_current_user( 0 );
		$this->min_price(); // warm

		wp_set_current_user( $this->b2b_user );
		$this->assertSame( '60.00', $this->min_price() );
	}

	public function test_warming_as_b2b_then_reading_as_retail_does_not_leak() {
		wp_set_current_user( $this->b2b_user );
		$this->min_price(); // warm

		wp_set_current_user( 0 );
		$this->assertSame( '100.00', $this->min_price() );
	}

	/**
	 * get_variation_prices_hash() is not public API, so the hash is checked
	 * through the filter that builds it — which is the thing this task is
	 * required to participate in.
	 */
	public function test_the_pricing_group_is_folded_into_the_cache_hash() {
		wp_set_current_user( 0 );
		$retail_hash = apply_filters( 'woocommerce_get_variation_prices_hash', array(), wc_get_product( $this->variable_id ), true );

		wp_set_current_user( $this->b2b_user );
		$b2b_hash = apply_filters( 'woocommerce_get_variation_prices_hash', array(), wc_get_product( $this->variable_id ), true );

		$this->assertNotSame( $retail_hash, $b2b_hash );
		$this->assertNotEquals( md5( wp_json_encode( $retail_hash ) ), md5( wp_json_encode( $b2b_hash ) ) );
	}
}
