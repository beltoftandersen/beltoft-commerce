<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Monitoring\MissingPriceScan;
use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Roles;

class MissingPriceScanTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		LookupIndex::install();
	}

	private function make( ?string $audience, ?string $price, string $sku ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_sku( $sku );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		if ( $price ) {
			update_post_meta( $product->get_id(), '_price_group_b2b_standard', $price );
		}

		LookupIndex::update_for_product( $product->get_id() );

		return $product->get_id();
	}

	public function test_it_reports_a_product_visible_to_a_group_with_no_price_for_it() {
		$id = $this->make( Taxonomy::BOTH, null, 'MISSING-1' );

		$found = MissingPriceScan::run();

		$this->assertArrayHasKey( 'b2b_standard', $found );
		$this->assertArrayHasKey( $id, $found['b2b_standard'] );
		$this->assertSame( 'MISSING-1', $found['b2b_standard'][ $id ] );
	}

	public function test_a_retail_only_product_is_not_reported() {
		// Retail-only products are not expected to have a B2B price.
		$id = $this->make( null, null, 'RETAIL-1' );

		$this->assertArrayNotHasKey( $id, MissingPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_a_priced_visible_product_is_not_reported() {
		$id = $this->make( Taxonomy::BOTH, '60', 'PRICED-1' );

		$this->assertArrayNotHasKey( $id, MissingPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_a_b2b_only_product_with_no_price_is_reported() {
		$id = $this->make( Taxonomy::B2B, null, 'MISSING-2' );

		$this->assertArrayHasKey( $id, MissingPriceScan::run()['b2b_standard'] );
	}

	/**
	 * Iterates the registry, so a newly registered group is covered with no
	 * code change.
	 */
	public function test_it_iterates_the_registry_rather_than_a_hardcoded_group() {
		add_filter( 'pricing_groups', function ( $groups ) {
			$groups['dealer'] = array( 'label' => 'Dealer', 'roles' => array( 'dealer' ) );

			return $groups;
		} );

		$this->assertArrayHasKey( 'dealer', MissingPriceScan::run() );
	}

	public function test_a_clean_catalog_reports_nothing() {
		$this->make( Taxonomy::BOTH, '60', 'CLEAN-1' );

		$this->assertSame( array(), array_filter( MissingPriceScan::run() ) );
	}
}
