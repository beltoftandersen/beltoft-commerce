<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Monitoring\OrphanedPriceScan;
use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Roles;

class OrphanedPriceScanTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		LookupIndex::install();
	}

	private function make( ?string $audience, ?string $price, string $sku, string $status = 'publish' ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( $status );
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

	public function test_it_reports_a_product_priced_for_a_group_that_cannot_see_it() {
		$id = $this->make( null, '60', 'ORPHAN-1' );

		$found = OrphanedPriceScan::run();

		$this->assertArrayHasKey( 'b2b_standard', $found );
		$this->assertArrayHasKey( $id, $found['b2b_standard'] );
		$this->assertSame( 'ORPHAN-1', $found['b2b_standard'][ $id ] );
	}

	public function test_a_retail_only_tag_still_counts_as_not_visible() {
		// An explicit `retail` term is as invisible to B2B as no term at all,
		// so a B2B price on one is just as stranded.
		$id = $this->make( Taxonomy::RETAIL, '60', 'ORPHAN-2' );

		$this->assertArrayHasKey( $id, OrphanedPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_a_priced_and_tagged_product_is_not_reported() {
		$id = $this->make( Taxonomy::BOTH, '60', 'FINE-1' );

		$this->assertArrayNotHasKey( $id, OrphanedPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_an_untagged_product_with_no_b2b_price_is_not_reported() {
		// The ordinary retail product. There are 250 of these on staging and
		// reporting them would bury the handful that matter.
		$id = $this->make( null, null, 'RETAIL-1' );

		$this->assertArrayNotHasKey( $id, OrphanedPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_an_unpublished_but_tagged_product_is_not_reported() {
		// Drafts are where a product legitimately waits with its price and
		// term already set; the scan must not treat that as drift.
		$id = $this->make( Taxonomy::B2B, '60', 'DRAFT-1', 'draft' );

		$this->assertArrayNotHasKey( $id, OrphanedPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_a_price_row_for_a_deleted_product_is_not_reported() {
		$id = $this->make( null, '60', 'GONE-1' );
		wp_delete_post( $id, true );

		$this->assertArrayNotHasKey( $id, OrphanedPriceScan::run()['b2b_standard'] ?? array() );
	}

	public function test_it_alerts_when_a_price_is_stranded() {
		$sent = array();
		add_filter( 'beltoft_commerce_send_orphaned_price_alert', function ( $send, $body ) use ( &$sent ) {
			$sent[] = $body;
			return false;
		}, 10, 2 );

		$this->make( null, '60', 'ORPHAN-3' );
		OrphanedPriceScan::run_and_alert();

		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'ORPHAN-3', $sent[0] );
	}

	public function test_it_stays_quiet_when_audience_and_prices_agree() {
		$this->make( Taxonomy::BOTH, '60', 'FINE-2' );
		$this->make( null, null, 'RETAIL-2' );

		$sent = array();
		add_filter( 'beltoft_commerce_send_orphaned_price_alert', function ( $send, $body ) use ( &$sent ) {
			$sent[] = $body;
			return false;
		}, 10, 2 );

		OrphanedPriceScan::run_and_alert();

		$this->assertSame( array(), $sent );
	}
}
