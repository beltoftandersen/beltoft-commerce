<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class QueryFilterTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $b2b_only;
	private int $both;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$this->retail_only = $this->make( null );
		$this->b2b_only    = $this->make( Taxonomy::B2B );
		$this->both        = $this->make( Taxonomy::BOTH );
		$this->b2b_user    = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make( ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		return $product->get_id();
	}

	private function ids( array $args = array() ): array {
		$q = new WP_Query( array_merge( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		), $args ) );

		return array_map( 'intval', $q->posts );
	}

	public function test_a_guest_sees_retail_and_both_but_not_b2b_only() {
		wp_set_current_user( 0 );
		$ids = $this->ids();

		$this->assertContains( $this->retail_only, $ids );
		$this->assertContains( $this->both, $ids );
		$this->assertNotContains( $this->b2b_only, $ids );
	}

	public function test_a_b2b_customer_sees_b2b_and_both_but_not_retail_only() {
		wp_set_current_user( $this->b2b_user );
		$ids = $this->ids();

		$this->assertContains( $this->b2b_only, $ids );
		$this->assertContains( $this->both, $ids );
		$this->assertNotContains( $this->retail_only, $ids );
	}

	/**
	 * A Bricks query loop is its own WP_Query, so hooking
	 * woocommerce_product_query_tax_query would miss it entirely and a B2B
	 * customer would see retail-only products in any builder-driven grid.
	 * Architecture §2.11.
	 */
	public function test_an_arbitrary_wp_query_is_filtered_too() {
		wp_set_current_user( $this->b2b_user );

		$ids = $this->ids( array( 'no_found_rows' => true, 'suppress_filters' => false ) );

		$this->assertNotContains( $this->retail_only, $ids );
	}

	public function test_a_query_with_its_own_tax_query_still_gets_filtered() {
		wp_set_current_user( $this->b2b_user );

		$ids = $this->ids( array(
			'tax_query' => array(
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => 'simple',
				),
			),
		) );

		$this->assertNotContains( $this->retail_only, $ids );
		$this->assertContains( $this->b2b_only, $ids );
	}

	public function test_admin_queries_are_untouched() {
		set_current_screen( 'edit-product' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$ids = $this->ids();

		$this->assertContains( $this->retail_only, $ids );
		$this->assertContains( $this->b2b_only, $ids );

		set_current_screen( 'front' );
	}

	public function test_non_product_queries_are_untouched() {
		wp_set_current_user( $this->b2b_user );
		$post = $this->factory->post->create();

		$q = new WP_Query( array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1 ) );

		$this->assertContains( $post, array_map( 'intval', $q->posts ) );
	}
}
