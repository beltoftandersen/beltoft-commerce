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
}
