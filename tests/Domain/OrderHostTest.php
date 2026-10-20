<?php

use BeltoftCommerce\Domain\OrderHost;
use BeltoftCommerce\Support\Hosts;

class OrderHostTest extends WP_UnitTestCase {

	use TradeHosts;

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		parent::tearDown();
	}

	public function test_it_records_the_host_the_order_was_placed_on() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$order = new WC_Order();
		$order->save();

		OrderHost::record( $order->get_id() );

		$this->assertSame( Hosts::b2b(), OrderHost::for_order( wc_get_order( $order->get_id() ) ) );
	}

	public function test_it_records_the_retail_host_for_a_retail_order() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$order = new WC_Order();
		$order->save();

		OrderHost::record( $order->get_id() );

		$this->assertSame( Hosts::retail(), OrderHost::for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Covers every order placed before this feature existed — all 14,490
	 * of them on production. Retail is the correct fallback: it is where
	 * they were placed.
	 */
	public function test_it_falls_back_to_retail_for_an_order_with_no_recorded_host() {
		$order = new WC_Order();
		$order->save();

		$this->assertSame( Hosts::retail(), OrderHost::for_order( $order ) );
	}

	public function test_pushing_and_popping_the_host_restores_the_previous_value() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$order = new WC_Order();
		$order->save();
		$order->update_meta_data( '_beltoft_origin_host', Hosts::b2b() );
		$order->save();

		OrderHost::push_host( $order );
		$this->assertSame( Hosts::b2b(), $_SERVER['HTTP_HOST'] );

		OrderHost::pop_host();
		$this->assertSame( Hosts::retail(), $_SERVER['HTTP_HOST'] );
	}

	private function order_on( string $host ): WC_Order {
		$_SERVER['HTTP_HOST'] = $host;
		$order                = new WC_Order();
		$order->save();
		OrderHost::record( $order->get_id() );
		unset( $_SERVER['HTTP_HOST'] );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * The point of the whole class: a link in an order's email belongs to the
	 * order, not to whatever process rendered it.
	 */
	public function test_links_follow_the_order_to_the_trade_host() {
		$order = $this->order_on( Hosts::b2b() );

		OrderHost::push_host( $order );
		$url = home_url( '/minha-conta/' );
		OrderHost::pop_host();

		$this->assertSame( Hosts::b2b(), wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * And the other way, which is the half that was a coin flip: a retail
	 * order's email rendered by a queue runner spawned from the trade host
	 * used to come out with trade links.
	 */
	public function test_a_retail_orders_links_stay_retail_even_on_the_trade_host() {
		$order = $this->order_on( Hosts::retail() );

		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		OrderHost::push_host( $order );
		$url = home_url( '/minha-conta/' );
		OrderHost::pop_host();

		$this->assertSame( Hosts::retail(), wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * It used to work by setting HTTP_HOST and letting UrlFilter read it,
	 * which broke the moment UrlFilter learned to stand down for queued work -
	 * and queued work is what sends these emails.
	 */
	public function test_it_does_not_depend_on_the_url_filter_being_awake() {
		$order = $this->order_on( Hosts::b2b() );

		\BeltoftCommerce\Domain\UrlFilter::suspend();
		OrderHost::push_host( $order );
		$url = home_url( '/minha-conta/' );
		OrderHost::pop_host();
		\BeltoftCommerce\Domain\UrlFilter::resume();

		$this->assertSame( Hosts::b2b(), wp_parse_url( $url, PHP_URL_HOST ) );
	}

	public function test_urls_are_untouched_outside_an_email() {
		$before = home_url( '/loja/' );

		$this->assertSame( $before, OrderHost::swap( $before ) );
		$this->assertSame( $before, home_url( '/loja/' ) );
	}

	/** An email with no order behind it must not hijack anything. */
	public function test_an_email_without_an_order_pushes_nothing() {
		$before = home_url( '/loja/' );

		OrderHost::push_from_email( 'Heading', (object) array( 'object' => new WP_User( 0 ) ) );
		$during = home_url( '/loja/' );
		OrderHost::pop_host();

		$this->assertSame( $before, $during );
	}

	public function test_nesting_restores_the_outer_host() {
		$trade  = $this->order_on( Hosts::b2b() );
		$retail = $this->order_on( Hosts::retail() );

		OrderHost::push_host( $trade );
		OrderHost::push_host( $retail );
		$inner = home_url( '/x/' );
		OrderHost::pop_host();
		$outer = home_url( '/x/' );
		OrderHost::pop_host();

		$this->assertSame( Hosts::retail(), wp_parse_url( $inner, PHP_URL_HOST ) );
		$this->assertSame( Hosts::b2b(), wp_parse_url( $outer, PHP_URL_HOST ) );
	}

	/* One trade host per language (spec 2026-10-20, §6.1). */

	public function test_an_order_records_the_trade_host_it_was_placed_on() {
		$this->use_trade_hosts();
		$_SERVER['HTTP_HOST'] = strtoupper( self::$en_trade ) . ':443';

		$order = new WC_Order();
		$order->save();
		OrderHost::record( $order->get_id() );

		$this->assertSame( self::$en_trade, OrderHost::for_order( wc_get_order( $order->get_id() ) ) );

		$_SERVER['HTTP_HOST'] = self::$en_retail;
		$retail = new WC_Order();
		$retail->save();
		OrderHost::record( $retail->get_id() );

		$this->assertSame( Hosts::retail(), OrderHost::for_order( wc_get_order( $retail->get_id() ) ) );
	}

	/** An order with no host (older, or made by staff in wp-admin) for a partner: the partner's own trade host. */
	public function test_a_trade_customers_order_without_a_host_uses_their_trade_host() {
		$this->use_trade_hosts();
		\BeltoftCommerce\Pricing\Roles::install();
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		$order   = new WC_Order();
		$order->set_customer_id( $partner );
		$order->save();

		$this->assertSame( Hosts::b2b(), OrderHost::for_order( $order ) );

		update_user_meta( $partner, \BeltoftCommerce\Registration\TradeHost::META_KEY, self::$en_trade );
		$this->assertSame( self::$en_trade, OrderHost::for_order( $order ) );

		// A recorded host that is no trade host any more is not used.
		update_user_meta( $partner, \BeltoftCommerce\Registration\TradeHost::META_KEY, 'gone.example' );
		$this->assertSame( Hosts::b2b(), OrderHost::for_order( $order ) );

		$retail_customer = new WC_Order();
		$retail_customer->set_customer_id( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$retail_customer->save();
		$this->assertSame( Hosts::retail(), OrderHost::for_order( $retail_customer ) );
	}

	/**
	 * THE CONTRACT WITH ZEROLANG (spec R4): while an order email is written,
	 * HTTP_HOST is the order's host, and Zerolang's active host set follows
	 * HTTP_HOST - so a trade order's links stay on its trade host, in its
	 * language, from cron, wp-admin or the checkout alike.
	 */
	public function test_the_email_push_sets_http_host_to_the_order_host() {
		$this->use_trade_hosts();
		unset( $_SERVER['HTTP_HOST'] );

		$order = new WC_Order();
		$order->update_meta_data( '_beltoft_origin_host', self::$en_trade );
		$order->save();

		$email         = new stdClass();
		$email->object = $order;

		OrderHost::push_from_email( '', $email );
		$this->assertSame( self::$en_trade, $_SERVER['HTTP_HOST'] );
		$this->assertSame( self::$en_trade, wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$this->assertTrue( Hosts::is_b2b_host() );

		OrderHost::pop_host();
		$this->assertArrayNotHasKey( 'HTTP_HOST', $_SERVER );
	}
}
