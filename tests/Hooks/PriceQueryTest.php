<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Roles;

class PriceQueryTest extends WP_UnitTestCase {

	private int $cheap_b2b;
	private int $dear_b2b;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		LookupIndex::install();
		Taxonomy::register();

		// Retail order is the REVERSE of B2B order, so a query sorted with
		// the wrong table produces a visibly wrong answer.
		$a = new WC_Product_Simple();
		$a->set_regular_price( '10' );   // cheapest at retail
		$a->set_status( 'publish' );
		$a->save();
		update_post_meta( $a->get_id(), '_price_group_b2b_standard', '900' );
		// Must be visible to B2B, or the audience filter excludes it before
		// price sorting is ever reached.
		Taxonomy::set_audience( $a->get_id(), Taxonomy::BOTH );
		$this->dear_b2b = $a->get_id();

		$b = new WC_Product_Simple();
		$b->set_regular_price( '1000' ); // dearest at retail
		$b->set_status( 'publish' );
		$b->save();
		update_post_meta( $b->get_id(), '_price_group_b2b_standard', '5' );
		Taxonomy::set_audience( $b->get_id(), Taxonomy::BOTH );
		$this->cheap_b2b = $b->get_id();

		LookupIndex::update_for_product( $this->dear_b2b );
		LookupIndex::update_for_product( $this->cheap_b2b );

		$this->b2b_user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function sorted_ids(): array {
		$q = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'price',
			'order'          => 'ASC',
		) );

		return array_map( 'intval', $q->posts );
	}

	public function test_a_guest_sorts_by_retail_price() {
		wp_set_current_user( 0 );
		$ids = $this->sorted_ids();

		$this->assertLessThan(
			array_search( $this->cheap_b2b, $ids, true ),
			array_search( $this->dear_b2b, $ids, true ),
			'retail sort should put the 10 EUR product before the 1000 EUR one'
		);
	}

	public function test_a_b2b_customer_sorts_by_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		$ids = $this->sorted_ids();

		$this->assertLessThan(
			array_search( $this->dear_b2b, $ids, true ),
			array_search( $this->cheap_b2b, $ids, true ),
			'B2B sort should put the 5 EUR group price before the 900 EUR one'
		);
	}
}
