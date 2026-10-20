<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Support\Hosts;

class HostRoutesTest extends WP_UnitTestCase {

	use TradeHosts;

	/** @var callable[] page_link filters added by spanish_copy(). */
	private array $links = array();

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = Hosts::retail();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		remove_all_filters( 'beltoft_commerce_default_language_post' );
		$this->forget_trade_hosts();
		delete_option( \BeltoftCommerce\Frontend\HostPages::OPTION );
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

	/** D3: the language prefix survives the move to the trade host, to the copy's own root (spec §5). */
	public function test_a_translated_trade_copy_keeps_its_language_on_the_trade_host() {
		$shop = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja', 'post_status' => 'publish' ) );
		update_option( \BeltoftCommerce\Frontend\HostPages::OPTION, array( 'loja' => $shop ) );
		$copy = $this->spanish_copy( $shop, 'b2b-tienda' );
		$this->use_path_mode();
		$this->translation( $shop, $copy, 'es' );

		$this->go_to( home_url( '/?page_id=' . $copy ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/es/tienda/', HostRoutes::resolved_redirect_target() );

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

	/* One trade host per language (spec 2026-10-20, §4). */

	public function test_a_trade_path_on_the_site_address_goes_to_the_default_trade_host() {
		$this->assertSame( 'https://' . Hosts::b2b() . '/entrar/?redirect_to=%2Fconta%2F', HostRoutes::early_target( '/entrar/?redirect_to=%2Fconta%2F', Hosts::retail() ) );

		$this->use_trade_hosts();
		$this->assertSame( 'https://' . Hosts::b2b() . '/entrar/', HostRoutes::early_target( '/entrar/', Hosts::retail() ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/b2b-loja/', HostRoutes::early_target( '/b2b-loja/', Hosts::retail() ) );
		$this->assertSame( '', HostRoutes::early_target( '/loja/', Hosts::retail() ) );
	}

	public function test_a_trade_path_on_a_language_domain_goes_to_that_languages_trade_host() {
		$this->use_trade_hosts();

		// The early handler cannot know a language domain's language: it leaves it to plugins_loaded:2.
		$this->assertSame( '', HostRoutes::early_target( '/sign-in/', self::$en_retail ) );

		$this->assertSame( 'https://' . self::$en_trade . '/sign-in/?redirect_to=x', HostRoutes::language_target( '/sign-in/?redirect_to=x', self::$en_retail, 'en' ) );
		$this->assertSame( 'https://' . self::$en_trade . '/b2b-shop/', HostRoutes::language_target( '/b2b-shop/', self::$en_retail, 'en' ) );
		$this->assertSame( 'https://' . self::$en_trade . '/quick-order/', HostRoutes::language_target( '/quick-order/', self::$en_retail, 'en' ) );
		$this->assertSame( '', HostRoutes::language_target( '/shop/', self::$en_retail, 'en' ) );

		// The retail header's /entrar/ link works on every language domain.
		$this->assertSame( 'https://' . self::$en_trade . '/entrar/', HostRoutes::language_target( '/entrar/', self::$en_retail, 'en' ) );
	}

	public function test_a_language_without_a_trade_host_goes_to_the_portal() {
		$this->use_trade_hosts();

		$this->assertSame( 'https://' . self::$en_trade . '/entrar/', HostRoutes::language_target( '/entrar/', self::$de_retail, 'de' ) );
		$this->assertSame( 'https://' . self::$en_trade . '/sign-in/', HostRoutes::language_target( '/sign-in/', self::$de_retail, 'de' ) );
	}

	/** Path mode (staging): a prefixed trade path goes to the trade host with its prefix. */
	public function test_a_prefixed_trade_path_on_retail_keeps_its_prefix() {
		$this->use_path_mode();

		$this->assertSame( 'https://' . Hosts::b2b() . '/es/acceder/', HostRoutes::early_target( '/es/acceder/', Hosts::retail() ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/es/acceder/', HostRoutes::language_target( '/es/acceder/', Hosts::retail(), 'es' ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/es/b2b-tienda/', HostRoutes::language_target( '/es/b2b-tienda/', Hosts::retail(), 'es' ) );
		$this->assertSame( '', HostRoutes::language_target( '/es/tienda/', Hosts::retail(), 'es' ) );
	}

	public function test_a_translated_trade_page_on_retail_goes_to_its_language_root() {
		$this->use_trade_hosts();
		$shop = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja', 'post_status' => 'publish' ) );
		$copy = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-shop', 'post_status' => 'publish' ) );
		update_option( \BeltoftCommerce\Frontend\HostPages::OPTION, array( 'loja' => $shop ) );
		$this->translation( $shop, $copy, 'en' );
		\BeltoftCommerce\Domain\TradePages::forget();

		$this->go_to( home_url( '/?page_id=' . $copy ) );
		$this->assertSame( 'https://' . self::$en_trade . '/shop/', HostRoutes::resolved_redirect_target() );

		$this->go_to( home_url( '/?page_id=' . $shop ) );
		$this->assertSame( 'https://' . Hosts::b2b() . '/loja/', HostRoutes::resolved_redirect_target() );
	}

	/** D2: a copy whose slug carries no b2b- prefix and no trade path is a trade page by identity. */
	public function test_an_unprefixed_translated_copy_is_still_a_trade_page() {
		$this->use_trade_hosts();
		$checkout = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-finalizar', 'post_status' => 'publish' ) );
		$copy     = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'finalizar-compra-socios', 'post_status' => 'publish' ) );
		update_option( \BeltoftCommerce\Checkout\B2BCheckoutPage::OPTION, $checkout );
		$this->translation( $checkout, $copy, 'en' );
		\BeltoftCommerce\Domain\TradePages::forget();

		$this->go_to( home_url( '/?page_id=' . $copy ) );
		$target = HostRoutes::resolved_redirect_target();

		$this->assertStringStartsWith( 'https://' . self::$en_trade . '/', $target );
		$this->assertStringContainsString( 'finalizar-compra-socios', $target );

		delete_option( \BeltoftCommerce\Checkout\B2BCheckoutPage::OPTION );
	}

	public function test_trade_hosts_never_redirect_to_each_other() {
		$this->use_trade_hosts();
		$login = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'entrar', 'post_status' => 'publish' ) );

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			$this->assertSame( '', HostRoutes::early_target( '/entrar/', $host ), $host );
			$this->assertSame( '', HostRoutes::early_target( '/sign-in/', $host ), $host );
			$this->assertSame( '', HostRoutes::language_target( '/entrar/', $host, 'pt' ), $host );
			$this->assertSame( '', HostRoutes::language_target( '/sign-in/', $host, 'en' ), $host );
			$this->assertFalse( HostRoutes::should_redirect( '/b2b-loja/' ), $host );

			$this->go_to( 'http://' . $host . '/?page_id=' . $login );
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertSame( '', HostRoutes::resolved_redirect_target(), $host );
		}
	}

	/** The early handler registers the language-aware redirect just after the language plugin has decided. */
	public function test_the_language_redirect_runs_after_the_language_plugin() {
		$this->assertSame( 2, has_action( 'plugins_loaded', array( HostRoutes::class, 'maybe_redirect_on_language' ) ) );
	}
}
