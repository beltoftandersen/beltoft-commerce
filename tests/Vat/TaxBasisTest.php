<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Vat\TaxBasis;

class TaxBasisTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		TaxBasis::register();
	}

	public function tearDown(): void {
		remove_all_filters( 'option_woocommerce_prices_include_tax' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_retail_prices_still_include_tax() {
		wp_set_current_user( 0 );

		$this->assertSame( 'yes', get_option( 'woocommerce_prices_include_tax' ) );
		$this->assertTrue( wc_prices_include_tax() );
	}

	public function test_a_b2b_session_reads_prices_as_net() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertSame( 'no', get_option( 'woocommerce_prices_include_tax' ) );
		$this->assertFalse( wc_prices_include_tax() );
	}

	public function test_the_raw_option_read_is_covered_too() {
		/*
		 * WC_Checkout::create_order() stamps the order from a direct
		 * get_option() call, not wc_prices_include_tax(), so filtering only
		 * the function would leave a B2B order claiming its net totals were
		 * gross. Asserted separately because the two readers are separate.
		 */
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertFalse( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
	}

	public function test_a_b2b_price_is_charged_as_entered() {
		$user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $user );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '40' );

		// The stored 40 is the net price, so the line is 40 — not 40/1.23.
		$this->assertSame( 40.0, (float) wc_get_price_excluding_tax( wc_get_product( $product->get_id() ), array( 'price' => '40' ) ) );
	}
}
