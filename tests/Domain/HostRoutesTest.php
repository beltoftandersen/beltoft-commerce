<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Support\Hosts;

class HostRoutesTest extends WP_UnitTestCase {

	/** @var callable[] page_link filters added by spanish_copy(). */
	private array $links = array();

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = Hosts::retail();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		remove_all_filters( 'beltoft_commerce_default_language_post' );
		foreach ( $this->links as $link ) {
			remove_filter( 'page_link', $link, 10 );
		}
		\BeltoftCommerce\Domain\TradePages::forget();
		parent::tearDown();
	}

	public function test_a_b2b_path_redirects_from_retail() {
		$this->assertTrue( HostRoutes::should_redirect( '/b2b-loja/' ) );
		$this->assertTrue( HostRoutes::should_redirect( '/entrar/' ) );
	}

	public function test_a_bricks_builder_request_stays_on_the_canonical_admin_host() {
		$this->assertFalse( HostRoutes::should_redirect( '/b2b-loja/', true ) );
		$this->assertFalse( HostRoutes::should_redirect( '/entrar/', true ) );
	}

	public function test_an_ordinary_retail_path_is_untouched() {
		$this->assertFalse( HostRoutes::should_redirect( '/loja/' ) );
	}

	public function test_the_b2b_host_is_never_redirected_to_itself() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( HostRoutes::should_redirect( '/b2b-loja/' ) );
	}

	public function test_b2b_admin_and_login_requests_move_to_the_canonical_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-admin/edit.php?post_type=page&paged=2',
			HostRoutes::admin_redirect_target( '/wp-admin/edit.php?post_type=page&paged=2' )
		);
		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-login.php?action=lostpassword&source=b2b',
			HostRoutes::admin_redirect_target( '/wp-login.php?action=lostpassword&source=b2b' )
		);
		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-admin',
			HostRoutes::admin_redirect_target( '/wp-admin', 'HEAD' )
		);
	}

	public function test_b2b_frontend_wordpress_endpoints_stay_on_the_requesting_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/admin-ajax.php?action=filter' ) );
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/admin-post.php?action=saved_cart' ) );
	}

	public function test_b2b_logout_stays_on_b2b_to_clear_its_host_only_cookie() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame(
			'',
			HostRoutes::admin_redirect_target( '/wp-login.php?action=logout&redirect_to=%2Fentrar%2F&_wpnonce=abc123' )
		);
	}

	public function test_admin_redirect_does_not_move_posts_or_retail_requests() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-login.php', 'POST' ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/' ) );
	}

	/** An editor's preview of a trade page stays on the retail host, where the session is. */
	public function test_an_editors_preview_is_not_sent_to_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-preview-test', 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( home_url( '/?page_id=' . $page . '&preview=true' ) );
		$this->assertSame( '', HostRoutes::resolved_redirect_target() );

		wp_set_current_user( 0 );
		$this->go_to( home_url( '/?page_id=' . $page ) );
		$this->assertStringStartsWith( 'https://' . Hosts::b2b(), HostRoutes::resolved_redirect_target() );
	}

	/**
	 * A translated copy, as a path-mode language plugin serves it: linked to
	 * its default-language page and at a /es/ address.
	 */
	private function spanish_copy( int $anchor, string $slug ): int {
		$copy = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => $slug, 'post_status' => 'publish' ) );

		add_filter( 'beltoft_commerce_default_language_post', static fn( $id, $post_id ) => (int) $post_id === $copy ? $anchor : $id, 10, 2 );
		$this->links[] = static fn( $link, $post_id ) => (int) $post_id === $copy ? home_url( '/es/' . $slug . '/' ) : $link;
		add_filter( 'page_link', end( $this->links ), 10, 2 );
		\BeltoftCommerce\Domain\TradePages::forget();

		return $copy;
	}

	/** D2: the Spanish login page (/es/acceder/) is a trade page by identity, and never renders on retail. */
	public function test_a_translated_trade_page_with_its_own_slug_goes_to_the_trade_host() {
		$login = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'entrar', 'post_status' => 'publish' ) );
		$copy  = $this->spanish_copy( $login, 'acceder' );

		$this->go_to( home_url( '/?page_id=' . $copy ) );

		$this->assertSame( 'https://' . Hosts::b2b() . '/es/acceder/', HostRoutes::resolved_redirect_target() );
	}

	/** D3: the language prefix survives the move to the trade host. */
	public function test_a_translated_trade_copy_keeps_its_language_on_the_trade_host() {
		$shop = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja', 'post_status' => 'publish' ) );
		update_option( \BeltoftCommerce\Frontend\HostPages::OPTION, array( 'loja' => $shop ) );
		$copy = $this->spanish_copy( $shop, 'b2b-tienda' );

		$this->go_to( home_url( '/?page_id=' . $copy ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/es/b2b-tienda/', HostRoutes::resolved_redirect_target() );

		// The default-language page still goes to its root path.
		$this->go_to( home_url( '/?page_id=' . $shop ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/loja/', HostRoutes::resolved_redirect_target() );

		delete_option( \BeltoftCommerce\Frontend\HostPages::OPTION );
	}

	public function test_a_translated_retail_page_stays_on_retail() {
		$retail = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'sobre-nos', 'post_status' => 'publish' ) );
		$copy   = $this->spanish_copy( $retail, 'sobre-nosotros' );

		$this->go_to( home_url( '/?page_id=' . $copy ) );

		$this->assertSame( '', HostRoutes::resolved_redirect_target() );
	}
}
