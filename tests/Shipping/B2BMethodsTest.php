<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Shipping\B2BMethods;

class B2BMethodsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		B2BMethods::set_instances( array( 27, 28 ) );
	}

	public function tearDown(): void {
		delete_option( B2BMethods::OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function be_b2b(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );
	}

	/** @return array<string, WC_Shipping_Rate> */
	private function rates(): array {
		return array(
			'flexible:2'     => new WC_Shipping_Rate( 'flexible:2', 'DPD Pickup', 3.45, array(), 'flexible_shipping_single', 2 ),
			'local_pickup:6' => new WC_Shipping_Rate( 'local_pickup:6', 'Levantar na Sede', 0, array(), 'local_pickup', 6 ),
			'flat_rate:27'   => new WC_Shipping_Rate( 'flat_rate:27', 'Envio B2B', 5.70, array(), 'flat_rate', 27 ),
		);
	}

	public function test_a_consumer_is_never_offered_a_trade_rate() {
		wp_set_current_user( 0 );

		$kept = array_keys( B2BMethods::filter_rates( $this->rates() ) );

		$this->assertSame( array( 'flexible:2', 'local_pickup:6' ), $kept );
	}

	public function test_a_trade_session_sees_only_trade_rates() {
		$this->be_b2b();

		$kept = array_keys( B2BMethods::filter_rates( $this->rates() ) );

		$this->assertSame( array( 'flat_rate:27' ), $kept );
	}

	public function test_free_carriage_supersedes_the_paid_trade_rate() {
		$this->be_b2b();

		$rates                      = $this->rates();
		$rates['free_shipping:28']  = new WC_Shipping_Rate( 'free_shipping:28', 'Envio Grátis B2B', 0, array(), 'free_shipping', 28 );

		$kept = array_keys( B2BMethods::filter_rates( $rates ) );

		// Not "free plus a paid option you would be foolish to pick".
		$this->assertSame( array( 'free_shipping:28' ), $kept );
	}

	public function test_a_zone_with_no_trade_rate_keeps_its_ordinary_ones() {
		/*
		 * The failure this prevents does not degrade: a buyer in an
		 * unconfigured zone with zero shipping methods cannot order at all.
		 */
		$this->be_b2b();

		$rates = $this->rates();
		unset( $rates['flat_rate:27'] );

		$kept = array_keys( B2BMethods::filter_rates( $rates ) );

		$this->assertSame( array( 'flexible:2', 'local_pickup:6' ), $kept );
	}

	public function test_nothing_is_touched_when_no_instance_is_nominated() {
		delete_option( B2BMethods::OPTION );
		wp_set_current_user( 0 );

		$this->assertSame( array_keys( $this->rates() ), array_keys( B2BMethods::filter_rates( $this->rates() ) ) );
	}

	public function test_the_package_carries_the_pricing_group() {
		// Without this the rate cache cannot tell trade from retail, and an
		// account approved mid-session keeps being offered consumer rates.
		$this->be_b2b();

		$packages = B2BMethods::tag_packages( array( array( 'contents' => array() ) ) );

		$this->assertSame( 'b2b_standard', $packages[0]['beltoft_pricing_group'] );
	}

	public function test_instances_are_stored_as_clean_integers() {
		B2BMethods::set_instances( array( '27', 0, 28, '', 27 ) );

		$this->assertSame( array( 27, 28 ), B2BMethods::instances() );
	}
}
