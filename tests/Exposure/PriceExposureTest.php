<?php

use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

require_once __DIR__ . '/RouteInventory.php';

/**
 * §5 anonymous data exposure contract.
 *
 * The property that anonymous requests never see wholesale pricing is
 * maintained by test, not by assumption.
 */
class PriceExposureTest extends WP_UnitTestCase {

	private int $product_id;
	private array $group_prices = array();

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		do_action( 'rest_api_init' );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();
		$this->product_id = $product->get_id();
		Taxonomy::set_audience( $this->product_id, Taxonomy::BOTH );

		// A distinctive price per registered group, so a leak is
		// unmistakable in a response body. Iterates the registry, so a new
		// group is covered without touching this test.
		$n = 0;
		foreach ( array_keys( Registry::groups() ) as $slug ) {
			$price = (string) ( 7770 + $n );
			update_post_meta( $this->product_id, Registry::regular_key( $slug ), $price );
			$this->group_prices[ $slug ] = $price;
			$n++;
		}
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	/**
	 * Contexts that must be pricing-equivalent to anonymous.
	 */
	public function anonymous_equivalent_contexts(): array {
		return array(
			'guest'       => array( 'guest' ),
			'customer'    => array( 'customer' ),
			'b2b_pending' => array( 'b2b_pending' ),
		);
	}

	/** Fills route placeholders with this test's product. */
	private function resolve( string $route ): string {
		$product = wc_get_product( $this->product_id );

		return str_replace(
			array( '(?P<id>[\d]+)', '(?P<slug>[\S]+)' ),
			array( (string) $this->product_id, $product->get_slug() ),
			$route
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
	public function test_no_group_price_leaks_from_any_rest_route( string $context ) {
		$this->apply_context( $context );

		foreach ( RouteInventory::rest() as $route ) {
			$path     = $this->resolve( $route );
			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			$body     = wp_json_encode( $response->get_data() );

			foreach ( $this->group_prices as $slug => $price ) {
				$this->assertStringNotContainsString(
					$price,
					(string) $body,
					"Group price for {$slug} leaked in {$path} as {$context}"
				);
			}
		}
	}

	/** @dataProvider anonymous_equivalent_contexts */
	public function test_no_group_meta_key_leaks_from_any_rest_route( string $context ) {
		$this->apply_context( $context );

		foreach ( RouteInventory::rest() as $route ) {
			$path     = $this->resolve( $route );
			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			$body     = (string) wp_json_encode( $response->get_data() );

			foreach ( Registry::all_price_keys() as $key ) {
				$this->assertStringNotContainsString( $key, $body, "Meta key {$key} leaked in {$path}" );
			}
		}
	}

	public function test_the_woocommerce_rest_api_is_unreachable_without_authentication() {
		wp_set_current_user( 0 );

		foreach ( RouteInventory::authenticated_only() as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );

			$this->assertGreaterThanOrEqual(
				400,
				$response->get_status(),
				"{$route} must not be readable anonymously"
			);
		}
	}

	/**
	 * §5: "the registered-route list matches the inventory — a new unlisted
	 * route fails the build". Scoped to the namespaces that can expose
	 * product data.
	 */
	public function test_no_unlisted_product_bearing_rest_route_exists() {
		$registered = array_keys( rest_get_server()->get_routes() );

		$product_routes = array_values( array_filter(
			$registered,
			function ( $route ) {
				return (bool) preg_match( '#^/wc/store/v1/products|^/wp/v2/product(_variation)?(/|$)#', $route );
			}
		) );

		$unlisted = array_diff( $product_routes, RouteInventory::all_product_namespace() );

		$this->assertSame(
			array(),
			array_values( $unlisted ),
			'Unlisted product route(s) found. Review against the §5 contract and add to RouteInventory: '
				. implode( ', ', $unlisted )
		);
	}

	public function test_a_b2b_customer_does_see_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		// The contract must not be satisfied by the price simply never
		// appearing anywhere.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_customer' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $this->product_id ) );
		$body     = (string) wp_json_encode( $response->get_data() );

		$this->assertStringContainsString( '777000', $body );
	}
}
