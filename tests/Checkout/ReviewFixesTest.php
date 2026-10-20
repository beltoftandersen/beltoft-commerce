<?php

use BeltoftCommerce\Checkout\B2BPaymentMethods;
use BeltoftCommerce\Hooks\Price;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\Registrar;
use BeltoftCommerce\Scanner\BackInStockLink;
use BeltoftCommerce\Support\Hosts;

/** Checkout, pricing and registration behaviour fixed after the 2026-10-17 review. */
class CheckoutReviewFixesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
	}

	public function tearDown(): void {
		// The district test fills WooCommerce's states cache with test data.
		$cache = new ReflectionProperty( WC_Countries::class, 'geo_cache' );
		$cache->setAccessible( true );
		$cache->setValue( WC()->countries, array() );

		PricingContext::stop_acting();
		unset( $_SERVER['HTTP_HOST'], $_POST['order_id'], $GLOBALS['wp']->query_vars['order-pay'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Trade prices come with trade payment: bank transfer only, on the trade host. */
	public function test_a_partner_on_the_trade_host_pays_by_bank_transfer() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$gateways = B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) );

		$this->assertArrayNotHasKey( 'stripe', $gateways );
	}

	/** On the retail host a partner is priced at retail (§2.3, 2026-10-20), so pays as a retail shopper does. */
	public function test_a_partner_on_the_retail_host_keeps_every_gateway() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertCount( 2, B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
	}

	/** A pending order to pay, recorded on $host (null: placed before hosts were recorded). */
	private function pending_order( int $customer, ?string $host ): WC_Order {
		$order = new WC_Order();
		$order->set_customer_id( $customer );
		$order->set_status( 'pending' );
		if ( null !== $host ) {
			$order->update_meta_data( '_beltoft_origin_host', $host );
		}
		$order->save();

		return $order;
	}

	/** The order-pay endpoint, as WooCommerce parses it. */
	private function paying( WC_Order $order ): void {
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $order->get_id();
	}

	/** A trade order paid from the retail host keeps trade payment: the order decides, not the request. */
	public function test_a_trade_order_paid_on_the_retail_host_is_bank_transfer_only() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$partner              = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $partner );
		$this->paying( $this->pending_order( $partner, Hosts::b2b() ) );

		$this->assertArrayNotHasKey( 'stripe', B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
	}

	/** An order staff made for a partner records no host: the customer's account decides. */
	public function test_an_unrecorded_order_for_a_partner_is_bank_transfer_only() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$partner              = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $partner );
		$this->paying( $this->pending_order( $partner, null ) );

		$this->assertArrayNotHasKey( 'stripe', B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
	}

	/** A partner's own retail order pays as retail. */
	public function test_a_retail_order_by_a_partner_keeps_every_gateway() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$partner              = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $partner );
		$this->paying( $this->pending_order( $partner, Hosts::retail() ) );

		$this->assertCount( 2, B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
	}

	/** The pay page of a trade order opened on the retail host goes to the trade host's pay page. */
	public function test_a_trade_orders_pay_page_on_retail_goes_to_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$partner              = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		$order                = $this->pending_order( $partner, Hosts::b2b() );
		$slug                 = WC()->query->get_query_vars()['order-pay'];

		$this->assertSame(
			'https://' . Hosts::b2b() . BeltoftCommerce\Checkout\B2BCheckoutPage::path() . $slug . '/' . $order->get_id() . '/?pay_for_order=true&key=' . $order->get_order_key(),
			BeltoftCommerce\Checkout\B2BCheckoutPage::pay_target( $order )
		);

		// Not for a retail order, and not on the trade host itself.
		$this->assertSame( '', BeltoftCommerce\Checkout\B2BCheckoutPage::pay_target( $this->pending_order( $partner, Hosts::retail() ) ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->assertSame( '', BeltoftCommerce\Checkout\B2BCheckoutPage::pay_target( $order ) );
	}

	public function test_a_retail_customer_keeps_every_gateway() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertCount( 2, B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) ) );
	}

	/** A line staff add to a partner's order is priced for the partner, not for staff. */
	public function test_admin_added_lines_are_priced_for_the_orders_customer() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		$order = new WC_Order();
		$order->set_customer_id( $partner );
		$order->save();

		$_POST['order_id'] = $order->get_id();
		Price::price_added_items_for_customer();

		$this->assertSame( '60', wc_get_product( $product->get_id() )->get_price() );
	}

	/** The application form sends district names; WooCommerce matches on codes. */
	public function test_a_district_name_is_stored_as_its_state_code() {
		// WooCommerce ships no Portuguese districts; the site adds them.
		add_filter( 'woocommerce_states', static fn( $states ) => array_merge( (array) $states, array( 'PT' => array( 'AC' => 'Açores', 'LS' => 'Lisboa', 'MD' => 'Madeira' ) ) ) );
		$cache = new ReflectionProperty( WC_Countries::class, 'geo_cache' );
		$cache->setAccessible( true );
		$cache->setValue( WC()->countries, array() );
		$this->assertSame( 'MD', Registrar::state_code( 'Madeira', 'PT' ) );
		$this->assertSame( 'AC', Registrar::state_code( 'Acores', 'PT' ) );
		$this->assertSame( 'LS', Registrar::state_code( 'LS', 'PT' ) );
		$this->assertSame( 'Somewhere', Registrar::state_code( 'Somewhere', 'PT' ) );
	}

	/** The trade link is applied after the notifier's own rewrites of the email link. */
	public function test_the_back_in_stock_link_runs_last() {
		if ( ! class_exists( '\\BeltoftInStockNotifier\\Subscription\\Repository' ) ) {
			require_once BELTOFT_SITE_PLUGINS . '/beltoft-in-stock-notifier/beltoft-in-stock-notifier.php';
		}

		BackInStockLink::register();

		$this->assertSame( PHP_INT_MAX, has_filter( 'bisn_email_product_url', array( BackInStockLink::class, 'filter' ) ) );
		remove_all_filters( 'bisn_email_product_url' );
	}
}
