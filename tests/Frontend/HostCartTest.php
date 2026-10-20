<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Frontend\HostCart;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

/**
 * A signed-in customer's WooCommerce session is keyed by the user id, not by
 * the host-only cookie, so the partner's trade cart - and the totals stored
 * with it - is the same session row on the retail host. Since 2026-10-20 the
 * retail host prices a partner at retail (§2.3), and the two carts are kept
 * apart.
 */
class HostCartTest extends WP_UnitTestCase {

	private int $partner;
	private int $product_id;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_stock_status( 'instock' );
		$product->save();
		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );
		foreach ( array_keys( Registry::groups() ) as $n => $slug ) {
			update_post_meta( $product->get_id(), Registry::regular_key( $slug ), (string) ( 7770 + $n ) );
		}
		$this->product_id = $product->get_id();

		$this->partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $this->partner );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
		WC()->session->set( HostCart::MARK, null );
		WC()->session->set( HostCart::STASH, null );
	}

	public function tearDown(): void {
		WC()->cart->empty_cart();
		WC()->session->set( HostCart::MARK, null );
		WC()->session->set( HostCart::STASH, null );
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function on( string $host ): void {
		$_SERVER['HTTP_HOST'] = $host;
	}

	/** A trade cart as the session row holds it after a visit to the trade host. */
	private function trade_cart_in_the_session(): array {
		$this->on( Hosts::b2b() );
		HostCart::swap();

		$cart = array(
			'abc' => array(
				'key'           => 'abc',
				'product_id'    => $this->product_id,
				'variation_id'  => 0,
				'variation'     => array(),
				'quantity'      => 3,
				'line_total'    => 23310.0,
				'line_subtotal' => 23310.0,
			),
		);
		WC()->session->set( 'cart', $cart );
		WC()->session->set( 'cart_totals', array( 'subtotal' => '23310', 'total' => '23310', 'cart_contents_total' => '23310' ) );
		WC()->session->set( 'chosen_payment_method', 'bacs' );

		return $cart;
	}

	public function test_the_trade_cart_is_not_the_cart_on_the_retail_host() {
		$this->trade_cart_in_the_session();

		$this->on( Hosts::retail() );
		HostCart::swap();

		$this->assertNull( WC()->session->get( 'cart' ) );
		$this->assertNull( WC()->session->get( 'cart_totals' ) );
		$this->assertNull( WC()->session->get( 'chosen_payment_method' ) );
	}

	public function test_the_trade_cart_comes_back_on_the_trade_host() {
		$cart = $this->trade_cart_in_the_session();

		$this->on( Hosts::retail() );
		HostCart::swap();
		WC()->session->set( 'cart', array( 'retail' => array( 'product_id' => $this->product_id, 'quantity' => 1 ) ) );

		$this->on( Hosts::b2b() );
		HostCart::swap();
		$this->assertSame( $cart, WC()->session->get( 'cart' ) );
		$this->assertSame( 'bacs', WC()->session->get( 'chosen_payment_method' ) );

		// And the retail one is kept for the retail host.
		$this->on( Hosts::retail() );
		HostCart::swap();
		$this->assertArrayHasKey( 'retail', WC()->session->get( 'cart' ) );
	}

	/** Loaded the way WooCommerce loads it: no trade line and no trade total reach the retail cart or its fragments. */
	public function test_loading_the_cart_on_the_retail_host_shows_no_trade_total() {
		$this->trade_cart_in_the_session();

		$this->on( Hosts::retail() );
		WC()->cart->get_cart_from_session();

		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
		$this->assertStringNotContainsString( '23310', (string) wp_json_encode( WC()->cart->get_totals() ) );
	}

	/**
	 * The first load after deploy: a partner's session holds a cart nobody
	 * marked. On the retail host it is the trade cart (the partner's carts were
	 * built at trade prices until now): put aside as trade, retail starts empty.
	 */
	public function test_an_unmarked_cart_of_a_partner_on_retail_is_the_trade_cart() {
		$cart = array( 'old' => array( 'product_id' => $this->product_id, 'quantity' => 2 ) );
		WC()->session->set( 'cart', $cart );
		WC()->session->set( 'cart_totals', array( 'total' => '15540' ) );

		$this->on( Hosts::retail() );
		HostCart::swap();
		$this->assertNull( WC()->session->get( 'cart' ) );
		$this->assertNull( WC()->session->get( 'cart_totals' ) );

		$this->on( Hosts::b2b() );
		HostCart::swap();
		$this->assertSame( $cart, WC()->session->get( 'cart' ) );
	}

	/** A retail customer's unmarked cart is theirs, on retail. */
	public function test_an_unmarked_cart_of_a_retail_customer_stays() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		WC()->session->set( 'cart', array( 'x' => array( 'product_id' => $this->product_id, 'quantity' => 1 ) ) );

		$this->on( Hosts::retail() );
		HostCart::swap();

		$this->assertArrayHasKey( 'x', WC()->session->get( 'cart' ) );
	}

	/** A shopper who only ever uses one host is never touched. */
	public function test_a_session_on_one_host_is_left_alone() {
		$this->on( Hosts::retail() );
		HostCart::swap();
		WC()->session->set( 'cart', array( 'x' => array( 'product_id' => $this->product_id, 'quantity' => 1 ) ) );

		HostCart::swap();

		$this->assertArrayHasKey( 'x', WC()->session->get( 'cart' ) );
		$this->assertSame( 'retail', WC()->session->get( HostCart::MARK ) );
	}

	/** The persistent cart (user meta, one per account) belongs to the partner's trade cart. */
	public function test_the_persistent_cart_is_off_for_a_partner_on_the_retail_host() {
		$this->on( Hosts::retail() );
		$this->assertFalse( apply_filters( 'woocommerce_persistent_cart_enabled', true ) );

		$this->on( Hosts::b2b() );
		$this->assertTrue( apply_filters( 'woocommerce_persistent_cart_enabled', true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->on( Hosts::retail() );
		$this->assertTrue( apply_filters( 'woocommerce_persistent_cart_enabled', true ) );
	}
}
