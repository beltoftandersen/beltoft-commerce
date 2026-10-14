<?php

/**
 * The cached ordering-list rows carry prices, so a price change has to drop
 * them - otherwise the list shows the old price while the cart charges the
 * new one until the cache's TTL runs out.
 */
class QueryResultCacheTest extends WP_UnitTestCase {

	private function version(): string {
		return (string) get_option( 'beltoft_commerce_rows_version', '' );
	}

	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Group prices arrive by import as plain meta writes, never through the
	 * product save that already invalidates the rows.
	 */
	public function test_a_group_price_written_as_meta_drops_the_cached_rows() {
		$id     = $this->product();
		$before = $this->version();

		update_post_meta( $id, '_price_group_b2b_standard', '6' );

		$this->assertNotSame( $before, $this->version() );
	}

	/** A sale window opening or closing is a price change with no write behind it. */
	public function test_a_group_sale_boundary_drops_the_cached_rows() {
		$id     = $this->product();
		$before = $this->version();

		do_action( 'beltoft_commerce_group_price_changed', $id, 0 );

		$this->assertNotSame( $before, $this->version() );
	}
}
