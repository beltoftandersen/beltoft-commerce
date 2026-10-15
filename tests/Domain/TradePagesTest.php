<?php

use BeltoftCommerce\Support\Hosts;

class TradePagesTest extends WP_UnitTestCase {

	private int $trade;
	private int $retail;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->trade  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-envios', 'post_title' => 'Envios revenda' ) );
		$this->retail = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'envios', 'post_title' => 'Envios' ) );
		$_SERVER['HTTP_HOST'] = Hosts::retail();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	public function test_a_trade_page_is_not_described_on_retail() {
		$this->assertSame( 404, $this->get( '/wp/v2/pages/' . $this->trade )->get_status() );
		$this->assertSame( array(), $this->get( '/wp/v2/pages', array( 'slug' => 'b2b-envios' ) )->get_data() );
		$this->assertSame( 0, apply_filters( 'oembed_request_post_id', $this->trade, get_permalink( $this->trade ) ) );
	}

	public function test_a_retail_page_still_is() {
		$this->assertSame( 200, $this->get( '/wp/v2/pages/' . $this->retail )->get_status() );
	}

	public function test_editors_still_load_trade_pages() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 200, $this->get( '/wp/v2/pages/' . $this->trade )->get_status() );
	}
}
