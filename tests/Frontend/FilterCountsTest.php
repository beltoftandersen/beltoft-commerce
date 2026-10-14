<?php

use BeltoftCommerce\Frontend\FilterCounts;
use BeltoftCommerce\Support\Hosts;

class FilterCountsTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function element( string $name ): object {
		return (object) array( 'name' => $name, 'id' => 'fltcat' );
	}

	public function test_it_hides_the_count_on_every_filter_that_prints_one() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		foreach ( array( 'filter-checkbox', 'filter-radio', 'filter-select' ) as $name ) {
			$out = FilterCounts::hide( array(), $this->element( $name ) );

			$this->assertArrayHasKey( 'filterHideCount', $out, $name );
		}
	}

	/**
	 * Bricks reads this with isset(), so the key existing is what hides the
	 * count - the value never gets looked at.
	 */
	public function test_the_key_is_what_matters_not_the_value() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$out = FilterCounts::hide( array(), $this->element( 'filter-checkbox' ) );

		$this->assertTrue( isset( $out['filterHideCount'] ) );
	}

	public function test_retail_keeps_its_counts() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$out = FilterCounts::hide( array(), $this->element( 'filter-checkbox' ) );

		$this->assertArrayNotHasKey( 'filterHideCount', $out );
	}

	public function test_other_elements_are_left_alone() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		foreach ( array( 'filter-search', 'filter-submit', 'heading', 'block' ) as $name ) {
			$out = FilterCounts::hide( array( 'x' => 1 ), $this->element( $name ) );

			$this->assertSame( array( 'x' => 1 ), $out, $name );
		}
	}

	public function test_it_survives_being_handed_nonsense() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( 'not an array', FilterCounts::hide( 'not an array', $this->element( 'filter-checkbox' ) ) );
		$this->assertSame( array(), FilterCounts::hide( array(), null ) );
	}
}
