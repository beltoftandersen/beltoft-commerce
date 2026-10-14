<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\QuickOrder\Sheet\Writer;

class WriterTest extends WP_UnitTestCase {

	public function test_it_produces_a_readable_zip_with_the_expected_parts() {
		$xlsx = Writer::build( array(
			array( 'sku' => 'A-1', 'name' => 'Produto A', 'variant' => '', 'stock' => 'Em stock', 'price' => '7,27', 'rrp' => '12,12' ),
		) );

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		file_put_contents( $file, $xlsx );

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $file ) );
		$this->assertNotFalse( $zip->locateName( '[Content_Types].xml' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/workbook.xml' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet1.xml' ) );
		$zip->close();

		unlink( $file );
	}

	/**
	 * Accented product names are the normal case on this store, so the
	 * encoding is asserted rather than assumed.
	 */
	public function test_accented_names_survive_into_the_sheet() {
		$xlsx = Writer::build( array(
			array( 'sku' => 'A-2', 'name' => 'Ácido Hialurónico', 'variant' => '', 'stock' => 'Em stock', 'price' => '7,27', 'rrp' => '12,12' ),
		) );

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		file_put_contents( $file, $xlsx );

		$zip = new ZipArchive();
		$zip->open( $file );
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		unlink( $file );

		$this->assertStringContainsString( 'Ácido Hialurónico', $sheet );
	}

	public function test_rows_only_include_products_this_buyer_can_order() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'QO-VISIBLE' );
		$product->set_regular_price( '10' );
		$product->set_stock_status( 'instock' );
		$visible = $product->save();
		Taxonomy::set_audience( $visible, 'both' );

		$skus = wp_list_pluck( Writer::rows( null ), 'sku' );

		$this->assertContains( 'QO-VISIBLE', $skus );
	}

	/**
	 * The negative half of the audience test. A positive-only assertion passes
	 * just as happily when the filter does nothing at all.
	 */
	public function test_rows_exclude_a_product_this_buyer_may_not_order() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'QO-HIDDEN' );
		$product->set_regular_price( '10' );
		$product->set_stock_status( 'instock' );
		$hidden = $product->save();
		Taxonomy::set_audience( $hidden, 'b2b' );

		$skus = wp_list_pluck( Writer::rows( null ), 'sku' );

		$this->assertNotContains( 'QO-HIDDEN', $skus );
	}

	/**
	 * A variable product must contribute its variations, because a variation is
	 * the orderable SKU. A parent row would be a line nobody can order.
	 */
	public function test_a_variable_product_contributes_variations_not_the_parent() {
		$parent = new WC_Product_Variable();
		$parent->set_sku( 'QO-PARENT' );
		$parent_id = $parent->save();
		Taxonomy::set_audience( $parent_id, 'both' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_sku( 'QO-CHILD' );
		$variation->set_regular_price( '10' );
		$variation->set_stock_status( 'instock' );
		$variation->save();

		$skus = wp_list_pluck( Writer::rows( null ), 'sku' );

		$this->assertContains( 'QO-CHILD', $skus );
		$this->assertNotContains( 'QO-PARENT', $skus );
	}
}
