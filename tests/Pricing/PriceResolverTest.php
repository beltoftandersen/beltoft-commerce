<?php

use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\Registry;

class PriceResolverTest extends WP_UnitTestCase {

	private function product_with( array $meta, string $regular = '100', string $sale = '' ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( $regular );

		if ( '' !== $sale ) {
			$product->set_sale_price( $sale );
		}

		$product->save();

		foreach ( $meta as $key => $value ) {
			update_post_meta( $product->get_id(), $key, $value );
		}

		return wc_get_product( $product->get_id() );
	}

	public function test_retail_uses_core_pricing() {
		$product = $this->product_with( array() );

		$this->assertSame( '100', PriceResolver::resolve( $product, Registry::retail_slug() ) );
	}

	public function test_it_returns_the_group_regular_price() {
		$product = $this->product_with( array( '_price_group_b2b_standard' => '60' ) );

		$this->assertSame( '60', PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	public function test_a_group_sale_price_wins_inside_its_window() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'                 => '60',
			'_sale_price_group_b2b_standard'            => '45',
			'_sale_price_dates_from_group_b2b_standard' => strtotime( '-1 day' ),
			'_sale_price_dates_to_group_b2b_standard'   => strtotime( '+1 day' ),
		) );

		$this->assertSame( '45', PriceResolver::resolve( $product, 'b2b_standard' ) );
		$this->assertTrue( PriceResolver::is_on_sale( $product, 'b2b_standard' ) );
	}

	public function test_a_group_sale_price_is_ignored_before_its_window() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'                 => '60',
			'_sale_price_group_b2b_standard'            => '45',
			'_sale_price_dates_from_group_b2b_standard' => strtotime( '+1 day' ),
			'_sale_price_dates_to_group_b2b_standard'   => strtotime( '+2 days' ),
		) );

		$this->assertSame( '60', PriceResolver::resolve( $product, 'b2b_standard' ) );
		$this->assertFalse( PriceResolver::is_on_sale( $product, 'b2b_standard' ) );
	}

	public function test_a_group_sale_price_is_ignored_after_its_window() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'                 => '60',
			'_sale_price_group_b2b_standard'            => '45',
			'_sale_price_dates_from_group_b2b_standard' => strtotime( '-3 days' ),
			'_sale_price_dates_to_group_b2b_standard'   => strtotime( '-1 day' ),
		) );

		$this->assertSame( '60', PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	public function test_a_sale_price_with_no_dates_applies() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'      => '60',
			'_sale_price_group_b2b_standard' => '45',
		) );

		$this->assertSame( '45', PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	/**
	 * MUST NOT fall through to retail. An incomplete import would otherwise
	 * quietly show retail pricing to wholesale customers and nothing in the
	 * storefront would look wrong. Architecture §2.5.
	 */
	public function test_it_fails_closed_when_no_group_price_exists() {
		$product = $this->product_with( array() );

		$this->assertNull( PriceResolver::resolve( $product, 'b2b_standard' ) );
		$this->assertNull( PriceResolver::regular( $product, 'b2b_standard' ) );
	}

	public function test_an_empty_string_group_price_also_fails_closed() {
		$product = $this->product_with( array( '_price_group_b2b_standard' => '' ) );

		$this->assertNull( PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	public function test_a_retail_sale_does_not_affect_the_group() {
		$product = $this->product_with( array( '_price_group_b2b_standard' => '60' ), '100', '70' );

		$this->assertSame( '60', PriceResolver::resolve( $product, 'b2b_standard' ) );
		$this->assertFalse( PriceResolver::is_on_sale( $product, 'b2b_standard' ) );
	}

	public function test_a_group_sale_does_not_affect_retail() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'      => '60',
			'_sale_price_group_b2b_standard' => '45',
		) );

		$this->assertSame( '100', PriceResolver::resolve( $product, Registry::retail_slug() ) );
		$this->assertFalse( PriceResolver::is_on_sale( $product, Registry::retail_slug() ) );
	}

	/**
	 * A "sale" price above the regular price is not a sale.
	 */
	public function test_a_higher_sale_price_is_not_on_sale() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'      => '60',
			'_sale_price_group_b2b_standard' => '80',
		) );

		$this->assertFalse( PriceResolver::is_on_sale( $product, 'b2b_standard' ) );
	}

	public function test_zero_is_a_valid_group_price_and_not_treated_as_missing() {
		$product = $this->product_with( array( '_price_group_b2b_standard' => '0' ) );

		$this->assertSame( '0', PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	/** ...and is not charged either: the regular price is. */
	public function test_a_higher_sale_price_is_not_charged() {
		$product = $this->product_with( array(
			'_price_group_b2b_standard'      => '60',
			'_sale_price_group_b2b_standard' => '80',
		) );

		$this->assertSame( '60', PriceResolver::resolve( $product, 'b2b_standard' ) );
	}

	/** Retail's own sale follows WooCommerce's schedule: not before it starts. */
	public function test_an_inherited_sale_waits_for_its_start_date() {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_sale_price( '70' );
		$product->set_date_on_sale_from( gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$this->assertSame( '100', PriceResolver::resolve( $product, Registry::retail_slug() ) );

		$product->set_date_on_sale_from( '' );
		$product->save();

		$this->assertSame( '70', PriceResolver::resolve( wc_get_product( $product->get_id() ), Registry::retail_slug() ) );
	}
}
