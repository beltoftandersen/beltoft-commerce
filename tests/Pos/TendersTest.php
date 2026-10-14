<?php

use BeltoftCommerce\Pos\PaymentMethods;
use BeltoftCommerce\Pos\Tenders;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

class TendersTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_the_four_tenders_are_registered_as_instances() {
		$gateways = Tenders::add( array() );

		$this->assertCount( 4, $gateways );

		foreach ( $gateways as $g ) {
			$this->assertInstanceOf( \BeltoftCommerce\Pos\Tender::class, $g );
			$this->assertTrue( Tenders::is_tender( $g->id ) );
		}

		$this->assertSame(
			array( 'pos_mbway', 'pos_card', 'pos_cash', 'pos_bank' ),
			Tenders::ids()
		);
	}

	/**
	 * The one that would be forgotten: a tender is a real gateway, so without
	 * the filter it appears on the retail checkout as a way to declare you
	 * paid cash from your sofa.
	 */
	public function test_a_tender_is_not_offered_outside_the_till() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$available = PaymentMethods::filter( array(
			'pos_cash' => new \BeltoftCommerce\Pos\Tender( 'pos_cash', 'Dinheiro' ),
			'bacs'     => new stdClass(),
		) );

		$this->assertArrayNotHasKey( 'pos_cash', $available );
		$this->assertArrayHasKey( 'bacs', $available, 'Trade keeps its own method.' );
	}

	public function test_the_pos_group_owns_its_stock_and_inherits_prices() {
		$this->assertTrue( Registry::has_own_stock( 'pos' ) );
		$this->assertTrue( Registry::inherits_prices( 'pos' ) );
		$this->assertFalse( Registry::is_trade( 'pos' ) );
		$this->assertSame( '_stock_group_pos', Registry::stock_key( 'pos' ) );
	}

	/** Trade must be exactly as it was before the till existed. */
	public function test_trade_is_unchanged() {
		$this->assertTrue( Registry::is_trade( 'b2b_standard' ) );
		$this->assertFalse( Registry::inherits_prices( 'b2b_standard' ) );
		$this->assertFalse( Registry::has_own_stock( 'b2b_standard' ) );

		$this->assertSame(
			array(
				'_price_group_b2b_standard',
				'_sale_price_group_b2b_standard',
				'_sale_price_dates_from_group_b2b_standard',
				'_sale_price_dates_to_group_b2b_standard',
			),
			Registry::all_price_keys(),
			'The till owns no price meta, so none may appear here.'
		);
	}
}
