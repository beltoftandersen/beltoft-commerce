<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Vat\OrderTaxDisplay;
use BeltoftCommerce\Vat\TaxDisplay;

class OrderTaxDisplayTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		update_option( 'woocommerce_tax_total_display', 'single' );

		// Two real rates, so the per-rate split has something to split.
		WC_Tax::_insert_tax_rate( array(
			'tax_rate_country' => '', 'tax_rate' => '23.0000',
			'tax_rate_name' => 'IVA23', 'tax_rate_class' => '',
		) );
		WC_Tax::_insert_tax_rate( array(
			'tax_rate_country' => '', 'tax_rate' => '6.0000',
			'tax_rate_name' => 'IVA06', 'tax_rate_class' => 'reduced-rate',
		) );

		OrderTaxDisplay::register();
		TaxDisplay::register();
	}

	public function tearDown(): void {
		remove_all_filters( 'woocommerce_get_order_item_totals' );
		remove_all_filters( 'woocommerce_order_formatted_line_subtotal' );
		remove_all_filters( 'option_woocommerce_tax_total_display' );
		remove_all_filters( 'option_woocommerce_tax_display_cart' );
		remove_all_filters( 'option_woocommerce_tax_display_shop' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function product( string $tax_class ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_tax_class( $tax_class );
		$product->set_status( 'publish' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function order( bool $prices_include_tax ): WC_Order {
		$order = new WC_Order();
		$order->set_prices_include_tax( $prices_include_tax );
		$order->add_product( $this->product( '' ), 2 );
		$order->add_product( $this->product( 'reduced-rate' ), 1 );
		$order->calculate_totals( true );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_a_net_order_renders_its_totals_net_with_no_session() {
		// The cron-sent email / wp-admin case: no user, option says incl.
		wp_set_current_user( 0 );
		$order = $this->order( false );

		$rows = $order->get_order_item_totals();

		$tax_rows = array_filter( $rows, static fn( $r ) => 'tax' === ( $r['type'] ?? '' ) );

		$this->assertArrayHasKey( 'cart_subtotal', $rows );
		// An incl rendering folds tax into the total and emits no tax row.
		$this->assertNotEmpty( $tax_rows, 'a net order must show its tax broken out, whatever the session' );

		// And broken out PER RATE, not as one combined line.
		$this->assertCount( 2, $tax_rows, 'a two-rate order must show two tax rows' );

		$labels = implode( ' ', array_column( $tax_rows, 'label' ) );
		$this->assertStringContainsString( 'IVA23', $labels );
		$this->assertStringContainsString( 'IVA06', $labels );
	}

	public function test_a_gross_order_is_left_alone() {
		wp_set_current_user( 0 );
		$order = $this->order( true );

		$rows = $order->get_order_item_totals();

		$this->assertSame(
			array(),
			array_filter( $rows, static fn( $r ) => 'tax' === ( $r['type'] ?? '' ) ),
			'a retail order still displays incl-IVA with no separate tax row'
		);
	}

	public function test_line_subtotals_on_a_net_order_exclude_tax() {
		wp_set_current_user( 0 );
		$order = $this->order( false );

		$shown = array();

		foreach ( $order->get_items() as $item ) {
			$text = html_entity_decode( wp_strip_all_tags( $order->get_formatted_line_subtotal( $item ) ) );
			$shown[] = (float) preg_replace( '/[^0-9.]/', '', $text );
		}

		sort( $shown );

		// 1 x 10 and 2 x 10, net. A gross rendering would show 10.60 and
		// 24.60 — the tax added on top of a price that already excludes it.
		$this->assertSame( array( 10.0, 20.0 ), $shown );
	}

	public function test_the_rerender_does_not_leak_itemized_into_later_reads() {
		wp_set_current_user( 0 );
		$order = $this->order( false );
		$order->get_order_item_totals();

		$this->assertSame(
			'single',
			get_option( 'woocommerce_tax_total_display' ),
			'the itemized override must be scoped to the re-render'
		);
	}

	public function test_a_b2b_session_gets_itemized_tax() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertSame( 'itemized', get_option( 'woocommerce_tax_total_display' ) );
	}

	public function test_retail_keeps_the_combined_tax_line() {
		wp_set_current_user( 0 );

		$this->assertSame( 'single', get_option( 'woocommerce_tax_total_display' ) );
	}
}
