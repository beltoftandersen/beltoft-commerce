<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class PurchasableTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $retail_only_priced;
	private int $both_priced;
	private int $both_unpriced;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$this->retail_only   = $this->make( null, null );
		// Retail-only BUT carrying a B2B price, so that audience
		// enforcement is what excludes it rather than fail-closed pricing.
		$this->retail_only_priced = $this->make( null, '60' );
		$this->both_priced   = $this->make( Taxonomy::BOTH, '60' );
		$this->both_unpriced = $this->make( Taxonomy::BOTH, null );
		$this->b2b_user      = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
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

	public function test_a_guest_can_purchase_a_retail_product() {
		wp_set_current_user( 0 );

		$this->assertTrue( wc_get_product( $this->retail_only )->is_purchasable() );
	}

	public function test_b2b_cannot_purchase_a_retail_only_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertFalse( wc_get_product( $this->retail_only )->is_purchasable() );
	}

	/**
	 * The product HAS a B2B price, so if this passes only because the price
	 * is missing the test proves nothing. Audience must be what excludes it.
	 */
	public function test_b2b_cannot_purchase_a_priced_retail_only_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( '60', wc_get_product( $this->retail_only_priced )->get_price() );
		$this->assertFalse( wc_get_product( $this->retail_only_priced )->is_purchasable() );
	}

	public function test_b2b_can_purchase_a_visible_priced_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertTrue( wc_get_product( $this->both_priced )->is_purchasable() );
	}

	/**
	 * Fail closed: visible to the group but unpriced for it means not
	 * buyable, and must not fall back to retail.
	 */
	public function test_b2b_cannot_purchase_a_visible_but_unpriced_product() {
		wp_set_current_user( $this->b2b_user );

		$this->assertFalse( wc_get_product( $this->both_unpriced )->is_purchasable() );
	}
}
