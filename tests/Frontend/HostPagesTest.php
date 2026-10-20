<?php

use BeltoftCommerce\Frontend\HostPages;
use BeltoftCommerce\Frontend\OrderingPage;
use BeltoftCommerce\Support\Hosts;

class HostPagesTest extends WP_UnitTestCase {

	use TradeHosts;

	private int $loja   = 0;
	private int $termos = 0;

	/** REQUEST_URI as the test found it; later tests (the Store API's) read it. */
	private ?string $request_uri = null;

	public function setUp(): void {
		parent::setUp();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null;
		$this->loja   = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja' ) );
		$this->termos = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-termos-condicoes' ) );
		update_option( HostPages::OPTION, array( 'loja' => $this->loja, 'termos-condicoes' => $this->termos ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
	}

	public function tearDown(): void {
		delete_option( HostPages::OPTION );
		$this->forget_trade_hosts();
		unset( $_SERVER['HTTP_HOST'] );

		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		parent::tearDown();
	}

	public function test_an_unpublished_page_drops_out_of_the_map() {
		wp_update_post( array( 'ID' => $this->termos, 'post_status' => 'draft' ) );

		$this->assertSame( array( 'loja' => $this->loja ), HostPages::map() );
		$this->assertSame( 0, HostPages::id( 'termos-condicoes' ) );
	}

	public function test_the_ordering_page_is_the_loja_entry() {
		$this->assertSame( $this->loja, OrderingPage::id() );
	}

	/**
	 * THE ROUTING BUG THIS GUARDS AGAINST. /loja/ is the shop page on retail,
	 * and WooCommerce registers the shop slug as the product archive - so the
	 * vars that arrive for /loja/ are post_type=product, never pagename=loja.
	 * The remap has to key on the path and REPLACE the archive vars: left
	 * beside page_id they would make WP_Query look for a product with that id.
	 */
	public function test_loja_resolves_to_the_trade_page_even_when_it_arrives_as_the_product_archive() {
		$_SERVER['REQUEST_URI'] = '/loja/';

		$this->assertSame( array( 'page_id' => $this->loja ), HostPages::resolve( array( 'post_type' => 'product' ) ) );
	}

	public function test_a_mapped_page_resolves_by_its_root_slug() {
		$_SERVER['REQUEST_URI'] = '/termos-condicoes/';

		$this->assertSame( array( 'page_id' => $this->termos ), HostPages::resolve( array( 'pagename' => 'termos-condicoes' ) ) );
	}

	public function test_pagination_survives_the_remap() {
		$_SERVER['REQUEST_URI'] = '/loja/page/3/';

		// Key order is not part of the contract: incoming vars stay in place, page_id is added.
		$this->assertEqualsCanonicalizing( array( 'page_id' => $this->loja, 'paged' => 3 ), HostPages::resolve( array( 'post_type' => 'product', 'paged' => 3 ) ) );
	}

	/** Retail keeps its own pages: the remap never fires there. */
	public function test_retail_is_never_remapped() {
		$_SERVER['HTTP_HOST']   = Hosts::retail();
		$_SERVER['REQUEST_URI'] = '/loja/';
		$vars = array( 'post_type' => 'product' );

		$this->assertSame( $vars, HostPages::resolve( $vars ) );
	}

	/** Only exact mapped slugs - not an arbitrary path under one, not an unmapped page. */
	public function test_only_exact_mapped_paths_are_remapped() {
		foreach ( array( '/lojas/', '/loja/produto-x/', '/b2b-loja/', '/contacto/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$vars = array( 'pagename' => 'whatever' );
			$this->assertSame( $vars, HostPages::resolve( $vars ), $uri );
		}
	}

	/**
	 * Without this, redirect_canonical() would send the root path to the
	 * page's physical b2b- slug, which is internal and never public.
	 */
	public function test_a_mapped_pages_permalink_is_its_root_path_on_the_trade_host() {
		$this->assertSame( home_url( '/loja/' ), HostPages::permalink( 'http://x/b2b-loja/', $this->loja ) );
		$this->assertSame( home_url( '/termos-condicoes/' ), HostPages::permalink( 'http://x/b2b-termos-condicoes/', $this->termos ) );
	}

	public function test_other_pages_keep_their_permalink() {
		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'http://x/other/', HostPages::permalink( 'http://x/other/', $other ) );
	}

	public function test_the_permalink_is_untouched_on_retail() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertSame( 'http://x/b2b-loja/', HostPages::permalink( 'http://x/b2b-loja/', $this->loja ) );
	}

	public function test_a_mapped_pages_preview_link_uses_the_canonical_admin_host() {
		$url = 'https://' . Hosts::b2b() . '/loja/?preview=true&preview_nonce=abc123#preview';

		$this->assertSame(
			'https://' . Hosts::retail() . '/?page_id=' . $this->loja . '&preview=true&preview_nonce=abc123#preview',
			HostPages::preview_link( $url, get_post( $this->loja ) )
		);
	}

	public function test_an_unmapped_pages_preview_link_is_untouched() {
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'ordinary-page' ) );
		$url   = 'https://' . Hosts::retail() . '/ordinary-page/?preview=true';

