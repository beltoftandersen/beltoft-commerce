<?php

use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Stock\Recount;

class RecountTest extends WP_UnitTestCase {

	private int $ok;
	private int $drifted;

	public function setUp(): void {
		parent::setUp();

		$this->ok      = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->drifted = self::factory()->post->create( array( 'post_type' => 'product' ) );

		update_post_meta( $this->ok, Registry::stock_key( 'pos' ), 4 );
		update_post_meta( $this->drifted, Registry::stock_key( 'pos' ), -2 );
	}

	public function test_only_shelves_below_zero_are_listed() {
		$this->assertSame( array( $this->drifted ), Recount::below_zero() );
	}

	/** A shelf at zero is counted and empty, which is not the same as drifted. */
	public function test_an_empty_shelf_is_not_a_drifted_one() {
		update_post_meta( $this->drifted, Registry::stock_key( 'pos' ), 0 );

		$this->assertSame( array(), Recount::below_zero() );
	}

	public function test_an_uncounted_shelf_is_not_a_drifted_one() {
		delete_post_meta( $this->drifted, Registry::stock_key( 'pos' ) );

		$this->assertSame( array(), Recount::below_zero() );
	}

	/**
	 * The comparison has to be numeric: meta_value is text, and as text '-2'
	 * sorts above '0', so a string comparison finds nothing.
	 */
	public function test_the_comparison_is_numeric_not_textual() {
		update_post_meta( $this->ok, Registry::stock_key( 'pos' ), '10' );
		update_post_meta( $this->drifted, Registry::stock_key( 'pos' ), '-1' );

		$this->assertSame( array( $this->drifted ), Recount::below_zero() );
	}

	/** Variations drift on their own shelf but are edited on the parent. */
	public function test_a_drifted_variation_is_listed_as_its_parent() {
		$variation = self::factory()->post->create(
			array( 'post_type' => 'product_variation', 'post_parent' => $this->ok )
		);
		update_post_meta( $variation, Registry::stock_key( 'pos' ), -5 );

		$this->assertContains( $variation, Recount::below_zero(), 'The shelf that drifted.' );
		$this->assertContains( $this->ok, Recount::products_to_recount(), 'The screen to fix it on.' );
		$this->assertNotContains( $variation, Recount::products_to_recount() );
	}

	public function test_the_notice_names_how_many() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-product' );

		ob_start();
		Recount::notice();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( '1 product has sold past', $out );
		$this->assertStringContainsString( Recount::FLAG, $out );
	}

	public function test_no_notice_when_every_shelf_adds_up() {
		update_post_meta( $this->drifted, Registry::stock_key( 'pos' ), 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-product' );

		ob_start();
		Recount::notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_no_notice_for_somebody_who_cannot_fix_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'edit-product' );

		ob_start();
		Recount::notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
