<?php

use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Stock\GroupStock;
use BeltoftCommerce\Support\Hosts;

class GroupStockTest extends WP_UnitTestCase {

	private int $pos_user;
	private int $b2b_user;
	private int $product;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		GroupStock::register();

		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->pos_user = self::factory()->user->create( array( 'role' => 'pos_operator' ) );
		$this->b2b_user = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$p = new WC_Product_Simple();
		$p->set_name( 'Shelf item' );
		$p->set_regular_price( '10' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 40 );   // the warehouse
		$this->product = $p->save();

		update_post_meta( $this->product, Registry::stock_key( 'pos' ), 3 ); // the shelf
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function fresh(): WC_Product {
		return wc_get_product( $this->product );
	}

	public function test_the_till_sees_the_shelf_and_trade_sees_the_warehouse() {
		wp_set_current_user( $this->pos_user );
		$this->assertSame( 3, $this->fresh()->get_stock_quantity(), 'POS reads its own pool.' );

		wp_set_current_user( $this->b2b_user );
		$this->assertSame( 40, $this->fresh()->get_stock_quantity(), 'Trade reads the shared pool.' );

		wp_set_current_user( 0 );
		$this->assertSame( 40, $this->fresh()->get_stock_quantity(), 'Retail reads the shared pool.' );
	}

	/**
	 * The customer is holding it. The count is what might be wrong, not the
	 * goods - so an empty shelf never stops a sale at a stand.
	 */
	public function test_an_empty_shelf_still_sells_at_the_till() {
		update_post_meta( $this->product, Registry::stock_key( 'pos' ), 0 );

		wp_set_current_user( $this->pos_user );
		$this->assertTrue( $this->fresh()->is_in_stock(), 'Never refuse goods on the table.' );
		$this->assertSame( 0, $this->fresh()->get_stock_quantity(), 'And the count still says what it says.' );

		wp_set_current_user( $this->b2b_user );
		$this->assertTrue( $this->fresh()->is_in_stock(), 'Still 40 in the warehouse.' );
	}

	public function test_an_uncounted_product_still_sells_at_the_till() {
		delete_post_meta( $this->product, Registry::stock_key( 'pos' ) );
		wp_set_current_user( $this->pos_user );

		$p = $this->fresh();
		$this->assertTrue( $p->managing_stock(), 'Always counted at the till.' );
		$this->assertSame( 0, $p->get_stock_quantity(), 'Never counted reads as none...' );
		$this->assertTrue( $p->is_in_stock(), '...which is a thing to reconcile, not a reason to refuse.' );

		// ...and the warehouse is untouched either way.
		wp_set_current_user( $this->b2b_user );
		$this->assertTrue( $this->fresh()->is_in_stock() );
		$this->assertSame( 40, $this->fresh()->get_stock_quantity() );
	}

	/**
	 * Backorders are WooCommerce's own "sell it and keep counting", which is
	 * what stops every stock gate downstream without switching any of them off.
	 */
	public function test_the_till_always_allows_the_sale_through() {
		$p = $this->fresh();
		$p->set_backorders( 'no' );
		$p->save();
		update_post_meta( $this->product, Registry::stock_key( 'pos' ), 0 );

		wp_set_current_user( $this->pos_user );
		$this->assertSame( 'yes', $this->fresh()->get_backorders(), 'Whatever the product says.' );
		$this->assertTrue( $this->fresh()->is_in_stock() );
		$this->assertSame( 'instock', $this->fresh()->get_stock_status(), 'Not "onbackorder" - nobody is promising to supply later.' );

		wp_set_current_user( $this->b2b_user );
		$this->assertSame( 'no', $this->fresh()->get_backorders(), 'Trade keeps the product\'s own setting.' );
	}

	/** A shelf below zero is the record working, not the record failing. */
	public function test_a_shelf_can_go_below_zero_and_keeps_selling() {
		update_post_meta( $this->product, Registry::stock_key( 'pos' ), -3 );
		wp_set_current_user( $this->pos_user );

		$this->assertSame( -3, $this->fresh()->get_stock_quantity(), 'Reported honestly, so it can be reconciled.' );
		$this->assertTrue( $this->fresh()->is_in_stock() );
	}

