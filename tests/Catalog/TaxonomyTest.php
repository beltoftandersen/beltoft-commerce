<?php

use BeltoftCommerce\Catalog\Taxonomy;

class TaxonomyTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Taxonomy::register();
	}

	/**
	 * Registered on init, not on plugins_loaded where Plugin::init() runs: its
	 * labels are translated, and translating before init made WordPress log
	 * _load_textdomain_just_in_time for beltoft-commerce on every request.
	 */
	public function test_it_is_registered_on_init_not_while_plugins_load() {
		$this->assertNotFalse( has_action( 'init', array( Taxonomy::class, 'register' ) ) );
	}

	public function test_the_taxonomy_is_registered_against_products() {
		$this->assertTrue( taxonomy_exists( Taxonomy::TAXONOMY ) );
		$this->assertContains( 'product', get_taxonomy( Taxonomy::TAXONOMY )->object_type );
	}

	/**
	 * The term itself is information about the B2B catalog, so it must not
	 * be published to anonymous REST callers.
	 */
	public function test_it_is_not_exposed_in_rest_or_public() {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );

		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->show_in_rest );
	}

	/** The ids WP_Tax_Query would turn the slugs into: each term, its children, and null for a slug with no term. */
	public function test_term_taxonomy_ids_are_the_terms_and_their_children() {
		$b2b   = get_term_by( 'slug', Taxonomy::B2B, Taxonomy::TAXONOMY );
		$both  = get_term_by( 'slug', Taxonomy::BOTH, Taxonomy::TAXONOMY );
		$child = wp_insert_term( 'Stands', Taxonomy::TAXONOMY, array( 'slug' => 'b2b-stands', 'parent' => (int) $b2b->term_id ) );
		$grand = wp_insert_term( 'Small stands', Taxonomy::TAXONOMY, array( 'slug' => 'b2b-stands-small', 'parent' => (int) $child['term_id'] ) );

		$expected = array( (int) $b2b->term_taxonomy_id, (int) $child['term_taxonomy_id'], (int) $grand['term_taxonomy_id'] );
		sort( $expected );

		$this->assertSame( $expected, Taxonomy::term_taxonomy_ids( array( Taxonomy::B2B ) ) );
		$this->assertSame( array( (int) $both->term_taxonomy_id ), Taxonomy::term_taxonomy_ids( array( Taxonomy::BOTH ) ) );
		$this->assertNull( Taxonomy::term_taxonomy_ids( array( 'no-such-audience' ) ) );

		wp_delete_term( (int) $grand['term_id'], Taxonomy::TAXONOMY );
		$this->assertNotContains( (int) $grand['term_taxonomy_id'], Taxonomy::term_taxonomy_ids( array( Taxonomy::B2B ) ), 'a term change is seen at once' );
	}

	public function test_only_the_two_real_terms_exist() {
		foreach ( array( Taxonomy::B2B, Taxonomy::BOTH ) as $slug ) {
			$this->assertNotNull(
				term_exists( $slug, Taxonomy::TAXONOMY ),
				"term {$slug} should exist"
			);
		}

		/*
		 * "Retail only" is what no term already means, so a term for it would
		 * be the same fact spelled twice - and an invitation to tag half the
		 * catalogue with the default.
		 */
		$this->assertNull(
			term_exists( Taxonomy::RETAIL, Taxonomy::TAXONOMY ),
			'retail must not be offered as a term'
		);
	}

	public function test_setting_retail_clears_the_term_rather_than_failing() {
		$product = new WC_Product_Simple();
		$id      = $product->save();

		Taxonomy::set_audience( $id, Taxonomy::B2B );
		$this->assertSame( Taxonomy::B2B, Taxonomy::audience_of( $id ) );

		$this->assertTrue( Taxonomy::set_audience( $id, Taxonomy::RETAIL ) );
		$this->assertSame( Taxonomy::RETAIL, Taxonomy::audience_of( $id ) );
		$this->assertSame( array(), wp_get_object_terms( $id, Taxonomy::TAXONOMY, array( 'fields' => 'slugs' ) ) );
	}

	public function test_audience_of_an_untagged_product_defaults_to_retail() {
		$product = new WC_Product_Simple();
		$product->save();

		// Absence is the default because B2B is the restricted audience:
		// tagging all 481 retail products to say "retail" would be noise.
		$this->assertSame( Taxonomy::RETAIL, Taxonomy::audience_of( $product->get_id() ) );
	}

	public function test_audience_reflects_an_assigned_term() {
		$product = new WC_Product_Simple();
		$product->save();

		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );

		$this->assertSame( Taxonomy::BOTH, Taxonomy::audience_of( $product->get_id() ) );
	}

	public function test_setting_an_audience_replaces_rather_than_appends() {
		$product = new WC_Product_Simple();
		$product->save();

		Taxonomy::set_audience( $product->get_id(), Taxonomy::B2B );
		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );

		$terms = wp_get_object_terms( $product->get_id(), Taxonomy::TAXONOMY, array( 'fields' => 'slugs' ) );

		$this->assertSame( array( Taxonomy::BOTH ), $terms );
	}

	public function test_an_invalid_audience_is_rejected() {
		$product = new WC_Product_Simple();
		$product->save();

		$this->assertFalse( Taxonomy::set_audience( $product->get_id(), 'nonsense' ) );
		$this->assertSame( Taxonomy::RETAIL, Taxonomy::audience_of( $product->get_id() ) );
	}

	/**
	 * A variation inherits its parent's audience.
	 *
	 * Regression test for a defect that produced no error anywhere: the
	 * taxonomy is registered on `product` only, so a variation carries no term
	 * and fell through to the retail default. WooCommerce's AJAX add-to-cart
	 * passes the VARIATION id to `woocommerce_add_to_cart_validation`, so a B2B
	 * customer adding any variable product was refused, and the refusal arrived
	 * as Woo's generic "go to the product page" response with nothing naming
	 * the audience as the cause.
	 */
	public function test_a_variation_inherits_the_audience_of_its_parent() {
		$parent = self::factory()->post->create( array( 'post_type' => 'product' ) );
		Taxonomy::set_audience( $parent, Taxonomy::BOTH );

		$variation = self::factory()->post->create( array(
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
		) );

		$this->assertSame( Taxonomy::BOTH, Taxonomy::audience_of( $variation ) );
	}

	public function test_an_orphan_variation_still_falls_back_to_retail() {
		$variation = self::factory()->post->create( array( 'post_type' => 'product_variation' ) );

		$this->assertSame( Taxonomy::RETAIL, Taxonomy::audience_of( $variation ) );
	}
}
