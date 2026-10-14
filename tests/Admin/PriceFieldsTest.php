<?php

use BeltoftCommerce\Admin\PriceFields;
use BeltoftCommerce\Pricing\Registry;

class PriceFieldsTest extends WP_UnitTestCase {

	/**
	 * Mirror the real store's number format. It is a Portuguese store:
	 * decimal separator "," and an empty thousands separator. Without this
	 * the suite exercises a configuration that does not exist, and
	 * wc_format_decimal( '60,50' ) strips the comma as a thousands
	 * separator and yields 6050.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '' );
		update_option( 'woocommerce_price_num_decimals', 2 );
	}

	public function test_it_describes_the_four_fields_for_a_group() {
		$fields = PriceFields::fields_for( 'b2b_standard' );

		$this->assertSame(
			array(
				'_price_group_b2b_standard',
				'_sale_price_group_b2b_standard',
				'_sale_price_dates_from_group_b2b_standard',
				'_sale_price_dates_to_group_b2b_standard',
			),
			array_column( $fields, 'key' )
		);
	}

	/**
	 * A group added by filter must produce its fields with no code change.
	 */
	public function test_a_new_group_produces_its_fields_automatically() {
		add_filter( 'pricing_groups', function ( $groups ) {
			$groups['dealer'] = array( 'label' => 'Dealer', 'roles' => array( 'dealer' ) );

			return $groups;
		} );

		$all = PriceFields::all_fields();

		$this->assertArrayHasKey( 'dealer', $all );
		$this->assertContains( '_price_group_dealer', array_column( $all['dealer'], 'key' ) );
	}

	public function test_saving_stores_a_formatted_decimal() {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->save();

		PriceFields::save_group_prices( $product->get_id(), array(
			'_price_group_b2b_standard' => '60,50',
		) );

		$this->assertSame( '60.50', get_post_meta( $product->get_id(), '_price_group_b2b_standard', true ) );
	}

	public function test_saving_an_empty_value_deletes_the_meta_rather_than_storing_empty() {
		$product = new WC_Product_Simple();
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		PriceFields::save_group_prices( $product->get_id(), array( '_price_group_b2b_standard' => '' ) );

		// Fail-closed depends on "absent", so an empty string must not linger.
		$this->assertSame( '', get_post_meta( $product->get_id(), '_price_group_b2b_standard', true ) );
		$this->assertFalse( metadata_exists( 'post', $product->get_id(), '_price_group_b2b_standard' ) );
	}

	public function test_it_ignores_keys_that_are_not_registry_derived() {
		$product = new WC_Product_Simple();
		$product->save();

		PriceFields::save_group_prices( $product->get_id(), array( '_evil_key' => '1' ) );

		$this->assertFalse( metadata_exists( 'post', $product->get_id(), '_evil_key' ) );
	}
}
