<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class CartGuardTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $both_priced;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$this->retail_only = $this->make( null, null );
		$this->both_priced = $this->make( Taxonomy::BOTH, '60' );
		$this->b2b_user    = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tearDown(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make( ?string $audience, ?string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_stock_status( 'instock' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		if ( $price ) {
			update_post_meta( $product->get_id(), '_price_group_b2b_standard', $price );
		}

		return $product->get_id();
	}

	public function test_a_b2b_customer_cannot_add_a_retail_only_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertFalse( WC()->cart->add_to_cart( $this->retail_only ) );
	}

	/**
	 * The validation the form handler, wc-ajax and the Store API run -
	 * add_to_cart() alone stops at is_purchasable() and never reaches it.
	 */
	public function test_the_add_to_cart_validation_refuses_out_of_audience() {
		wp_set_current_user( $this->b2b_user );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->retail_only, 1 ) );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->both_priced, 1 ) );
		wc_clear_notices();
	}

	public function test_a_b2b_customer_can_add_a_visible_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertNotFalse( WC()->cart->add_to_cart( $this->both_priced ) );
	}

	public function test_a_guest_can_add_a_retail_product() {
		wp_set_current_user( 0 );

		$this->assertNotFalse( WC()->cart->add_to_cart( $this->retail_only ) );
	}

	/**
	 * Role, approval status and catalog membership can all change
	 * mid-session, so checkout re-validates rather than trusting
	 * add-to-cart time. Architecture §2.8.
	 */
	public function test_checkout_revalidates_when_the_catalog_changes_mid_session() {
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->both_priced );

		// Removed from the B2B audience after it was added.
		Taxonomy::set_audience( $this->both_priced, Taxonomy::RETAIL );

		wc_clear_notices();
		do_action( 'woocommerce_check_cart_items' );

		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_checkout_passes_when_the_cart_is_still_valid() {
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->both_priced );

		wc_clear_notices();
		do_action( 'woocommerce_check_cart_items' );

		$this->assertEmpty( wc_get_notices( 'error' ) );
	}
}