	/** The regression this whole class exists to prevent. */
	public function test_a_till_sale_never_touches_the_shared_pool() {
		$order = new WC_Order();
		$order->add_product( wc_get_product( $this->product ), 2 );
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'pos' );
		$order->save();

		$this->assertFalse(
			GroupStock::divert_reduce( true, $order ),
			'WooCommerce must be kept away from _stock for a till order.'
		);

		$this->assertSame( '40', (string) get_post_meta( $this->product, '_stock', true ), 'Warehouse untouched.' );
		$this->assertSame( 1, (int) get_post_meta( $this->product, Registry::stock_key( 'pos' ), true ), 'Shelf 3 - 2 = 1.' );
	}

	public function test_a_trade_order_reduces_the_shared_pool_as_before() {
		$order = new WC_Order();
		$order->add_product( wc_get_product( $this->product ), 2 );
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'b2b_standard' );
		$order->save();

		$this->assertTrue(
			GroupStock::divert_reduce( true, $order ),
			'Trade orders stay on WooCommerce own reduction.'
		);

		$this->assertSame( 3, (int) get_post_meta( $this->product, Registry::stock_key( 'pos' ), true ), 'Shelf untouched.' );
	}

	public function test_a_refunded_till_sale_goes_back_on_the_shelf() {
		$order = new WC_Order();
		$order->add_product( wc_get_product( $this->product ), 2 );
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'pos' );
		$order->save();

		GroupStock::divert_restore( true, $order );

		$this->assertSame( 5, (int) get_post_meta( $this->product, Registry::stock_key( 'pos' ), true ), 'Shelf 3 + 2 = 5.' );
	}

	/**
	 * The lost-update this was rewritten to prevent.
	 *
	 * get_post_meta() is served from the object cache, so a read-then-write
	 * decrement can be computed from a number that is already out of date -
	 * two sales both read 5, both write 4, and one sale never happened. Doing
	 * the arithmetic in the UPDATE makes that impossible; this proves the shelf
	 * moves by the amount sold even when the cache is holding a stale value.
	 */
	public function test_a_decrement_is_not_computed_from_a_stale_cached_count() {
		$order = new WC_Order();
		$order->add_product( wc_get_product( $this->product ), 2 );
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'pos' );
		$order->save();

		// Something else sold one behind our back, straight to the database.
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = 2 WHERE post_id = %d AND meta_key = %s",
			$this->product,
			Registry::stock_key( 'pos' )
		) );
		// ...while the cache still says 3.
		$this->assertSame( 3, (int) get_post_meta( $this->product, Registry::stock_key( 'pos' ), true ) );

		GroupStock::divert_reduce( true, $order );

		$fresh = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$this->product,
			Registry::stock_key( 'pos' )
		) );

		$this->assertSame( 0, $fresh, '2 on the shelf minus 2 sold = 0, not 3 - 2 = 1.' );
		$this->assertSame( 0, (int) get_post_meta( $this->product, Registry::stock_key( 'pos' ), true ), 'And the cache was invalidated.' );
	}

	/**
	 * A till sale must not hold warehouse stock it is never going to take.
	 * WooCommerce reserves against `_stock` for 30 minutes while an order is
	 * paid for; a counter sale is already paid, and draws on a different pool.
	 */
	public function test_a_till_order_reserves_nothing_from_the_shared_pool() {
		$order = new WC_Order();
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'pos' );
		$order->save();

		$this->assertSame( 0, GroupStock::no_hold( 30, $order ), 'Zero excludes the order from reservation.' );
	}

	public function test_a_trade_order_still_holds_stock_as_before() {
		$order = new WC_Order();
		$order->update_meta_data( GroupStock::ORDER_GROUP_META, 'b2b_standard' );
		$order->save();

		$this->assertSame( 30, GroupStock::no_hold( 30, $order ) );
	}

	/** The group must be on the order before WooCommerce reserves against it. */
	public function test_the_group_is_recorded_before_stock_is_reserved() {
		$ours = has_action( 'woocommerce_checkout_order_created', array( GroupStock::class, 'record_group' ) );

		$this->assertIsInt( $ours );
		$this->assertLessThan(
			10,
			$ours,
			'wc_reserve_stock_for_order runs at 10 and is registered first, so this must be earlier.'
		);
	}
}
