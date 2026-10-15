<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

require_once __DIR__ . '/RouteInventory.php';

/**
 * §5 exposure contract, audience half.
 *
 * The price half asserts that wholesale numbers never reach an anonymous
 * caller. This asserts the catalog half: a B2B-only product must not appear
 * to anyone outside the B2B audience, and a retail-only product must not
 * appear to a B2B session, on any inventoried route.
 */
class AudienceExposureTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $b2b_only;
	private string $b2b_only_name = 'Mostruario Trade Only Fixture';

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		do_action( 'rest_api_init' );

		$this->retail_only = $this->make( 'Retail Fixture Product', null );
		$this->b2b_only    = $this->make( $this->b2b_only_name, Taxonomy::B2B );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make( string $name, ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		return $product->get_id();
	}

	public function anonymous_equivalent_contexts(): array {
		return array(
			'guest'       => array( 'guest' ),
			'customer'    => array( 'customer' ),
			'b2b_pending' => array( 'b2b_pending' ),
		);
	}

	private function apply_context( string $context ): void {
		if ( 'guest' === $context ) {
			wp_set_current_user( 0 );

			return;
		}

		wp_set_current_user( $this->factory->user->create( array( 'role' => $context ) ) );
	}

	/** @dataProvider anonymous_equivalent_contexts */
	public function test_a_b2b_only_product_never_appears_to_a_retail_context( string $context ) {
		$this->apply_context( $context );

		foreach ( RouteInventory::product_bearing() as $route ) {
			// The by-id and by-slug routes are tested separately below;
			// here the concern is listings leaking the product.
			if ( false !== strpos( $route, '(?P<' ) ) {
				continue;
			}

			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$body     = (string) wp_json_encode( $response->get_data() );

			$this->assertStringNotContainsString(
				$this->b2b_only_name,
				$body,
				"B2B-only product leaked in {$route} as {$context}"
			);
		}
	}

	/** @dataProvider anonymous_equivalent_contexts */
	public function test_a_direct_fetch_of_a_b2b_only_product_is_denied( string $context ) {
		$this->apply_context( $context );

		$status = rest_do_request(
			new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $this->b2b_only )
		)->get_status();

		$this->assertSame( 404, $status, "direct fetch should 404 for {$context}" );
	}

	public function test_a_retail_only_product_never_appears_to_a_b2b_session() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );
		$ids      = array_map( 'intval', wp_list_pluck(
			json_decode( wp_json_encode( $response->get_data() ), true ),
			'id'
		) );

		$this->assertNotContains( $this->retail_only, $ids );
	}

	/**
	 * Counter-test: the contract must not be satisfied by the product being
	 * invisible to everyone.
	 */
	public function test_a_b2b_customer_does_see_the_b2b_only_product() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$status = rest_do_request(
			new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $this->b2b_only )
		)->get_status();

		$this->assertSame( 200, $status );
	}

	/** WordPress's own product route reads the post directly. */
	/** @dataProvider anonymous_equivalent_contexts */
	public function test_the_core_product_route_denies_a_b2b_only_product( string $context ) {
		$this->apply_context( $context );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/product/' . $this->b2b_only ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringNotContainsString( $this->b2b_only_name, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_the_core_product_route_still_serves_a_retail_product() {
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/product/' . $this->retail_only ) )->get_status() );
	}

	/** Whoever may edit the product loads it in the editor, whatever their audience. */
	public function test_an_editor_may_load_a_b2b_only_product() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/product/' . $this->b2b_only ) )->get_status() );
	}

	/** oEmbed describes any URL it is given; not a product outside the audience. */
	public function test_oembed_does_not_describe_a_b2b_only_product() {
		$this->assertSame( 0, apply_filters( 'oembed_request_post_id', $this->b2b_only, get_permalink( $this->b2b_only ) ) );
		$this->assertSame( $this->retail_only, apply_filters( 'oembed_request_post_id', $this->retail_only, get_permalink( $this->retail_only ) ) );
	}

	private function variable( string $name, ?string $audience ): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$parent->save();

		if ( $audience ) {
			Taxonomy::set_audience( $parent->get_id(), $audience );
		}

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->set_sku( 'VAR-' . $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->save();

		return array( $parent->get_id(), $variation->get_id() );
	}

	private function store_ids( string $query ): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );
		$request  = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		parse_str( $query, $params );
		$request->set_query_params( $params );

		return array_map( 'intval', wp_list_pluck( json_decode( wp_json_encode( rest_do_request( $request )->get_data() ), true ), 'id' ) );
	}

	/** Variations carry no audience term; they are judged by their parent. */
	/** @dataProvider anonymous_equivalent_contexts */
	public function test_variations_of_a_b2b_only_product_are_not_listed_to_retail( string $context ) {
		list( , $hidden ) = $this->variable( 'Trade variable', Taxonomy::B2B );
		list( , $shown )  = $this->variable( 'Retail variable', null );
		$this->apply_context( $context );

		$ids = $this->store_ids( 'type=variation&per_page=100' );

		$this->assertNotContains( $hidden, $ids );
		$this->assertContains( $shown, $ids );
		$this->assertNotContains( $hidden, $this->store_ids( 'sku=VAR-' . wp_get_post_parent_id( $hidden ) ) );
	}

	public function test_a_b2b_session_lists_only_variations_of_its_catalogue() {
		list( , $retail ) = $this->variable( 'Retail variable', null );
		list( , $trade )  = $this->variable( 'Trade variable', Taxonomy::BOTH );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$ids = $this->store_ids( 'type=variation&per_page=100' );

		$this->assertNotContains( $retail, $ids );
		$this->assertContains( $trade, $ids );
	}

	/** get_posts() suppresses posts_* filters; the audience must hold there too. */
	public function test_a_mixed_get_posts_lookup_is_filtered_for_retail() {
		list( $parent, $variation ) = $this->variable( 'Trade variable', Taxonomy::B2B );

		$ids = get_posts( array(
			'post_type'   => array( 'product', 'product_variation' ),
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		) );

		$this->assertNotContains( $this->b2b_only, $ids );
		$this->assertNotContains( $parent, $ids );
		$this->assertNotContains( $variation, $ids );
		$this->assertContains( $this->retail_only, $ids );
	}

	/**
	 * A product's own children are looked up by post_parent and cached for
	 * everyone; they are not filtered (the product itself is).
	 */
	public function test_a_products_own_variations_are_not_filtered() {
		list( $parent, $variation ) = $this->variable( 'Trade variable', Taxonomy::B2B );

		$ids = get_posts( array(
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
			'numberposts' => -1,
			'fields'      => 'ids',
		) );

		$this->assertSame( array( $variation ), array_map( 'intval', $ids ) );
	}

	/** Staff working in wp-admin (its AJAX included) see the whole catalogue. */
	public function test_staff_in_admin_see_every_variation() {
		list( , $variation ) = $this->variable( 'Trade variable', Taxonomy::B2B );
		$query = static fn() => ( new WP_Query( array( 'post_type' => 'product_variation', 'posts_per_page' => -1, 'fields' => 'ids' ) ) )->posts;

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertNotContains( $variation, $query(), 'on the shop a shop manager shops like anyone' );

		set_current_screen( 'edit-product' );
		$this->assertContains( $variation, $query() );
		set_current_screen( 'front' );
	}

	/** include=, on_sale= and parent= narrow by id; the audience still holds. */
	/** @dataProvider anonymous_equivalent_contexts */
	public function test_narrowing_by_id_does_not_reach_a_b2b_only_product( string $context ) {
		list( $parent, $variation ) = $this->variable( 'Trade variable', Taxonomy::B2B );
		$this->apply_context( $context );

		$this->assertNotContains( $this->b2b_only, $this->store_ids( 'include[]=' . $this->b2b_only . '&include[]=' . $this->retail_only ) );
		$this->assertContains( $this->retail_only, $this->store_ids( 'include[]=' . $this->b2b_only . '&include[]=' . $this->retail_only ) );
		$this->assertNotContains( $variation, $this->store_ids( 'type=variation&parent[]=' . $parent ) );
		$this->assertNotContains( $variation, $this->store_ids( 'type=variation&include[]=' . $variation ) );
	}

	/** Another plugin's own variation lookup (get_posts) is not a listing and is left alone. */
	public function test_a_variation_only_get_posts_lookup_is_not_filtered() {
		list( , $variation ) = $this->variable( 'Trade variable', Taxonomy::B2B );

		$this->assertContains( $variation, get_posts( array( 'post_type' => 'product_variation', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}
}