		$this->assertSame( $url, HostPages::preview_link( $url, get_post( $other ) ) );
	}

	public function test_a_mapped_pages_bricks_link_moves_from_b2b_to_the_canonical_admin_host() {
		$url = 'https://' . Hosts::b2b() . '/loja/?bricks=run&brickspreview=true#builder';

		$this->assertSame(
			'https://' . Hosts::retail() . '/b2b-loja/?bricks=run&brickspreview=true#builder',
			HostPages::builder_edit_link( $url, $this->loja )
		);
	}

	public function test_a_mapped_pages_bricks_link_stays_on_the_canonical_admin_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$url = 'https://' . Hosts::retail() . '/b2b-loja/?bricks=run';

		$this->assertSame( $url, HostPages::builder_edit_link( $url, $this->loja ) );
	}

	public function test_an_unmapped_pages_bricks_link_is_untouched() {
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'ordinary-page' ) );
		$url   = 'https://' . Hosts::b2b() . '/ordinary-page/?bricks=run';

		$this->assertSame( $url, HostPages::builder_edit_link( $url, $other ) );
	}

	/**
	 * THE CHECKOUT CASE. Thank-you and pay pages are WooCommerce endpoints
	 * under the checkout page. The resolver has to remap them to the trade
	 * post AND keep the endpoint var WooCommerce parsed, or the trade host
	 * shows retail's checkout post for a trade order's thank-you page.
	 */
	public function test_a_woocommerce_endpoint_under_a_mapped_page_is_remapped_with_its_var_kept() {
		if ( ! function_exists( 'WC' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		$slug = WC()->query->get_query_vars()['order-received'];
		$_SERVER['REQUEST_URI'] = '/termos-condicoes/' . $slug . '/123/';

		$out = HostPages::resolve( array( 'pagename' => 'termos-condicoes', 'order-received' => '123' ) );

		$this->assertSame( $this->termos, $out['page_id'] );
		$this->assertSame( '123', $out['order-received'] );
		$this->assertArrayNotHasKey( 'pagename', $out );
	}

	/* Translated trade pages, per trade host (spec 2026-10-20, §5). */

	private function copy_of( int $anchor, string $slug, string $code ): int {
		$copy = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => $slug ) );
		$this->translation( $anchor, $copy, $code );

		return $copy;
	}

	/** A path-mode language plugin's url_in(): the prefix after the host. */
	private function prefixing_urls(): void {
		$this->seam( 'beltoft_commerce_language_url_in', static function ( $out, $url, $code ) {
			return 'pt' === $code ? $url : preg_replace( '#^(https?://[^/]+)/#', '$1/' . $code . '/', $url );
		}, 3 );
	}

	public function test_a_root_path_resolves_to_the_requests_language_copy() {
		$this->use_trade_hosts();
		$shop = $this->copy_of( $this->loja, 'b2b-shop', 'en' );
		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->in_language( 'en' );

		$_SERVER['REQUEST_URI'] = '/shop/';
		$this->assertSame( array( 'page_id' => $shop ), HostPages::resolve( array( 'pagename' => 'shop' ) ) );

		$_SERVER['REQUEST_URI'] = '/shop/page/2/';
		$this->assertEqualsCanonicalizing( array( 'page_id' => $shop, 'paged' => 2 ), HostPages::resolve( array( 'pagename' => 'shop', 'paged' => 2 ) ) );

		// No Portuguese fallback: a root with no English copy is not found (Zerolang §5).
		$_SERVER['REQUEST_URI'] = '/termos-condicoes/';
		$this->assertSame( array( 'error' => '404' ), HostPages::resolve( array( 'pagename' => 'termos-condicoes' ) ) );
		// /loja/ has an English copy: it is redirected (test_a_default_root_in_another_language_goes_to_that_languages_copy).

		// The Portuguese trade host is unchanged.
		$this->forget_trade_hosts();
		$this->use_trade_hosts();
		$this->translation( $this->loja, $shop, 'en' );
		$_SERVER['HTTP_HOST']   = Hosts::b2b();
		$_SERVER['REQUEST_URI'] = '/loja/';
		$this->assertSame( array( 'page_id' => $this->loja ), HostPages::resolve( array( 'post_type' => 'product' ) ) );
	}

	public function test_a_prefixed_root_resolves_in_path_mode() {
		$this->use_path_mode();
		$tienda = $this->copy_of( $this->loja, 'b2b-tienda', 'es' );

		$_SERVER['REQUEST_URI'] = '/es/tienda/';
		$this->assertSame( array( 'page_id' => $tienda ), HostPages::resolve( array( 'pagename' => 'tienda' ) ) );

		$_SERVER['REQUEST_URI'] = '/es/tienda/page/3/';
		$this->assertEqualsCanonicalizing( array( 'page_id' => $tienda, 'paged' => 3 ), HostPages::resolve( array( 'pagename' => 'tienda', 'paged' => 3 ) ) );

		// The Portuguese root under the Spanish prefix with no Spanish copy is not the Portuguese page.
		$_SERVER['REQUEST_URI'] = '/es/termos-condicoes/';
		$this->assertSame( array( 'error' => '404' ), HostPages::resolve( array( 'pagename' => 'termos-condicoes' ) ) );

		$_SERVER['REQUEST_URI'] = '/loja/';
		$this->assertSame( array( 'page_id' => $this->loja ), HostPages::resolve( array( 'post_type' => 'product' ) ) );
	}

	public function test_a_copys_permalink_is_its_root_in_its_language() {
		$this->use_path_mode();
		$this->prefixing_urls();
		$tienda = $this->copy_of( $this->loja, 'b2b-tienda', 'es' );

		$this->assertSame( 'tienda', HostPages::root_for( $tienda ) );
		$this->assertSame( 'loja', HostPages::root_for( $this->loja ) );
		$this->assertSame( home_url( '/es/tienda/' ), HostPages::permalink( 'http://x/es/b2b-tienda/', $tienda ) );
		$this->assertSame( home_url( '/loja/' ), HostPages::permalink( 'http://x/b2b-loja/', $this->loja ) );
		$this->assertSame( array( 'tienda' => $tienda ), HostPages::map_in( 'es' ) );
	}

	public function test_a_copy_without_the_prefix_is_not_served_at_root() {
		$this->use_path_mode();
		$copy = $this->copy_of( $this->termos, 'terminos-socios', 'es' );

		$this->assertSame( '', HostPages::root_for( $copy ) );
		$this->assertSame( array(), HostPages::map_in( 'es' ) );
		$this->assertSame( 'http://x/es/terminos-socios/', HostPages::permalink( 'http://x/es/terminos-socios/', $copy ) );

		$_SERVER['REQUEST_URI'] = '/es/terminos-socios/';
		$this->assertSame( array( 'pagename' => 'terminos-socios' ), HostPages::resolve( array( 'pagename' => 'terminos-socios' ) ) );
	}

	public function test_the_ordering_page_is_recognised_in_every_language() {
		$this->use_trade_hosts();
		$shop = $this->copy_of( $this->loja, 'b2b-shop', 'en' );

		$this->assertSame( $this->loja, OrderingPage::id() );
		$this->assertSame( '/loja/', OrderingPage::path() );

		$this->in_language( 'en' );
		$this->assertSame( $shop, OrderingPage::id() );
		$this->assertSame( '/shop/', OrderingPage::path() );

		$this->go_to( home_url( '/?page_id=' . $shop ) );
		$this->assertTrue( OrderingPage::is_ordering_page() );

		// A language without a copy has no ordering page.
		$this->forget_trade_hosts();
		$this->use_trade_hosts();
		$this->in_language( 'de' );
		$this->assertSame( 0, OrderingPage::id() );
	}

	/** A default-language root asked for in another language goes to that language's copy, when there is one. */
	public function test_a_default_root_in_another_language_goes_to_that_languages_copy() {
		$this->use_path_mode();
		$tienda = $this->copy_of( $this->loja, 'b2b-tienda', 'es' );

		$this->assertSame( home_url( '/es/tienda/' ), HostPages::language_root_target( '/es/loja/' ) );
		$this->assertSame( home_url( '/es/tienda/page/2/' ) . '?orderby=price', HostPages::language_root_target( '/es/loja/page/2/?orderby=price' ) );
		// No Spanish copy: no target, and resolve() answers 404.
		$this->assertSame( '', HostPages::language_root_target( '/es/termos-condicoes/' ) );
		// The default language, and the copy's own root, are not redirected.
		$this->assertSame( '', HostPages::language_root_target( '/loja/' ) );
		$this->assertSame( '', HostPages::language_root_target( '/es/tienda/' ) );

		$this->forget_trade_hosts();
		$this->use_trade_hosts();
		$shop = $this->copy_of( $this->loja, 'b2b-shop', 'en' );
		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertSame( 'http://' . self::$en_trade . '/shop/', HostPages::language_root_target( '/loja/' ) );
		$this->assertSame( '', HostPages::language_root_target( '/termos-condicoes/' ) );
	}

	/** Only pagination and endpoint tails move with the root; any other tail (a product under /loja/) is the language plugin's. */
	public function test_a_default_root_with_another_tail_is_not_redirected() {
		$this->use_trade_hosts();
		$this->copy_of( $this->loja, 'b2b-shop', 'en' );
		$_SERVER['HTTP_HOST'] = self::$en_trade;

		$this->assertSame( '', HostPages::language_root_target( '/loja/vaso-algodao-1/' ) );
		$this->assertSame( 'http://' . self::$en_trade . '/shop/page/2/', HostPages::language_root_target( '/loja/page/2/' ) );

		$_SERVER['REQUEST_URI'] = '/loja/vaso-algodao-1/';
		$vars = array( 'product' => 'vaso-algodao-1' );
		$this->assertSame( $vars, HostPages::resolve( $vars ) );
	}
}
