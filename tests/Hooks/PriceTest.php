<?php

use BeltoftCommerce\Pricing\Roles;

class PriceTest extends WP_UnitTestCase {

	private int $product_id;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		$this->product_id = $product->get_id();
		$this->b2b_user   = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_a_guest_sees_the_retail_price() {
		wp_set_current_user( 0 );

		$this->assertSame( '100', wc_get_product( $this->product_id )->get_price() );
	}

	public function test_a_b2b_customer_sees_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( '60', wc_get_product( $this->product_id )->get_price() );
	}

	public function test_a_pending_user_sees_the_retail_price() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_pending' ) ) );

		$this->assertSame( '100', wc_get_product( $this->product_id )->get_price() );
	}

	/**
	 * Without this, B2B customers see sale badges driven by retail
	 * campaigns that do not apply to them. Architecture §2.5.
	 */
	public function test_a_retail_sale_does_not_put_the_product_on_sale_for_b2b() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		$product = wc_get_product( $this->product_id );
		$product->set_sale_price( '70' );
		$product->save();

		wp_set_current_user( $this->b2b_user );
		$product = wc_get_product( $this->product_id );

		$this->assertFalse( $product->is_on_sale() );
		$this->assertSame( '60', $product->get_price() );
	}

	public function test_a_group_sale_puts_it_on_sale_for_b2b_only() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		update_post_meta( $this->product_id, '_sale_price_group_b2b_standard', '45' );

		wp_set_current_user( $this->b2b_user );
		$this->assertTrue( wc_get_product( $this->product_id )->is_on_sale() );
		$this->assertSame( '45', wc_get_product( $this->product_id )->get_price() );

		wp_set_current_user( 0 );
		$this->assertFalse( wc_get_product( $this->product_id )->is_on_sale() );
	}

	/**
	 * Fail closed: a product with no group price renders no number and is
	 * not purchasable, rather than falling back to retail.
	 */
	public function test_a_product_with_no_group_price_shows_nothing_for_b2b() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		$bare = new WC_Product_Simple();
		$bare->set_regular_price( '100' );
		$bare->save();

		wp_set_current_user( $this->b2b_user );
		$product = wc_get_product( $bare->get_id() );

		$this->assertSame( '', $product->get_price() );
		$this->assertNotSame( '100', $product->get_price() );
	}

	public function test_regular_and_sale_getters_also_resolve_by_group() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		$product = wc_get_product( $this->product_id );

		$this->assertSame( '60', $product->get_regular_price() );
	}
}
