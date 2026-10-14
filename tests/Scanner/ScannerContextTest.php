<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Scanner\ScannerContext;
use BeltoftCommerce\Support\Hosts;

/**
 * The B2B loja's part of the scanner, exercised through the scanner's filters
 * without the scanner loaded - the contract is the filters, not its classes.
 */
class ScannerContextTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		ScannerContext::register();
	}

	public function tearDown(): void {
		remove_all_filters( 'beltoft_scanner_contexts' );
		remove_all_filters( 'beltoft_scanner_assess' );
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function context(): array {
		$contexts = apply_filters( 'beltoft_scanner_contexts', array() );

		$this->assertArrayHasKey( 'b2b-loja', $contexts );

		return $contexts['b2b-loja'];
	}

	private function may_scan( string $role ): bool {
		$user = get_userdata( self::factory()->user->create( array( 'role' => $role ) ) );

		return call_user_func( $this->context()['may_scan'], $user );
	}

	/**
	 * Exactly who could scan before the scanner became pluggable. pos_operator
	 * stays until the till has its own page and context in beltoft-pos.
	 */
	public function test_partners_the_till_and_shop_staff_may_scan() {
		$this->assertTrue( $this->may_scan( 'b2b_customer' ) );
		$this->assertTrue( $this->may_scan( 'pos_operator' ) );
		$this->assertTrue( $this->may_scan( 'shop_manager' ) );
		$this->assertTrue( $this->may_scan( 'administrator' ) );
	}

	/** A pending applicant can sign in before anyone has approved them. */
	public function test_applicants_and_retail_customers_may_not() {
		$this->assertFalse( $this->may_scan( 'b2b_pending' ) );
		$this->assertFalse( $this->may_scan( 'customer' ) );
	}

	public function test_it_does_not_apply_on_the_retail_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( call_user_func( $this->context()['applies'] ) );
	}

	public function test_it_does_not_apply_off_the_ordering_page_on_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );

		$this->assertFalse( call_user_func( $this->context()['applies'] ) );
	}

	private function product( ?string $audience ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '6' );

		return wc_get_product( $product->get_id() );
	}

	private function assess( WC_Product $product, int $user_id ) {
		return apply_filters( 'beltoft_scanner_assess', null, $product, $user_id );
	}

	/** Same answer the catalogue gives: a retail-only product is invisible to trade. */
	public function test_a_product_outside_the_partners_catalogue_is_not_visible() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame( 'not_visible', $this->assess( $this->product( null ), $partner ) );
	}

	public function test_a_product_in_the_partners_catalogue_passes() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertNull( $this->assess( $this->product( Taxonomy::BOTH ), $partner ) );
	}

	/** A variation is judged by its parent's audience, as the catalogue does. */
	public function test_a_variation_is_judged_by_its_parent() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$parent = new WC_Product_Variable();
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->save();

		$this->assertSame( 'not_visible', $this->assess( wc_get_product( $variation->get_id() ), $partner ) );
	}

	/** An earlier refusal from another rule is never overturned. */
	public function test_an_earlier_refusal_stands() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame(
			'not_purchasable',
			apply_filters( 'beltoft_scanner_assess', 'not_purchasable', $this->product( Taxonomy::BOTH ), $partner )
		);
	}
}
