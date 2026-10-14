<?php

use BeltoftCommerce\Frontend\HostPages;
use BeltoftCommerce\Frontend\OrderingPage;
use BeltoftCommerce\Support\Hosts;

class HostPagesTest extends WP_UnitTestCase {

	private int $loja   = 0;
	private int $termos = 0;

	public function setUp(): void {
		parent::setUp();
		$this->loja   = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja' ) );
		$this->termos = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-termos-condicoes' ) );
		update_option( HostPages::OPTION, array( 'loja' => $this->loja, 'termos-condicoes' => $this->termos ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
	}

	public function tearDown(): void {
		delete_option( HostPages::OPTION );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
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
}
