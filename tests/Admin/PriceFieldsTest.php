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

	/**
	 * WooCommerce saves every changed variation in one request, firing
	 * woocommerce_save_product_variation( $variation_id, $loop ) once per
	 * variation, with the fields suffixed _<loop>. Each variation must get
	 * its own row's values - not the lowest-numbered row's.
	 */
	public function test_saving_several_variations_gives_each_its_own_prices() {
		PriceFields::register();

		$first = new WC_Product_Variation();
		$first->save();
		$fourth = new WC_Product_Variation();
		$fourth->save();

		$_POST = array(
			'_price_group_b2b_standard_0'               => '10,00',
			'_sale_price_dates_to_group_b2b_standard_0' => '2026-11-01',
			'_price_group_b2b_standard_3'               => '30,00',
			'_sale_price_dates_to_group_b2b_standard_3' => '',
		);

		do_action( 'woocommerce_save_product_variation', $first->get_id(), 0 );
		do_action( 'woocommerce_save_product_variation', $fourth->get_id(), 3 );

		$_POST = array();

		$this->assertSame( '10.00', get_post_meta( $first->get_id(), '_price_group_b2b_standard', true ) );
		$this->assertSame( '30.00', get_post_meta( $fourth->get_id(), '_price_group_b2b_standard', true ) );
		$this->assertFalse( metadata_exists( 'post', $fourth->get_id(), '_sale_price_dates_to_group_b2b_standard' ) );
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

	private function site_time( string $local ): int {
		return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
	}

	/**
	 * "To 2026-10-20" means the sale is on for all of the 20th in the shop's
	 * own time, as WooCommerce's sale dates do. It was stored as 00:00 UTC,
	 * ending the sale a day early.
	 */
	public function test_a_sale_runs_through_the_whole_of_its_last_day_in_shop_time() {
		update_option( 'timezone_string', 'Europe/Lisbon' );
		$product = new WC_Product_Simple();
		$product->save();

		PriceFields::save_group_prices( $product->get_id(), array(
			'_sale_price_dates_from_group_b2b_standard' => '2026-10-14',
			'_sale_price_dates_to_group_b2b_standard'   => '2026-10-20',
		) );

		$this->assertSame( $this->site_time( '2026-10-14 00:00:00' ), (int) get_post_meta( $product->get_id(), '_sale_price_dates_from_group_b2b_standard', true ) );
		$this->assertSame( $this->site_time( '2026-10-20 23:59:59' ), (int) get_post_meta( $product->get_id(), '_sale_price_dates_to_group_b2b_standard', true ) );
	}

	public function test_a_sale_ending_today_is_still_on() {
		update_option( 'timezone_string', 'Europe/Lisbon' );
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->save();

		PriceFields::save_group_prices( $product->get_id(), array(
			'_price_group_b2b_standard'               => '60',
			'_sale_price_group_b2b_standard'          => '45',
			'_sale_price_dates_to_group_b2b_standard' => wp_date( 'Y-m-d' ),
		) );

		$this->assertSame( '45', BeltoftCommerce\Pricing\PriceResolver::resolve( wc_get_product( $product->get_id() ), 'b2b_standard' ) );
	}

	/**
	 * The field showed the stored timestamp back ("1792800000"), which the
	 * date pattern rejects and strtotime() cannot read on the next save.
	 */
	public function test_the_date_fields_show_the_date_not_the_timestamp() {
		update_option( 'timezone_string', 'Europe/Lisbon' );
		$variation = new WC_Product_Variation();
		$variation->save();
		update_post_meta( $variation->get_id(), '_sale_price_dates_to_group_b2b_standard', $this->site_time( '2026-10-20 23:59:59' ) );

		// The price inputs use WooCommerce's admin form helpers.
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

		ob_start();
		PriceFields::render_variation( 2, array(), get_post( $variation->get_id() ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="2026-10-20"', $html );
	}
}
