<?php

use BeltoftCommerce\Domain\TradePages;
use BeltoftCommerce\Frontend\HostPages;
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
		remove_all_filters( 'beltoft_commerce_default_language_post' );
		delete_option( HostPages::OPTION );
		TradePages::forget();
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

	/** include= becomes post__in, which WordPress lets override post__not_in. */
	public function test_include_does_not_bring_a_trade_page_back() {
		$ids = wp_list_pluck( $this->get( '/wp/v2/pages', array( 'include' => array( $this->trade, $this->retail ) ) )->get_data(), 'id' );

		$this->assertSame( array( $this->retail ), array_map( 'intval', $ids ) );
	}

	/** A translated copy of a trade page, as a language plugin links them: the copy answers with its default-language page. */
	private function translate( int $copy, int $anchor ): void {
		add_filter( 'beltoft_commerce_default_language_post', static fn( $id, $post_id ) => (int) $post_id === $copy ? $anchor : $id, 10, 2 );
		TradePages::forget();
	}

	/** D1: the Spanish copy of the login page has its own slug (acceder), and is a trade page by its anchor. */
	public function test_a_translated_copy_of_a_trade_page_is_a_trade_page() {
		$login = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'entrar', 'post_title' => 'Entrar' ) );
		$copy  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'acceder', 'post_title' => 'Acceder' ) );
		$this->translate( $copy, $login );

		$this->assertTrue( TradePages::is_trade_page( $copy ) );
		$this->assertContains( $copy, TradePages::ids() );
		$this->assertSame( 404, $this->get( '/wp/v2/pages/' . $copy )->get_status() );
	}

	public function test_a_translated_copy_of_a_retail_page_is_not() {
		$copy = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'envios-es', 'post_title' => 'Envios ES' ) );
		$this->translate( $copy, $this->retail );

		$this->assertFalse( TradePages::is_trade_page( $copy ) );
		$this->assertFalse( TradePages::is_trade_page( $this->retail ) );
		$this->assertNotContains( $copy, TradePages::ids() );
	}

	/** The HostPages map and the configured account and checkout pages are trade pages by identity, whatever their slug. */
	public function test_a_mapped_or_configured_page_is_a_trade_page_by_identity() {
		$mapped   = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'loja-revenda' ) );
		$checkout = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'finalizar-socios' ) );
		$copy     = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'finalizar-compra-socios' ) );
		update_option( HostPages::OPTION, array( 'loja' => $mapped ) );
		update_option( \BeltoftCommerce\Checkout\B2BCheckoutPage::OPTION, $checkout );
		$this->translate( $copy, $checkout );

		$this->assertTrue( TradePages::is_trade_page( $mapped ) );
		$this->assertTrue( TradePages::is_trade_page( $checkout ) );
		$this->assertTrue( TradePages::is_trade_page( $copy ) );

		delete_option( \BeltoftCommerce\Checkout\B2BCheckoutPage::OPTION );
	}

	/** D1: a language plugin narrows get_posts() to the request's language unless the query asks for every language (Zerolang §9a). */
	public function test_the_trade_page_list_asks_for_every_language() {
		$marked = null;
		$watch  = static function ( $query ) use ( &$marked ) {
			if ( 'page' === $query->get( 'post_type' ) && 'ids' === $query->get( 'fields' ) ) {
				$marked = (bool) $query->get( 'zerolang_all_languages' );
			}
		};
		add_action( 'pre_get_posts', $watch );
		TradePages::forget();

		TradePages::ids();
		remove_action( 'pre_get_posts', $watch );

		$this->assertTrue( $marked );
	}

	/** The retail sitemaps leave a translated trade page out. */
	public function test_a_translated_trade_page_is_kept_out_of_the_sitemaps() {
		$login = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'entrar' ) );
		$copy  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'acceder' ) );
		$this->translate( $copy, $login );

		$this->assertTrue( TradePages::ignore_in_sitemap( false, get_post( $copy ) ) );
		$this->assertContains( $copy, TradePages::exclude_from_sitemap_query( array() )['post__not_in'] );
	}
}
