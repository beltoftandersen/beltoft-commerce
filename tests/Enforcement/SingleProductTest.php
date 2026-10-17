<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Enforcement\SingleProduct;
use BeltoftCommerce\Pricing\Roles;

/** A product page outside the visitor's audience is a 404, by its permalink too. */
class SingleProductTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function product( ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		return $product->get_id();
	}

	public function test_it_runs_before_the_page_renders() {
		$this->assertSame( 5, has_action( 'template_redirect', array( SingleProduct::class, 'maybe_404' ) ) );
	}

	public function test_a_guest_opening_a_b2b_only_product_gets_a_404() {
		$this->go_to( get_permalink( $this->product( Taxonomy::B2B ) ) );
		$this->assertTrue( SingleProduct::should_404() );

		$this->go_to( get_permalink( $this->product( null ) ) );
		$this->assertFalse( SingleProduct::should_404() );
	}

	public function test_a_partner_opening_a_retail_only_product_gets_a_404() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->go_to( get_permalink( $this->product( null ) ) );
		$this->assertTrue( SingleProduct::should_404() );

		$this->go_to( get_permalink( $this->product( Taxonomy::BOTH ) ) );
		$this->assertFalse( SingleProduct::should_404() );
	}
}
