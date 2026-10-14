<?php

use BeltoftCommerce\Frontend\OrderingBreakpoints;

class OrderingBreakpointsTest extends WP_UnitTestCase {

	/**
	 * Every width the ordering page changes shape at has to be one of Bricks'
	 * four. A number that is merely close would put the CSS and the builder's
	 * responsive controls on different pixels.
	 */
	public function test_every_breakpoint_is_one_of_bricks_own() {
		$bricks = array( 1279, 991, 767, 478 );

		$this->assertContains( OrderingBreakpoints::cart_offcanvas_max(), $bricks );
		$this->assertContains( OrderingBreakpoints::filters_collapse_max(), $bricks );
		$this->assertContains( OrderingBreakpoints::code_wrap_max(), $bricks );
		$this->assertContains( OrderingBreakpoints::three_column_min() - 1, $bricks );
	}

	/**
	 * The three-column minimum is arithmetic, not taste: the filter column at
	 * its 228px floor, the cart at 292, the product row's own 664, 48px of
	 * page padding and two 24px gutters.
	 */
	public function test_the_three_column_minimum_is_what_the_columns_actually_need() {
		$this->assertSame( 228 + 664 + 292 + 48 + 24 + 24, OrderingBreakpoints::three_column_min() );
	}

	/**
	 * The filters survive narrower screens than the cart does: a filter column
	 * costs less width and is worth more while browsing.
	 */
	public function test_the_filters_fold_later_than_the_cart_leaves() {
		$this->assertLessThan( OrderingBreakpoints::cart_offcanvas_max(), OrderingBreakpoints::filters_collapse_max() );
	}
}
