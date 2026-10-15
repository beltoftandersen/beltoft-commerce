<?php

use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Pricing\SaleScheduler;

class SaleSchedulerTest extends WP_UnitTestCase {

	private int $variable_id;
	private int $variation_id;
	private int $simple_id;

	public function setUp(): void {
		parent::setUp();
		Roles::install();

		$simple = new WC_Product_Simple();
		$simple->set_regular_price( '100' );
		$simple->save();
		$this->simple_id = $simple->get_id();

		$variable = new WC_Product_Variable();
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
		$variation->save();

		WC_Product_Variable::sync( $variable->get_id() );

		$this->variable_id  = $variable->get_id();
		$this->variation_id = $variation->get_id();
	}

	/**
	 * Prices arrive from an import that never touches the admin screen, so
	 * invalidation hooks the meta write itself rather than the save action.
	 * Architecture §2.5.
	 */
	public function test_writing_a_group_price_invalidates_the_variation_transient() {
		wc_get_product( $this->variable_id )->get_variation_prices( true );
		$before = get_transient( 'wc_var_prices_' . $this->variable_id );

		update_post_meta( $this->variation_id, '_price_group_b2b_standard', '55' );

		$this->assertNotSame( $before, get_transient( 'wc_var_prices_' . $this->variable_id ) );
	}

	public function test_writing_an_unrelated_meta_key_does_not_invalidate() {
		$this->assertFalse( SaleScheduler::is_group_price_key( '_some_other_meta' ) );
		$this->assertTrue( SaleScheduler::is_group_price_key( '_price_group_b2b_standard' ) );
		$this->assertTrue( SaleScheduler::is_group_price_key( '_sale_price_dates_to_group_b2b_standard' ) );
	}

	/**
	 * A scheduled sale starting or ending is a price change with no write
	 * behind it. Without a boundary task a B2B campaign appears late and
	 * lingers after it should have ended.
	 */
	public function test_a_sale_starting_is_reflected_without_a_price_write() {
		update_post_meta( $this->simple_id, '_price_group_b2b_standard', '60' );
		update_post_meta( $this->simple_id, '_sale_price_group_b2b_standard', '45' );
		update_post_meta( $this->simple_id, '_sale_price_dates_from_group_b2b_standard', strtotime( '-1 hour' ) );

		do_action( 'beltoft_commerce_group_sale_boundary' );

		$this->assertSame( '45', PriceResolver::resolve( wc_get_product( $this->simple_id ), 'b2b_standard' ) );
	}

	public function test_a_sale_that_has_ended_reverts_to_the_regular_group_price() {
		update_post_meta( $this->simple_id, '_price_group_b2b_standard', '60' );
		update_post_meta( $this->simple_id, '_sale_price_group_b2b_standard', '45' );
		update_post_meta( $this->simple_id, '_sale_price_dates_to_group_b2b_standard', strtotime( '-1 hour' ) );

		do_action( 'beltoft_commerce_group_sale_boundary' );

		$this->assertSame( '60', PriceResolver::resolve( wc_get_product( $this->simple_id ), 'b2b_standard' ) );
	}

	public function test_the_boundary_event_is_scheduled() {
		SaleScheduler::register();
		SaleScheduler::schedule();

		$this->assertNotFalse( wp_next_scheduled( 'beltoft_commerce_group_sale_boundary' ) );
	}

	/** Only a window that opened or closed since the last run is invalidated. */
	public function test_only_crossed_boundaries_are_invalidated() {
		$changed = array();
		$record  = static function ( $id ) use ( &$changed ) {
			$changed[] = (int) $id;
		};

		$other = ( new WC_Product_Simple() );
		$other->save();

		update_option( SaleScheduler::LAST_RUN_OPTION, time() - HOUR_IN_SECONDS );
		update_post_meta( $this->simple_id, '_sale_price_dates_from_group_b2b_standard', time() - 10 * MINUTE_IN_SECONDS );
		update_post_meta( $other->get_id(), '_sale_price_dates_to_group_b2b_standard', time() + 30 * DAY_IN_SECONDS );

		add_action( 'beltoft_commerce_group_price_changed', $record );
		SaleScheduler::run_boundary();

		$this->assertSame( array( $this->simple_id ), $changed );

		// The next run starts where this one ended: nothing new has crossed.
		$changed = array();
		SaleScheduler::run_boundary();
		$this->assertSame( array(), $changed );
	}
}
