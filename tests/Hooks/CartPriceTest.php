<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class CartPriceTest extends WP_UnitTestCase {

	private int $simple_id;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_stock_status( 'instock' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );
		// Visible to B2B, or the audience filter blocks the add-to-cart
		// before pricing is ever reached.
		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );

		$this->simple_id = $product->get_id();
		$this->b2b_user  = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
	}

	public function tearDown(): void {
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_a_guest_cart_totals_at_retail() {
		wp_set_current_user( 0 );
		WC()->cart->add_to_cart( $this->simple_id );
		WC()->cart->calculate_totals();

		$this->assertEquals( 100, (float) WC()->cart->get_cart_contents_total() );
	}

	public function test_a_b2b_cart_totals_at_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->simple_id );
		WC()->cart->calculate_totals();

		$this->assertEquals( 60, (float) WC()->cart->get_cart_contents_total() );
	}

	public function test_the_cart_total_matches_the_displayed_price() {
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->simple_id );
		WC()->cart->calculate_totals();

		$displayed = (float) wc_get_product( $this->simple_id )->get_price();

		$this->assertEquals( $displayed, (float) WC()->cart->get_cart_contents_total() );
	}

	/**
	 * Role, approval status and catalog membership can all change
	 * mid-session, so pricing is re-resolved rather than trusted from
	 * add-to-cart time. Architecture §2.8.
	 */
	public function test_a_role_change_mid_session_is_reflected_in_cart_totals() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->simple_id );
		WC()->cart->calculate_totals();
		$this->assertEquals( 60, (float) WC()->cart->get_cart_contents_total() );

		// Approval revoked mid-session.
		( new WP_User( $this->b2b_user ) )->set_role( 'customer' );
		wp_set_current_user( $this->b2b_user );

		WC()->cart->calculate_totals();
		$this->assertEquals( 100, (float) WC()->cart->get_cart_contents_total() );
	}

	public function test_a_variable_product_line_uses_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		$variable = new WC_Product_Variable();
		$variable->set_name( 'Cart variable' );
		$variable->save();

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );
		$variable->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_attributes( array( 'size' => 'small' ) );
		$variation->set_regular_price( '100' );
		$variation->set_stock_status( 'instock' );
		$variation->save();
		update_post_meta( $variation->get_id(), '_price_group_b2b_standard', '60' );

		WC_Product_Variable::sync( $variable->get_id() );
		Taxonomy::set_audience( $variable->get_id(), Taxonomy::BOTH );

		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $variable->get_id(), 1, $variation->get_id(), array( 'size' => 'small' ) );
		WC()->cart->calculate_totals();

		$this->assertEquals( 60, (float) WC()->cart->get_cart_contents_total() );
	}

	/**
	 * Cart items are persisted to the session as IDs and rehydrated with
	 * wc_get_product() on load, so a price cached during one context must
	 * not survive into another.
	 */
	public function test_the_price_is_re_resolved_when_the_cart_is_rehydrated() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->simple_id );
		WC()->cart->calculate_totals();
		$this->assertEquals( 60, (float) WC()->cart->get_cart_contents_total() );

		// Force a rehydrate as a guest.
		wp_set_current_user( 0 );
		WC()->cart->get_cart_from_session();
		WC()->cart->calculate_totals();

		$this->assertEquals( 100, (float) WC()->cart->get_cart_contents_total() );
	}

	public function test_quantity_multiplies_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		WC()->cart->add_to_cart( $this->simple_id, 3 );
		WC()->cart->calculate_totals();

		$this->assertEquals( 180, (float) WC()->cart->get_cart_contents_total() );
	}
}
