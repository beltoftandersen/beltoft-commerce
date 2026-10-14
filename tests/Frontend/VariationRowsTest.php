<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Frontend\VariationRows;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

class VariationRowsTest extends WP_UnitTestCase {

	private WC_Product_Variable $parent;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->parent         = $this->make_variable();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_variable(): WC_Product_Variable {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Cor' );
		$attribute->set_options( array( 'Original', 'Otimista' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Amaciador Sólido' );
		$parent->set_status( 'publish' );
		$parent->set_sku( 'AMA' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		Taxonomy::set_audience( $parent->get_id(), Taxonomy::BOTH );

		foreach ( array( 'Original' => 'AMA-ORI', 'Otimista' => 'AMA-OTI' ) as $label => $sku ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $parent->get_id() );
			$v->set_regular_price( '10' );
			$v->set_sku( $sku );
			$v->set_attributes( array( 'cor' => $label ) );
			$v->save();
		}

		return new WC_Product_Variable( $parent->get_id() );
	}

	private function expand( string $term = '' ): array {
		$q = new WP_Query();
		$q->posts      = array( get_post( $this->parent->get_id() ) );
		$q->post_count = 1;
		$q->set( 's', $term );

		$query = new stdClass();
		$query->object_type = 'post';

		return VariationRows::expand( $q, $query )->posts;
	}

	public function test_a_variable_parent_becomes_one_row_per_variation() {
		$posts = $this->expand();

		$this->assertCount( 2, $posts );
		foreach ( $posts as $p ) {
			$this->assertSame( 'product_variation', $p->post_type );
		}
	}

	public function test_a_variation_row_is_titled_parent_dash_variation() {
		$posts = $this->expand();
		$title = VariationRows::variation_title( '', $posts[0]->ID );

		$this->assertStringStartsWith( 'Amaciador Sólido - ', $title );
	}

	public function test_an_exact_sku_narrows_to_that_one_variation() {
		/*
		 * Relevanssi indexes a variation's codes against the PARENT, so the
		 * search matches the parent and would otherwise answer a scan of one
		 * barcode with every sibling.
		 */
		$posts = $this->expand( 'AMA-OTI' );

		$this->assertCount( 1, $posts );
		$this->assertSame( 'AMA-OTI', wc_get_product( $posts[0]->ID )->get_sku() );
	}

	public function test_a_partial_word_still_shows_the_whole_family() {
		$this->assertCount( 2, $this->expand( 'Amaciador' ) );
	}

	public function test_retail_is_untouched() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$posts = $this->expand();

		$this->assertCount( 1, $posts );
		$this->assertSame( 'product', $posts[0]->post_type );
	}

	/**
	 * The heading counted posts while the list showed rows: a category of four
	 * simple products and three variable ones rendered nine lines under
	 * "7 artigos".
	 */
	public function test_the_reported_total_matches_the_rows_on_a_single_page() {
		$result = (object) array(
			'posts'         => array(),
			'post_count'    => 0,
			'found_posts'   => 0,
			'max_num_pages' => 1,
		);

		$before = VariationRows::expand( $result );

		$this->assertSame(
			count( $before->posts ),
			$before->found_posts,
			'With everything on one page the expanded count IS the total.'
		);
	}

	public function test_a_multi_page_total_is_left_alone_rather_than_guessed() {
		$result = (object) array(
			'posts'         => array(),
			'post_count'    => 0,
			'found_posts'   => 120,
			'max_num_pages' => 6,
		);

		$after = VariationRows::expand( $result );

		$this->assertSame( 120, $after->found_posts, 'Not extrapolated from one page.' );
	}

}
