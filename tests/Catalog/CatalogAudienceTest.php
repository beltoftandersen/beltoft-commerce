<?php

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class CatalogAudienceTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $b2b_only;
	private int $both;
	private int $both_unpriced;
	private int $b2b_user;
	private int $pending_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$this->retail_only   = $this->make_product( null, null );
		$this->b2b_only      = $this->make_product( Taxonomy::B2B, '60' );
		$this->both          = $this->make_product( Taxonomy::BOTH, '60' );
		$this->both_unpriced = $this->make_product( Taxonomy::BOTH, null );

		$this->b2b_user     = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		$this->pending_user = $this->factory->user->create( array( 'role' => 'b2b_pending' ) );
	}

	private function make_product( ?string $audience, ?string $group_price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		if ( $group_price ) {
			update_post_meta( $product->get_id(), '_price_group_b2b_standard', $group_price );
		}

		return $product->get_id();
	}

	public function test_a_guest_sees_retail_and_both_but_not_b2b_only() {
		$this->assertTrue( CatalogAudience::can_view( 0, $this->retail_only ) );
		$this->assertTrue( CatalogAudience::can_view( 0, $this->both ) );
		$this->assertFalse( CatalogAudience::can_view( 0, $this->b2b_only ) );
	}

	public function test_a_b2b_customer_sees_b2b_and_both_but_not_retail_only() {
		$this->assertTrue( CatalogAudience::can_view( $this->b2b_user, $this->b2b_only ) );
		$this->assertTrue( CatalogAudience::can_view( $this->b2b_user, $this->both ) );
		$this->assertFalse( CatalogAudience::can_view( $this->b2b_user, $this->retail_only ) );
	}

	/**
	 * b2b_pending is retail-equivalent for every security and pricing
	 * purpose until approval. Architecture §2.2.
	 */
	public function test_a_pending_user_sees_exactly_what_a_guest_sees() {
		foreach ( array( $this->retail_only, $this->b2b_only, $this->both ) as $id ) {
			$this->assertSame(
				CatalogAudience::can_view( 0, $id ),
				CatalogAudience::can_view( $this->pending_user, $id ),
				'pending must match guest for product ' . $id
			);
		}
	}

	/**
	 * Where Stage 2's fail-closed rule and Stage 3's audience rule meet:
	 * visible to the group, but unpriced for it, means not buyable.
	 */
	public function test_can_purchase_is_false_when_the_group_has_no_price() {
		$this->assertTrue( CatalogAudience::can_view( $this->b2b_user, $this->both_unpriced ) );
		$this->assertFalse( CatalogAudience::can_purchase( $this->b2b_user, $this->both_unpriced ) );
	}

	public function test_can_purchase_is_true_for_a_priced_visible_product() {
		$this->assertTrue( CatalogAudience::can_purchase( $this->b2b_user, $this->both ) );
	}

	public function test_a_guest_can_purchase_a_retail_product_with_no_group_price() {
		// Fail-closed applies per group. Retail is unaffected by a missing
		// B2B price.
		$this->assertTrue( CatalogAudience::can_purchase( 0, $this->retail_only ) );
	}

	public function test_can_purchase_is_false_for_a_product_the_user_cannot_view() {
		$this->assertFalse( CatalogAudience::can_purchase( $this->b2b_user, $this->retail_only ) );
		$this->assertFalse( CatalogAudience::can_purchase( 0, $this->b2b_only ) );
	}

	public function test_restricts_current_request_is_false_for_a_guest() {
		wp_set_current_user( 0 );
		$this->assertFalse( CatalogAudience::restricts_current_request() );

		wp_set_current_user( $this->b2b_user );
		$this->assertTrue( CatalogAudience::restricts_current_request() );

		wp_set_current_user( 0 );
	}

	/** A role change in the same process is answered afresh, not from memory. */
	public function test_a_role_change_is_seen_at_once() {
		$this->assertFalse( CatalogAudience::can_view( $this->pending_user, $this->b2b_only ) );

		get_userdata( $this->pending_user )->set_role( 'b2b_customer' );

		$this->assertTrue( CatalogAudience::can_view( $this->pending_user, $this->b2b_only ) );
	}

	/** A group price written as plain meta, as imports do, is seen at once. */
	public function test_a_group_price_written_as_meta_is_seen_at_once() {
		$this->assertFalse( CatalogAudience::can_purchase( $this->b2b_user, $this->both_unpriced ) );

		update_post_meta( $this->both_unpriced, '_price_group_b2b_standard', '55' );

		$this->assertTrue( CatalogAudience::can_purchase( $this->b2b_user, $this->both_unpriced ) );
	}
}
