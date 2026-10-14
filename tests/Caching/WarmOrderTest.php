<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

/**
 * §2.9: "warm all product, variation and fragment caches as retail, then
 * request as B2B; flush, warm as B2B, then request as retail. Output must
 * be group-correct in both directions."
 *
 * Bypassing full-page cache does not make caching safe. Several caches sit
 * below it and are shared across sessions, and any cache key that omits the
 * pricing group is a cross-group leak that only appears under a specific
 * warm order.
 */
class WarmOrderTest extends WP_UnitTestCase {

	private int $simple_id;
	private int $variable_id;
	private int $b2b_user;

	private string $retail_price = '100';
	private string $b2b_price    = '60';

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		$simple = new WC_Product_Simple();
		$simple->set_regular_price( $this->retail_price );
		$simple->set_status( 'publish' );
		$simple->save();
		update_post_meta( $simple->get_id(), '_price_group_b2b_standard', $this->b2b_price );
		Taxonomy::set_audience( $simple->get_id(), Taxonomy::BOTH );
		$this->simple_id = $simple->get_id();

		$variable = new WC_Product_Variable();
		$variable->save();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'small' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );
		$variable->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_attributes( array( 'size' => 'small' ) );
		$variation->set_regular_price( $this->retail_price );
		$variation->save();
		update_post_meta( $variation->get_id(), '_price_group_b2b_standard', $this->b2b_price );

		WC_Product_Variable::sync( $variable->get_id() );
		Taxonomy::set_audience( $variable->get_id(), Taxonomy::BOTH );
		$this->variable_id = $variable->get_id();

		$this->b2b_user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function as_guest(): void {
		wp_set_current_user( 0 );
	}

	private function as_b2b(): void {
		wp_set_current_user( $this->b2b_user );
	}

	/** Extracts the first price figure from rendered markup. */
	private function first_price_in( string $html ): string {
		preg_match( '/([0-9]+(?:[.,][0-9]{2})?)/', html_entity_decode( wp_strip_all_tags( $html ) ), $m );

		// Normalised to a bare float string so every layer is compared on
		// the same footing — price_html renders "60,00", the object
		// returns "60".
		return isset( $m[1] ) ? (string) (float) str_replace( ',', '.', $m[1] ) : '';
	}

	/**
	 * PHPUnit runs data providers BEFORE setUp(), so a provider cannot
	 * touch the fixtures. It therefore yields layer names only, and the
	 * closures are resolved inside the test, after setUp() has run.
	 */
	public static function cache_layers(): array {
		return array(
			'variation price range'  => array( 'variation price range' ),
			'simple product price'   => array( 'simple product price' ),
			'price html fragment'    => array( 'price html fragment' ),
			'variation object price' => array( 'variation object price' ),
		);
	}

	/**
	 * Each layer supplies a warm closure and a read closure. A layer whose
	 * closures are not written is an untested cache — never leave one as a
	 * stub, because a green run would then mean nothing for that layer.
	 */
	private function layer( string $name ): array {
		$simple   = $this->simple_id;
		$variable = $this->variable_id;

		$layers = array(
			'variation price range' => array(
				'warm' => function () use ( $variable ) {
					wc_get_product( $variable )->get_variation_prices( true );
				},
				'read' => function () use ( $variable ) {
					$prices = wc_get_product( $variable )->get_variation_prices( true );

					return (string) (float) reset( $prices['price'] );
				},
			),
			'simple product price' => array(
				'warm' => function () use ( $simple ) {
					wc_get_product( $simple )->get_price();
				},
				'read' => function () use ( $simple ) {
					return (string) (float) wc_get_product( $simple )->get_price();
				},
			),
			'price html fragment' => array(
				'warm' => function () use ( $simple ) {
					wc_get_product( $simple )->get_price_html();
				},
				'read' => function () use ( $simple ) {
					return $this->first_price_in( wc_get_product( $simple )->get_price_html() );
				},
			),
			'variation object price' => array(
				'warm' => function () use ( $variable ) {
					$id = wc_get_product( $variable )->get_children()[0];
					wc_get_product( $id )->get_price();
				},
				'read' => function () use ( $variable ) {
					$id = wc_get_product( $variable )->get_children()[0];

					return (string) (float) wc_get_product( $id )->get_price();
				},
			),
		);

		if ( ! isset( $layers[ $name ] ) ) {
			$this->fail( "Untested cache layer: {$name}" );
		}

		return $layers[ $name ];
	}

	/** @dataProvider cache_layers */
	public function test_warming_as_retail_does_not_leak_into_b2b( string $name ) {
		$layer = $this->layer( $name );

		$this->as_guest();
		( $layer['warm'] )();

		$this->as_b2b();

		$this->assertSame( (string) (float) $this->b2b_price, ( $layer['read'] )() );
	}

	/** @dataProvider cache_layers */
	public function test_warming_as_b2b_does_not_leak_into_retail( string $name ) {
		$layer = $this->layer( $name );

		$this->as_b2b();
		( $layer['warm'] )();

		$this->as_guest();

		$this->assertSame( (string) (float) $this->retail_price, ( $layer['read'] )() );
	}

	/**
	 * The audience filter has its own caches (term relationships, query
	 * results), so the same discipline applies to catalog visibility.
	 */
	public function test_warming_a_listing_as_retail_does_not_leak_into_b2b() {
		$retail_only = new WC_Product_Simple();
		$retail_only->set_regular_price( '100' );
		$retail_only->set_status( 'publish' );
		$retail_only->save();

		$ids = function () {
			$q = new WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			) );

			return array_map( 'intval', $q->posts );
		};

		$this->as_guest();
		$this->assertContains( $retail_only->get_id(), $ids() );

		$this->as_b2b();
		$this->assertNotContains( $retail_only->get_id(), $ids() );
	}
}
