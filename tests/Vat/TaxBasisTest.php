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
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_retail_prices_still_include_tax() {
		wp_set_current_user( 0 );

		$this->assertSame( 'yes', get_option( 'woocommerce_prices_include_tax' ) );
		$this->assertTrue( wc_prices_include_tax() );
	}

	public function test_a_b2b_session_reads_prices_as_net() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertSame( 'no', get_option( 'woocommerce_prices_include_tax' ) );
		$this->assertFalse( wc_prices_include_tax() );
	}

	public function test_the_raw_option_read_is_covered_too() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		/*
		 * WC_Checkout::create_order() stamps the order from a direct
		 * get_option() call, not wc_prices_include_tax(), so filtering only
		 * the function would leave a B2B order claiming its net totals were
		 * gross. Asserted separately because the two readers are separate.
		 */
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertFalse( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
	}

	/**
	 * With a 23% rate in force, the group price resolved for the product (not
	 * one passed in) is its net price: 40, not 40/1.23. Without TaxBasis the
	 * same call strips IVA out of it, which the retail half asserts.
	 */
	public function test_a_b2b_price_is_charged_as_entered() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		WC_Tax::_insert_tax_rate( array(
			'tax_rate_country'  => '',
			'tax_rate'          => '23.0000',
			'tax_rate_name'     => 'IVA',
			'tax_rate_priority' => 1,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => '',
		) );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_tax_status( 'taxable' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '40' );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );
		$this->assertEqualsWithDelta( 40.0, (float) wc_get_price_excluding_tax( wc_get_product( $product->get_id() ) ), 0.001 );

		// Counter-check: retail's 100 is gross, so its net is 100/1.23.
		wp_set_current_user( 0 );
		$this->assertEqualsWithDelta( 81.30, (float) wc_get_price_excluding_tax( wc_get_product( $product->get_id() ) ), 0.01 );
	}
}
