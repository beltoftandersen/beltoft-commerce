<?php

use BeltoftCommerce\Frontend\ResellerTags;

class ResellerTagsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		ResellerTags::flush();
	}

	private function simple( string $price = '10' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	private function tag( string $name, int $post_id ): string {
		return (string) ResellerTags::render_tag( '{' . $name . '}', $post_id );
	}

	/** The list reads the EAN where the cart does, legacy key included. */
	public function test_the_ean_is_read_from_either_key() {
		$core   = $this->simple();
		$legacy = $this->simple();
		$core->set_global_unique_id( '5601234567890' );
		$core->save();
		update_post_meta( $legacy->get_id(), '_alg_ean', '5609876543210' );

		$this->assertSame( '5601234567890', $this->tag( 'beltoft_ean', $core->get_id() ) );
		$this->assertSame( '5609876543210', $this->tag( 'beltoft_ean', $legacy->get_id() ) );
	}

	public function test_a_variation_without_its_own_ean_shows_its_parents() {
		$parent = new WC_Product_Variable();
		$parent->save();
		update_post_meta( $parent->get_id(), '_alg_ean', '5600000000001' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$this->assertSame( '5600000000001', $this->tag( 'beltoft_ean', $variation->get_id() ) );
	}

	/** Several children with their own codes: say how many, never pick one. */
	public function test_a_variable_parent_counts_its_childrens_codes() {
		$parent = new WC_Product_Variable();
		$parent->save();

		foreach ( array( '5600000000011', '5600000000012' ) as $code ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( '5' );
			$variation->save();
			update_post_meta( $variation->get_id(), '_alg_ean', $code );
		}

		$this->assertSame( '2 codes', $this->tag( 'beltoft_ean', $parent->get_id() ) );
	}

	public function test_the_rrp_is_the_retail_price() {
		$product = $this->simple( '12.5' );

		$this->assertStringContainsString( '12', wp_strip_all_tags( $this->tag( 'beltoft_rrp', $product->get_id() ) ) );
		$this->assertSame( '', $this->tag( 'beltoft_rrp', $this->simple( '' )->get_id() ) );
	}

	/** Removed tags are left for Bricks to deal with, not answered. */
	public function test_unknown_tags_pass_through() {
		$this->assertSame( '{beltoft_margin}', ResellerTags::render_tag( '{beltoft_margin}', 1 ) );
	}
}
