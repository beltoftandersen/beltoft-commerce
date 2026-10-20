<?php

use BeltoftCommerce\Account\B2BAccountPage;
use BeltoftCommerce\Checkout\B2BCheckoutPage;
use BeltoftCommerce\Checkout\B2BPaymentMethods;
use BeltoftCommerce\Domain\OrderHost;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

/**
 * The trade checkout, account and quick order pages on every trade host and
 * in every language (spec 2026-10-20, §5), and the 2026-10-20 retail-price
 * rule (§2.3) treating each new trade host as a trade host.
 */
class TradeHostsCheckoutTest extends WP_UnitTestCase {

	use TradeHosts;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function order_on( int $customer, ?string $host ): WC_Order {
		$order = new WC_Order();
		$order->set_customer_id( $customer );
		$order->set_status( 'pending' );
		if ( null !== $host ) {
			$order->update_meta_data( '_beltoft_origin_host', $host );
		}
		$order->save();

		return $order;
	}

	/** Zerolang's Woo::page_id runs at 10 and translates whatever id it is handed: the trade id must be in first. */
	public function test_the_trade_page_id_filter_runs_before_the_language_plugin() {
		$this->assertSame( 5, has_filter( 'woocommerce_get_checkout_page_id', array( B2BCheckoutPage::class, 'page_id' ) ) );
		$this->assertSame( 5, has_filter( 'woocommerce_get_myaccount_page_id', array( B2BAccountPage::class, 'page_id' ) ) );
	}

	public function test_the_trade_pages_answer_on_every_trade_host() {
		$this->use_trade_hosts();
		$checkout = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-finalizar' ) );
		$account  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'conta' ) );
		update_option( B2BCheckoutPage::OPTION, $checkout );
		update_option( B2BAccountPage::OPTION, $account );

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertSame( $checkout, B2BCheckoutPage::page_id( 7 ), $host );
			$this->assertSame( $account, B2BAccountPage::page_id( 7 ), $host );
		}

		$_SERVER['HTTP_HOST'] = self::$en_retail;
		$this->assertSame( 7, B2BCheckoutPage::page_id( 7 ) );

		delete_option( B2BCheckoutPage::OPTION );
		delete_option( B2BAccountPage::OPTION );
	}

	public function test_a_legacy_path_keeps_its_language_prefix() {
		$this->use_path_mode();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( home_url( '/es/finalizar/encomenda-recebida/1/' ) . '?key=k', B2BCheckoutPage::legacy_target( '/es/finalizar-b2b/encomenda-recebida/1/?key=k' ) );
		$this->assertSame( home_url( '/finalizar/' ), B2BCheckoutPage::legacy_target( '/finalizar-b2b/' ) );
		$this->assertSame( home_url( '/es/conta/lost-password/' ) . '?key=k&login=x', B2BAccountPage::legacy_target( '/es/conta-b2b/lost-password/?key=k&login=x' ) );
		$this->assertNull( B2BAccountPage::legacy_target( '/es/conta/' ) );
	}

	public function test_a_legacy_path_goes_to_the_hosts_own_language_path() {
		$this->use_trade_hosts();
		$_SERVER['HTTP_HOST'] = self::$en_trade;

		$this->assertSame( home_url( '/checkout/' ), B2BCheckoutPage::legacy_target( '/finalizar-b2b/' ) );
		$this->assertSame( home_url( '/account/orders/' ), B2BAccountPage::legacy_target( '/conta-b2b/orders/' ) );
		$this->assertSame( self::$en_trade, wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/** A trade order is paid on the trade host it was placed on: the partner is signed in there and nowhere else. */
	public function test_a_trade_orders_pay_page_goes_to_its_own_trade_host() {
		$this->use_trade_hosts();
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		$order   = $this->order_on( $partner, self::$en_trade );
		$slug    = WC()->query->get_query_vars()['order-pay'];

		foreach ( array( Hosts::retail(), self::$en_retail ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertSame(
				'https://' . self::$en_trade . '/checkout/' . $slug . '/' . $order->get_id() . '/?pay_for_order=true&key=' . $order->get_order_key(),
				B2BCheckoutPage::pay_target( $order ),
				$host
			);
		}

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertSame( '', B2BCheckoutPage::pay_target( $order ) );
	}

	public function test_an_order_on_any_trade_host_is_a_trade_order() {
		$this->use_trade_hosts();
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertTrue( OrderHost::is_trade_order( $this->order_on( $partner, self::$en_trade ) ) );
		$this->assertTrue( OrderHost::placed_on_trade_host( $this->order_on( $partner, self::$en_trade ) ) );
		$this->assertFalse( OrderHost::is_trade_order( $this->order_on( $partner, self::$en_retail ) ) );
	}

	/** Every trade host prices, carts and pays as the trade host (§2.3, 2026-10-20); a language domain is retail. */
	public function test_every_trade_host_is_a_trade_front() {
		$this->use_trade_hosts();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertFalse( PricingContext::is_retail_front() );
		$this->assertArrayNotHasKey( 'stripe', B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
		$this->assertSame( 'trade', $this->cart_kind() );

		$_SERVER['HTTP_HOST'] = self::$en_retail;
		$this->assertTrue( PricingContext::is_retail_front() );
		$this->assertSame( 'retail', $this->cart_kind() );
	}

	private function cart_kind(): string {
		$kind = new ReflectionMethod( \BeltoftCommerce\Frontend\HostCart::class, 'kind' );
		$kind->setAccessible( true );

		return (string) $kind->invoke( null );
	}
}
