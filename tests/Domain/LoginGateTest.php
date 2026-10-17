<?php

use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Support\Hosts;

class LoginGateTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_logged_out_b2b_request_is_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( LoginGate::should_gate( '/loja/' ) );
	}

	public function test_logged_in_b2b_request_is_not_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		wp_set_current_user( $this->factory->user->create() );

		$this->assertFalse( LoginGate::should_gate( '/loja/' ) );
	}

	public function test_retail_host_is_never_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( LoginGate::should_gate( '/loja/' ) );
	}

	public function test_search_is_gated_on_b2b() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( LoginGate::should_gate( '/' ) );
	}

	/**
	 * @dataProvider allowlisted_paths
	 */
	public function test_allowlisted_paths_are_reachable_logged_out( string $path ) {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( $path ), $path . ' should be reachable' );
	}

	public function test_early_gate_blocks_a_logged_out_b2b_request() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( LoginGate::should_gate_early( '/loja/', false ) );
	}

	public function test_early_gate_lets_a_cookie_bearing_request_through() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		// Permissive by design: the authoritative gate re-checks properly.
		$this->assertFalse( LoginGate::should_gate_early( '/loja/', true ) );
	}

	public function test_early_gate_never_touches_the_retail_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( LoginGate::should_gate_early( '/loja/', false ) );
	}

	public function test_early_gate_respects_the_allowlist() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate_early( '/wp-login.php', false ) );
	}

	public static function allowlisted_paths(): array {
		return array(
			array( LoginGate::login_path() ),
			array( '/wp-login.php' ),
			array( '/wp-admin/' ),
			array( '/wp-admin/admin-ajax.php' ),
			array( '/a-minha-conta/recuperar-palavra-passe/' ),
			array( '/conta/recuperar-palavra-passe/' ),
			// The account area's old home. Still allowlisted so reset links
			// already in inboxes reach the 301 that forwards them.
			array( '/conta-b2b/recuperar-palavra-passe/' ),
			array( '/robots.txt' ),
			array( '/favicon.ico' ),
		);
	}

	/**
	 * The login page must never gate itself.
	 *
	 * Without this the gate redirects to a path it also blocks, and the
	 * browser follows the loop until it gives up. Cheap to assert, and the
	 * symptom is total: nobody can log in on the B2B host at all.
	 */
	public function test_the_login_page_is_not_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( LoginGate::login_path() ) );
		$this->assertFalse( LoginGate::should_gate_early( LoginGate::login_path(), false ) );
	}

	/**
	 * The allowlisted recovery path must be the one WooCommerce actually links to.
	 *
	 * This is the regression test for a bug the previous version of this file
	 * encoded rather than caught: the allowlist read '/minha-conta/perdeu-password/'
	 * and so did the data provider above, so both agreed with each other and
	 * neither agreed with the site. The real page is '/a-minha-conta/' with the
	 * 'lost-password' endpoint, so a logged-out B2B customer who clicked the
	 * reset link was redirected back to the login page, forever.
	 *
	 * Deriving the path from WooCommerce here means a slug change breaks this
	 * test instead of breaking password recovery silently.
	 */
	public function test_the_real_woocommerce_lost_password_url_is_reachable() {
		if ( ! function_exists( 'wc_lostpassword_url' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}

		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$path = wp_parse_url( (string) wc_lostpassword_url(), PHP_URL_PATH );

		if ( empty( $path ) || '/' === $path ) {
			// The test database has no My Account page configured, so there is
			// no URL to check against. Real installs do; this assertion is for
			// them. The literal-path assertion below always runs.
			$this->markTestSkipped( 'No WooCommerce My Account page in this fixture.' );
		}

		$this->assertFalse(
			LoginGate::should_gate( $path ),
			$path . ' is the URL WooCommerce links to and must not be gated.'
		);
	}

	/**
	 * The path that never existed must not be in the allowlist any more.
	 *
	 * Runs everywhere, including a fixture with no WooCommerce pages, so the
	 * old value cannot quietly come back if someone restores it from an older
	 * copy of this file.
	 */
	public function test_the_nonexistent_lost_password_path_is_not_allowlisted() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue(
			LoginGate::should_gate( '/minha-conta/perdeu-password/' ),
			'This path does not exist on this install and must not be allowlisted.'
		);
	}

	/** The login and application pages themselves, nothing beneath them. */
	public function test_paths_under_the_login_page_are_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( '/entrar/' ) );
		$this->assertFalse( LoginGate::should_gate( '/entrar' ) );
		$this->assertFalse( LoginGate::should_gate( '/registo/' ) );
		$this->assertTrue( LoginGate::should_gate( '/entrar/x/' ) );
		$this->assertTrue( LoginGate::should_gate( '/registo/qualquer/' ) );
		$this->assertTrue( LoginGate::should_gate( '/entrar/../loja/' ) );
	}

	/**
	 * The gate runs on wp_loaded, before WooCommerce's ?wc-ajax= handler
	 * (template_redirect 0) and its form handlers (wp_loaded) answer.
	 */
	public function test_the_gate_runs_before_woocommerce_answers() {
		$this->assertSame( 0, has_action( 'wp_loaded', array( LoginGate::class, 'maybe_redirect_loaded' ) ) );
		$this->assertGreaterThan( 0, (int) has_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ) ) );
	}

	public function test_wc_ajax_is_gated_logged_out() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( LoginGate::should_gate( '/', array( 'wc-ajax' => 'get_refreshed_fragments' ) ) );
		$this->assertTrue( LoginGate::should_gate( '/entrar/', array( 'wc-ajax' => 'checkout' ) ) );
	}

	/** Query vars and WooCommerce form fields in a POST body count like the URL's. */
	public function test_a_post_body_naming_other_content_is_gated() {
		$_SERVER['HTTP_HOST']      = Hosts::b2b();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/entrar/';

		foreach ( array( array( 'pagename' => 'b2b-loja' ), array( 'woocommerce_checkout_place_order' => '1' ), array( 'add-to-cart' => '12' ) ) as $body ) {
			$_POST = $body;

			$gate = new ReflectionMethod( LoginGate::class, 'request_vars' );
			$gate->setAccessible( true );

			$this->assertTrue( LoginGate::should_gate( '/entrar/', $gate->invoke( null, '/entrar/' ) ), wp_json_encode( $body ) );
		}

		// The lost-password form's own fields still reach its page.
		$_POST = array( 'user_login' => 'x', 'wc_reset_password' => 'true' );
		$this->assertFalse( LoginGate::should_gate( '/conta/recuperar-palavra-passe/', $gate->invoke( null, '/conta/recuperar-palavra-passe/' ) ) );

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_add_to_cart_on_the_login_page_is_gated() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( LoginGate::should_gate( '/entrar/', array( 'add-to-cart' => '12' ) ) );
	}

	/** Logged out on the trade host, admin-ajax answers the login and application forms only. */
	public function test_logged_out_admin_ajax_allows_only_the_forms() {
		$ajax = '/wp-admin/admin-ajax.php';

		$this->assertTrue( LoginGate::admin_endpoint_allowed( $ajax, 'bricks_form_submit', array() ) );
		$this->assertTrue( LoginGate::admin_endpoint_allowed( $ajax, 'bricks_regenerate_form_nonce', array() ) );
		$this->assertFalse( LoginGate::admin_endpoint_allowed( $ajax, 'woocommerce_checkout', array() ) );
		$this->assertFalse( LoginGate::admin_endpoint_allowed( $ajax, 'woocommerce_add_to_cart', array() ) );
		$this->assertFalse( LoginGate::admin_endpoint_allowed( $ajax, 'bricks_form_submit', array( 'add-to-cart' => '12' ) ) );
		$this->assertFalse( LoginGate::admin_endpoint_allowed( '/wp-admin/admin-post.php', 'bricks_form_submit', array() ) );
	}

	public function test_the_admin_endpoint_check_runs_before_woocommerces_form_handler() {
		$this->assertSame( 0, has_action( 'wp_loaded', array( LoginGate::class, 'maybe_redirect_loaded' ) ) );
	}
}
