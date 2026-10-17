<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Support\Hosts;

/**
 * The trade host shows logged-out visitors its login page and nothing else,
 * and trade pages never render on the retail host - whatever the request
 * looks like on the way in. These are the ways in that got past the path
 * checks, each confirmed against staging.
 */
class TradeBoundaryTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// --- Login gate: query strings on allowed paths -----------------------

	/** /entrar/?pagename=b2b-loja rendered the trade ordering page logged out. */
	public function test_an_allowed_path_that_selects_other_content_is_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		foreach ( array( 'pagename' => 'b2b-loja', 'page_id' => '5', 'p' => '5', 'post_type' => 'product', 's' => 'x' ) as $var => $value ) {
			$this->assertTrue( LoginGate::should_gate( LoginGate::login_path(), array( $var => $value ) ), $var );
			$this->assertTrue( LoginGate::should_gate_early( LoginGate::login_path(), false, array( $var => $value ) ), $var );
		}
	}

	/** The login and reset flows carry their own parameters, which must still work. */
	public function test_the_login_and_reset_parameters_still_pass() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( LoginGate::login_path(), array( 'redirect_to' => '/loja/', 'loggedout' => 'true' ) ) );
		$this->assertFalse( LoginGate::should_gate( '/conta/recuperar-palavra-passe/', array( 'key' => 'abc', 'login' => 'x@y.pt' ) ) );
	}

	// --- Login gate: REST through ?rest_route= ----------------------------

	/** /entrar/?rest_route=/wc/store/v1/products listed the catalogue logged out. */
	public function test_rest_route_is_judged_as_the_rest_path_it_is() {
		$this->assertSame( '/wp-json/wc/store/v1/products', LoginGate::effective_path( '/entrar/?rest_route=/wc/store/v1/products' ) );
		$this->assertSame( '/wp-json/beltoft-scanner/v1/lookup', LoginGate::effective_path( '/?rest_route=%2Fbeltoft-scanner%2Fv1%2Flookup' ) );
		$this->assertSame( '/entrar/', LoginGate::effective_path( '/entrar/?redirect_to=%2Floja%2F' ) );
	}

	public function test_rest_through_an_allowed_page_is_gated_early() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$path = LoginGate::effective_path( '/entrar/?rest_route=/wc/store/v1/products' );

		$this->assertTrue( LoginGate::should_gate_early( $path, false ) );
	}

	// --- REST gate ---------------------------------------------------------

	private function dispatch_guard( string $route ) {
		return LoginGate::guard_rest( null, rest_get_server(), new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Any cookie named wordpress_logged_in_* got a request past the early gate,
	 * and REST never meets the page gate. Only a valid login cookie counts.
	 */
	public function test_rest_on_the_trade_host_needs_a_real_login() {
		$_SERVER['HTTP_HOST']        = Hosts::b2b();
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'forged|0|x|y';

		$result = $this->dispatch_guard( '/wc/store/v1/products' );

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_a_signed_in_partner_keeps_rest_even_without_a_rest_nonce() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$user                 = self::factory()->user->create( array( 'role' => 'customer' ) );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'logged_in' );

		$this->assertNull( $this->dispatch_guard( '/wc/store/v1/products' ) );
	}

	/** The scanner answers 401 itself, so the phone can hold its scans. */
	public function test_the_scanner_namespace_is_left_to_answer_for_itself() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertNull( $this->dispatch_guard( '/beltoft-scanner/v1/lookup' ) );
	}

	/**
	 * Only the scanner's endpoints are exempt. A prefix without the trailing
	 * slash also let through /beltoft-scanner/v10... and the bare namespace,
	 * whose route index lists every endpoint to a signed-out visitor.
	 */
	public function test_only_the_scanners_endpoints_are_exempt() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		foreach ( array( '/beltoft-scanner/v1', '/beltoft-scanner/v10/lookup', '/beltoft-scanner/v1x' ) as $route ) {
			$this->assertWPError( $this->dispatch_guard( $route ), $route );
		}
	}

	/**
	 * The scanner's namespace is beltoft-scanner/v1 since its 2.0; a logged-out
	 * phone must reach it to get the 401 it expects, not the login page.
	 */
	public function test_the_scanner_namespace_is_reachable_logged_out_through_the_gate() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate_early( '/wp-json/beltoft-scanner/v1/lookup', false ) );
		$this->assertTrue( LoginGate::should_gate_early( '/wp-json/b2b/v1/lookup', false ), 'The old namespace is gone; nothing answers it.' );
	}

	public function test_retail_rest_is_untouched() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertNull( $this->dispatch_guard( '/wc/store/v1/products' ) );
	}

	// --- Retail host: trade pages by another spelling ------------------------

	/** /B2B-loja/ and /%62%32b-loja/ rendered the trade page on retail and were page-cached. */
	public function test_case_and_encoding_do_not_hide_a_trade_path() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertTrue( HostRoutes::should_redirect( '/B2B-loja/' ) );
		$this->assertTrue( HostRoutes::should_redirect( '/%62%32b-loja/' ) );
		$this->assertTrue( HostRoutes::should_redirect( '/Conta/' ) );
		$this->assertTrue( HostRoutes::should_redirect( '/Encomenda-rapida/' ) );
	}

	/**
	 * Whatever the URL, what WordPress resolved decides: ?pagename=, ?page_id=
	 * and any other spelling that lands on a trade page leaves the retail host.
	 */
	public function test_a_trade_page_resolved_on_retail_is_sent_to_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$page                 = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja-teste', 'post_status' => 'publish' ) );

		$this->go_to( home_url( '/?page_id=' . $page ) );

		$this->assertSame( 'https://' . Hosts::b2b() . '/b2b-loja-teste/', HostRoutes::resolved_redirect_target() );
	}

	public function test_an_ordinary_retail_page_stays() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$page                 = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'sobre-nos', 'post_status' => 'publish' ) );

		$this->go_to( home_url( '/?page_id=' . $page ) );

		$this->assertSame( '', HostRoutes::resolved_redirect_target() );
	}

	public function test_the_trade_host_is_never_sent_to_itself() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$page                 = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja-teste', 'post_status' => 'publish' ) );

		$this->go_to( home_url( '/?page_id=' . $page ) );

		$this->assertSame( '', HostRoutes::resolved_redirect_target() );
	}
}
