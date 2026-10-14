<?php

use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Roles;

class LookupIndexTest extends WP_UnitTestCase {

	private int $variable_id;
	private int $retail_only_id;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		LookupIndex::install();

		$variable = new WC_Product_Variable();
		$variable->save();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small', 'large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );
		$variable->save();

		foreach ( array( array( 'small', '100', '60' ), array( 'large', '120', '70' ) ) as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $variable->get_id() );
			$variation->set_attributes( array( 'size' => $spec[0] ) );
			$variation->set_regular_price( $spec[1] );
			$variation->save();
			update_post_meta( $variation->get_id(), '_price_group_b2b_standard', $spec[2] );
		}

		WC_Product_Variable::sync( $variable->get_id() );
		$this->variable_id = $variable->get_id();

		$retail = new WC_Product_Simple();
		$retail->set_regular_price( '50' );
		$retail->save();
		$this->retail_only_id = $retail->get_id();
	}

	private function row( int $product_id, string $group ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT min_price, max_price FROM {$wpdb->prefix}product_group_price_lookup WHERE product_id = %d AND price_group = %s",
			$product_id,
			$group
		) );
	}

	public function test_the_table_exists() {
		global $wpdb;

		$table = $wpdb->prefix . 'product_group_price_lookup';

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_it_records_min_and_max_across_variations() {
		LookupIndex::update_for_product( $this->variable_id );

		$row = $this->row( $this->variable_id, 'b2b_standard' );

		$this->assertNotNull( $row );
		$this->assertSame( '60.0000', $row->min_price );
		$this->assertSame( '70.0000', $row->max_price );
	}

	/**
	 * Fail-closed extends to the index: no group price means absent from
	 * that group's listings, not present at retail values.
	 */
	public function test_a_product_with_no_group_price_gets_no_row() {
		LookupIndex::update_for_product( $this->retail_only_id );

		$this->assertNull( $this->row( $this->retail_only_id, 'b2b_standard' ) );
	}

	public function test_removing_a_group_price_removes_the_row() {
		LookupIndex::update_for_product( $this->variable_id );
		$this->assertNotNull( $this->row( $this->variable_id, 'b2b_standard' ) );

		foreach ( wc_get_product( $this->variable_id )->get_children() as $vid ) {
			delete_post_meta( $vid, '_price_group_b2b_standard' );
		}

		LookupIndex::update_for_product( $this->variable_id );

		$this->assertNull( $this->row( $this->variable_id, 'b2b_standard' ) );
	}

	public function test_a_simple_product_records_its_own_price() {
		update_post_meta( $this->retail_only_id, '_price_group_b2b_standard', '30' );
		LookupIndex::update_for_product( $this->retail_only_id );

		$row = $this->row( $this->retail_only_id, 'b2b_standard' );

		$this->assertSame( '30.0000', $row->min_price );
		$this->assertSame( '30.0000', $row->max_price );
	}

	public function test_backfill_covers_every_product() {
		$written = LookupIndex::backfill();

		$this->assertGreaterThan( 0, $written );
		$this->assertNotNull( $this->row( $this->variable_id, 'b2b_standard' ) );
	}

	public function test_a_group_sale_price_is_what_gets_indexed() {
		update_post_meta( $this->retail_only_id, '_price_group_b2b_standard', '30' );
		update_post_meta( $this->retail_only_id, '_sale_price_group_b2b_standard', '20' );
		LookupIndex::update_for_product( $this->retail_only_id );

		$this->assertSame( '20.0000', $this->row( $this->retail_only_id, 'b2b_standard' )->min_price );
	}
}
