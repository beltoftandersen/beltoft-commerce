<?php

use BeltoftCommerce\Frontend\RelevanssiSearch;

class RelevanssiSearchTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		remove_all_filters( 'bricks/posts/query_vars' );
		RelevanssiSearch::register();
	}

	public function tearDown(): void {
		remove_all_filters( 'bricks/posts/query_vars' );
		parent::tearDown();
	}

	public function test_it_runs_after_the_bricks_query_filter_merge() {
		/*
		 * The regression this locks down. Bricks Query Filters merge the
		 * search term onto this same hook at priority 999
		 * (query-filters.php:1311). At any earlier priority this callback
		 * sees no `s`, opts out, and a SKU search silently falls back to
		 * core's title/content search — which finds nothing, with no error.
		 */
		$priority = has_filter( 'bricks/posts/query_vars', array( RelevanssiSearch::class, 'use_relevanssi' ) );

		$this->assertIsInt( $priority );
		$this->assertGreaterThan( 999, $priority );
	}

	public function test_a_query_with_no_search_term_is_untouched() {
		$in  = array( 'post_type' => 'product' );
		$out = RelevanssiSearch::use_relevanssi( $in );

		$this->assertSame( $in, $out );
		$this->assertArrayNotHasKey( 'relevanssi', $out );
	}

	public function test_a_non_array_is_returned_unchanged() {
		$this->assertSame( 'nonsense', RelevanssiSearch::use_relevanssi( 'nonsense' ) );
	}
}
