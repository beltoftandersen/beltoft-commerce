<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Enforcement\FeedGuard;
use BeltoftCommerce\Enforcement\NavCounts;
use BeltoftCommerce\Enforcement\SearchIndex;
use BeltoftCommerce\Pricing\Roles;

class SearchAndFeedsTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $both;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$this->retail_only = $this->make( null );
		$this->both        = $this->make( Taxonomy::BOTH );
		$this->b2b_user    = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make( ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Searchable widget' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		return $product->get_id();
	}

	/**
	 * Relevanssi and Elasticsearch hold their own copy of the content, so
	 * a query filter does not reach them. Architecture §2.8.
	 */
	public function test_search_hits_are_filtered_for_b2b() {
		wp_set_current_user( $this->b2b_user );

		$hits = SearchIndex::filter_hits( array( array(
			(object) array( 'ID' => $this->retail_only ),
			(object) array( 'ID' => $this->both ),
		) ) );

		$ids = wp_list_pluck( $hits[0], 'ID' );

		$this->assertNotContains( $this->retail_only, $ids );
		$this->assertContains( $this->both, $ids );
	}

	public function test_search_hits_are_untouched_for_a_guest() {
		wp_set_current_user( 0 );

		$hits = SearchIndex::filter_hits( array( array(
			(object) array( 'ID' => $this->retail_only ),
			(object) array( 'ID' => $this->both ),
		) ) );

		$this->assertCount( 2, $hits[0] );
	}

	public function test_a_feed_excludes_out_of_audience_products() {
		wp_set_current_user( $this->b2b_user );

		$ids = FeedGuard::filter_ids( array( $this->retail_only, $this->both ) );

		$this->assertSame( array( $this->both ), array_values( $ids ) );
	}

	/**
	 * Category counts are stored globally per term, so they are hidden for
	 * restricted sessions rather than recalculated. Architecture §2.8.
	 */
	public function test_category_counts_are_hidden_for_a_restricted_session() {
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( '', NavCounts::filter_count( '(12)' ) );
	}

	public function test_category_counts_are_shown_for_a_guest() {
		wp_set_current_user( 0 );

		$this->assertSame( '(12)', NavCounts::filter_count( '(12)' ) );
	}
}
