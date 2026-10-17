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
		PricingContext::stop_acting();
		unset( $_SERVER['HTTP_HOST'], $_POST['order_id'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Trade prices on the retail host come with trade payment: bank transfer only. */
	public function test_a_partner_on_the_retail_host_pays_by_bank_transfer() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$gateways = B2BPaymentMethods::only_bank_transfer( array( 'bacs' => new stdClass(), 'stripe' => new stdClass() ) );

		$this->assertArrayNotHasKey( 'stripe', $gateways );
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
