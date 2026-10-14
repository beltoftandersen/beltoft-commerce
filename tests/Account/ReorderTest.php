<?php

use BeltoftCommerce\Account\Reorder;
use BeltoftCommerce\SavedCart\Repository;

/**
 * A line whose product has since been deleted must be reported, by name -
 * dropping it silently is exactly the "ordered 40, got 37" this exists to stop.
 */
class ReorderTest extends WP_UnitTestCase {

	private function order_with( array $products ): WC_Order {
		$order = new WC_Order();

		foreach ( $products as $product ) {
			$order->add_product( $product, 2 );
		}

		$order->save();

		return wc_get_order( $order->get_id() );
	}

	private function product( string $name ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '5' );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	public function test_a_deleted_products_line_is_still_handed_on_with_its_name() {
		$kept    = $this->product( 'Sabonete Lavanda' );
		$deleted = $this->product( 'Amaciador Antigo' );
		$order   = $this->order_with( array( $kept, $deleted ) );

		wp_delete_post( $deleted->get_id(), true );

		$items = Reorder::items_from_order( wc_get_order( $order->get_id() ) );

		$this->assertCount( 2, $items );
		$this->assertContains( 'Amaciador Antigo', array_column( $items, 'name' ) );
	}

	public function test_the_report_names_a_product_that_no_longer_exists() {
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();

		$result = Repository::restore_items(
			array( array( 'product_id' => 999999, 'variation_id' => 0, 'quantity' => 1, 'name' => 'Amaciador Antigo' ) ),
			self::factory()->user->create(),
			false
		);

		$this->assertContains( 'Amaciador Antigo (já não existe)', $result['skipped'] );
	}
}
