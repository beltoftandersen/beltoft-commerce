<?php

use BeltoftCommerce\Frontend\AddToCartUrl;
use BeltoftCommerce\Frontend\QuickOrderPagePathShim;
use BeltoftCommerce\Support\Hosts;

class AddToCartUrlTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		// The rendering context this class exists for: markup built inside an
		// AJAX/REST request rather than a page view.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->report( null );
	}

	public function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_SERVER['HTTP_HOST'] );
		$this->report( null );
		parent::tearDown();
	}

	/** Feed AddToCartUrl the page Bricks would have reported. */
	private function report( ?string $base ): void {
		$request = new WP_REST_Request( 'POST', '/bricks/v1/query_result' );

		if ( null !== $base ) {
			$request->set_param( 'baseUrl', $base );
		}

		AddToCartUrl::capture( null, null, $request );
	}

	public function test_the_rest_route_is_replaced_by_the_page_the_list_is_on() {
		/*
		 * The reported bug, exactly: a button rendered inside Bricks' query
		 * endpoint pointed at /wp-json/bricks/v1/query_result?add-to-cart=N,
		 * which answers a JSON 404 instead of adding anything.
		 */
		$this->report( 'https://' . Hosts::b2b() . QuickOrderPagePathShim::ordering_path() );

		$this->assertSame(
			home_url( QuickOrderPagePathShim::ordering_path() . '?add-to-cart=172371' ),
			AddToCartUrl::repair( '/wp-json/bricks/v1/query_result?add-to-cart=172371' )
		);
	}

	public function test_quantity_and_variation_survive_and_route_parameters_do_not() {
		$this->report( 'https://' . Hosts::b2b() . QuickOrderPagePathShim::ordering_path() );

		$out = AddToCartUrl::repair(
			'/wp-json/bricks/v1/query_result?queryElementId=55c3c9&nonce=abc123&add-to-cart=5&quantity=12&variation_id=9'
		);

		$this->assertStringContainsString( 'add-to-cart=5', $out );
		$this->assertStringContainsString( 'quantity=12', $out );
		$this->assertStringContainsString( 'variation_id=9', $out );
		$this->assertStringNotContainsString( 'queryElementId', $out );
		$this->assertStringNotContainsString( 'nonce', $out );
	}

	public function test_a_reported_page_on_another_host_contributes_only_its_path() {
		// baseUrl arrives in a request body, so it is a value a client chose.
		$this->report( 'https://evil.example.net' . QuickOrderPagePathShim::ordering_path() );

		$out = AddToCartUrl::repair( '/wp-json/bricks/v1/query_result?add-to-cart=7' );

		$this->assertStringNotContainsString( 'evil.example.net', $out );
		$this->assertSame( home_url( QuickOrderPagePathShim::ordering_path() . '?add-to-cart=7' ), $out );
	}

	public function test_a_reported_page_that_is_itself_a_rest_path_falls_back_to_the_list() {
		$this->report( 'https://' . Hosts::b2b() . '/wp-json/bricks/v1/query_result' );

		$this->assertSame(
			home_url( QuickOrderPagePathShim::ordering_path() . '?add-to-cart=7' ),
			AddToCartUrl::repair( '/wp-json/bricks/v1/query_result?add-to-cart=7' )
		);
	}

	public function test_with_no_reported_page_it_falls_back_to_the_ordering_list() {
		$this->assertSame(
			home_url( QuickOrderPagePathShim::ordering_path() . '?add-to-cart=7' ),
			AddToCartUrl::repair( '/wp-json/bricks/v1/query_result?add-to-cart=7' )
		);
	}

	public function test_a_link_that_adds_nothing_is_left_alone() {
		$this->report( 'https://' . Hosts::b2b() . QuickOrderPagePathShim::ordering_path() );
		$url = 'https://' . Hosts::b2b() . '/produto/alguma-coisa/';

		$this->assertSame( $url, AddToCartUrl::repair( $url ) );
	}

	public function test_a_plain_permalink_is_left_alone() {
		$url = 'https://' . Hosts::b2b() . '/produto/alguma-coisa/';

		$this->assertSame( $url, AddToCartUrl::repair( $url ) );
	}

	public function test_retail_is_never_touched() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$url                  = '/wp-json/bricks/v1/query_result?add-to-cart=172371';

		$this->assertSame( $url, AddToCartUrl::repair( $url ) );
	}

	public function test_a_page_view_keeps_woocommerce_own_answer() {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$url = QuickOrderPagePathShim::ordering_path() . '?add-to-cart=172371';

		$this->assertSame( $url, AddToCartUrl::repair( $url ) );
	}

	public function test_a_non_string_is_returned_unchanged() {
		$this->assertNull( AddToCartUrl::repair( null ) );
		$this->assertFalse( AddToCartUrl::repair( false ) );
	}
}
