<?php

use BeltoftCommerce\Pricing\Registry;

class RegistryTest extends WP_UnitTestCase {

	public function test_it_exposes_the_default_b2b_group() {
		$this->assertArrayHasKey( 'b2b_standard', Registry::groups() );
	}

	/**
	 * Retail uses core's own _regular_price / _sale_price. It is a context,
	 * not a group with derived meta keys.
	 */
	public function test_retail_is_not_a_pricing_group() {
		$this->assertArrayNotHasKey( Registry::retail_slug(), Registry::groups() );
	}

	public function test_meta_keys_are_derived_from_the_slug() {
		$this->assertSame( '_price_group_dealer', Registry::regular_key( 'dealer' ) );
		$this->assertSame( '_sale_price_group_dealer', Registry::sale_key( 'dealer' ) );
		$this->assertSame( '_sale_price_dates_from_group_dealer', Registry::sale_from_key( 'dealer' ) );
		$this->assertSame( '_sale_price_dates_to_group_dealer', Registry::sale_to_key( 'dealer' ) );
	}

	/**
	 * The executable form of "adding a group is config, not code".
	 */
	public function test_a_group_can_be_added_by_filter_alone() {
		add_filter( 'pricing_groups', function ( $groups ) {
			$groups['key_account'] = array( 'label' => 'Key Account', 'roles' => array( 'key_account' ) );

			return $groups;
		} );

		$this->assertTrue( Registry::has( 'key_account' ) );
		$this->assertSame( '_price_group_key_account', Registry::regular_key( 'key_account' ) );
	}

	public function test_all_keys_for_a_group_are_distinct() {
		$keys = array(
			Registry::regular_key( 'b2b_standard' ),
			Registry::sale_key( 'b2b_standard' ),
			Registry::sale_from_key( 'b2b_standard' ),
			Registry::sale_to_key( 'b2b_standard' ),
		);

		$this->assertSame( $keys, array_unique( $keys ) );
	}
}
