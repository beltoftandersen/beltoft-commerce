<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class StoreApiPriceTest extends WP_UnitTestCase {

	private int $product_id;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();

		do_action( 'rest_api_init' );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();
		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );
		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );

		$this->product_id = $product->get_id();
		$this->b2b_user   = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	/**
	 * The Store API returns nested stdClass objects, so the response is
	 * round-tripped through JSON to get plain arrays.
	 */
	private function fetch_product(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $this->product_id ) );

		return json_decode( wp_json_encode( $response->get_data() ), true );
	}

	public function test_an_anonymous_request_gets_retail_pricing() {
		wp_set_current_user( 0 );
		$data = $this->fetch_product();

		$this->assertSame( '10000', $data['prices']['price'] );
	}

	public function test_a_b2b_request_gets_the_group_price() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		$data = $this->fetch_product();

		$this->assertSame( '6000', $data['prices']['price'] );
	}

	/**
	 * The property §3 rests on: wholesale pricing must never appear on the
	 * anonymous rendering path.
	 */
	public function test_no_group_price_appears_anywhere_in_an_anonymous_response() {
		wp_set_current_user( 0 );
		$body = wp_json_encode( $this->fetch_product() );

		$this->assertStringNotContainsString( '6000', $body );
		$this->assertStringNotContainsString( '_price_group_b2b_standard', $body );
	}

	public function test_a_pending_user_is_treated_as_anonymous_for_pricing() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'b2b_pending' ) ) );
		$body = wp_json_encode( $this->fetch_product() );

		$this->assertStringNotContainsString( '6000', $body );
	}
}
