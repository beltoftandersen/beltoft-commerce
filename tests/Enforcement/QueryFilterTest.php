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

	/** A taxonomy archive query, as WordPress builds it: no post_type. */
	private function archive_ids( array $args ): array {
		$q = new WP_Query( array_merge( array(
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		), $args ) );

		return array_map( 'intval', $q->posts );
	}

	private function in_category( string $slug ): void {
		$term = wp_insert_term( $slug, 'product_cat' );

		foreach ( array( $this->retail_only, $this->b2b_only, $this->both ) as $id ) {
			wp_set_object_terms( $id, (int) $term['term_id'], 'product_cat' );
		}
	}

	/**
	 * Category, tag and brand archives reach pre_get_posts with post_type
	 * still empty; WordPress fills it in later. A B2B customer saw retail-only
	 * products on every category page.
	 */
	public function test_a_b2b_customer_on_a_category_archive_sees_only_their_catalog() {
		$this->in_category( 'cosmeticos' );
		wp_set_current_user( $this->b2b_user );

		$ids = $this->archive_ids( array( 'product_cat' => 'cosmeticos' ) );

		$this->assertContains( $this->b2b_only, $ids );
		$this->assertContains( $this->both, $ids );
		$this->assertNotContains( $this->retail_only, $ids );
	}

	public function test_a_category_archive_reached_through_a_tax_query_is_filtered_too() {
		$this->in_category( 'cosmeticos' );
		wp_set_current_user( $this->b2b_user );

		$ids = $this->archive_ids( array(
			'tax_query' => array(
				array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'cosmeticos' ),
			),
		) );

		$this->assertNotContains( $this->retail_only, $ids );
	}

	public function test_a_guest_on_a_category_archive_does_not_see_b2b_only_products() {
		$this->in_category( 'cosmeticos' );
		wp_set_current_user( 0 );

		$ids = $this->archive_ids( array( 'product_cat' => 'cosmeticos' ) );

		$this->assertContains( $this->retail_only, $ids );
		$this->assertNotContains( $this->b2b_only, $ids );
	}

	/**
	 * A taxonomy shared with other post types is not a product archive:
	 * adding the audience clause there would hide every non-product.
	 */
	public function test_an_archive_of_a_shared_taxonomy_is_untouched() {
		register_taxonomy( 'shared_label', array( 'post', 'product' ) );
		$post = $this->factory->post->create();
		wp_set_object_terms( $post, 'x', 'shared_label' );
		wp_set_current_user( $this->b2b_user );

		$this->assertContains( $post, $this->archive_ids( array( 'shared_label' => 'x', 'post_type' => 'any' ) ) );

		unregister_taxonomy( 'shared_label' );
	}

	public function test_non_product_queries_are_untouched() {
		wp_set_current_user( $this->b2b_user );
		$post = $this->factory->post->create();

		$q = new WP_Query( array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1 ) );

		$this->assertContains( $post, array_map( 'intval', $q->posts ) );
	}

	/** A query var cannot switch the filter off; only code can (without_audience). */
	public function test_the_bypass_cannot_come_from_query_vars() {
		wp_set_current_user( $this->b2b_user );

		$this->assertNotContains( $this->retail_only, $this->ids( array( 'beltoft_bypass_audience' => true ) ) );
		$this->assertContains( $this->retail_only, BeltoftCommerce\Enforcement\QueryFilter::without_audience( fn() => $this->ids() ) );
	}

	public function test_post_type_any_is_filtered() {
		wp_set_current_user( $this->b2b_user );

		$this->assertNotContains( $this->retail_only, $this->ids( array( 'post_type' => 'any' ) ) );
	}

	/** A relation OR in the query does not make the audience one alternative of several. */
	public function test_an_or_relation_does_not_dilute_the_audience() {
		wp_set_current_user( $this->b2b_user );
		$cat = self::factory()->term->create( array( 'taxonomy' => 'product_cat' ) );
		wp_set_object_terms( $this->both, array( $cat ), 'product_cat' );

		$ids = $this->ids( array( 'tax_query' => array(
			'relation' => 'OR',
			array( 'taxonomy' => 'product_cat', 'terms' => array( $cat ) ),
			array( 'taxonomy' => 'product_cat', 'terms' => array( $cat ), 'operator' => 'NOT IN' ),
		) ) );

		$this->assertContains( $this->both, $ids );
		$this->assertNotContains( $this->retail_only, $ids );
	}

	/** Bricks loops may not ask for suppressed filters (which the id exclusion trusts). */
	public function test_bricks_query_vars_lose_suppress_filters() {
		$vars = BeltoftCommerce\Enforcement\QueryFilter::clean_bricks_query_vars( array( 'post_type' => 'product', 'suppress_filters' => true, 'beltoft_bypass_audience' => true ) );

		$this->assertSame( array( 'post_type' => 'product' ), $vars );
	}
}
