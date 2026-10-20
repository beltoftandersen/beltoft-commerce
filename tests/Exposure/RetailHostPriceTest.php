<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

/**
 * "A partner sees retail prices on retail hosts" (Christian, 2026-10-20;
 * architecture §2.3, amended).
 *
 * A signed-in b2b_customer whose group has its own prices is served retail
 * prices on every front-end surface of a host that is not the trade host, and
 * the group's prices on the trade host. Staff acting for a customer and
 * background work still price for the group.
 */
class RetailHostPriceTest extends WP_UnitTestCase {

	private int $product_id;
	private int $partner;

	/** @var array<string,string> group slug => its sentinel price */
	private array $group_prices = array();

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		do_action( 'rest_api_init' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Sentinel soap' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_stock_status( 'instock' );
		$product->save();
		$this->product_id = $product->get_id();
		Taxonomy::set_audience( $this->product_id, Taxonomy::BOTH );

		// A distinctive price per registered group (the PriceExposureTest pattern).
		$n = 0;
		foreach ( array_keys( Registry::groups() ) as $slug ) {
			$price = (string) ( 7770 + $n );
			update_post_meta( $this->product_id, Registry::regular_key( $slug ), $price );
			$this->group_prices[ $slug ] = $price;
			$n++;
		}

		wc_delete_product_transients( $this->product_id );

		$this->partner = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		$this->assertArrayHasKey( PricingContext::for_user( $this->partner ), $this->group_prices, 'The fixture partner must have a group with its own prices.' );

		wp_set_current_user( $this->partner );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
	}

	public function tearDown(): void {
		WC()->cart->empty_cart();
		PricingContext::stop_acting();
		remove_all_filters( 'wp_doing_cron' );
		remove_all_filters( 'wp_doing_ajax' );
		$GLOBALS['current_screen'] = null;
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function on( string $host ): void {
		$_SERVER['HTTP_HOST'] = $host;
	}

	private function sentinel(): string {
		return $this->group_prices[ PricingContext::for_user( $this->partner ) ];
	}

	/** Every spelling a price can take in a response: raw, minor units, formatted. */
	private function spellings( string $price ): array {
		return array_unique( array(
			$price,
			$price . '00',
			html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES ),
			wc_format_localized_price( $price ),
		) );
	}

	private function assertNoGroupPrice( string $haystack, string $where ): void {
		// Markup and its entities out: wc_price() splits the symbol from the number with a tag.
		$haystack = $haystack . ' ' . html_entity_decode( wp_strip_all_tags( $haystack ), ENT_QUOTES );

		foreach ( $this->group_prices as $slug => $price ) {
			foreach ( $this->spellings( $price ) as $spelling ) {
				$this->assertStringNotContainsString( $spelling, $haystack, "Group price for {$slug} ({$spelling}) on {$where}" );
			}
		}
	}

	private function assertGroupPrice( string $haystack, string $where ): void {
		// Markup and its entities out: wc_price() splits the symbol from the number with a tag.
		$haystack = $haystack . ' ' . html_entity_decode( wp_strip_all_tags( $haystack ), ENT_QUOTES );
		$found    = false;

		foreach ( $this->spellings( $this->sentinel() ) as $spelling ) {
			$found = $found || false !== strpos( $haystack, $spelling );
		}

		$this->assertTrue( $found, "The partner's group price is missing on {$where}" );
	}

	private function product_page(): string {
		$this->go_to( get_permalink( $this->product_id ) );

		return (string) wc_get_product( $this->product_id )->get_price_html() . (string) wc_get_product( $this->product_id )->get_price();
	}

	private function archive(): string {
		// No shop page in the fixture, so the post type archive by query.
		$this->go_to( home_url( '/?post_type=product' ) );

		$html = '';

		foreach ( $GLOBALS['wp_query']->posts as $post ) {
			$html .= (string) wc_get_product( $post )->get_price_html();
		}

		$this->assertNotSame( '', $html, 'The archive listed nothing.' );

		return $html;
	}

	private function store_api( string $route ): string {
		return (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data() );
	}

	private function cart(): string {
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product_id );
		WC()->cart->calculate_totals();

		ob_start();
		woocommerce_mini_cart();
		$mini = (string) ob_get_clean();

		// The fragments WooCommerce sends after an add to cart.
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' ) );

		return WC()->cart->get_total( 'edit' ) . ' ' . WC()->cart->get_subtotal() . ' ' . WC()->cart->get_cart_contents_total() . ' ' . wp_json_encode( $fragments ) . ' ' . $this->store_api( '/wc/store/v1/cart' );
	}

	public function test_a_partner_on_the_retail_host_sees_no_group_price_on_the_product_page() {
		$this->on( Hosts::retail() );

		$this->assertNoGroupPrice( $this->product_page(), 'the product page' );
		$this->assertSame( '100', wc_get_product( $this->product_id )->get_price() );
	}

	public function test_a_partner_on_the_retail_host_sees_no_group_price_in_the_shop() {
		$this->on( Hosts::retail() );

		$this->assertNoGroupPrice( $this->archive(), 'the shop archive' );
	}

	public function test_a_partner_on_the_retail_host_sees_no_group_price_in_the_store_api() {
		$this->on( Hosts::retail() );

		$this->assertNoGroupPrice( $this->store_api( '/wc/store/v1/products/' . $this->product_id ), 'Store API product' );
		$this->assertNoGroupPrice( $this->store_api( '/wc/store/v1/products' ), 'Store API products' );
	}

	public function test_a_partner_on_the_retail_host_has_a_retail_cart_and_fragments() {
		$this->on( Hosts::retail() );

		$this->assertNoGroupPrice( $this->cart(), 'cart totals and fragments' );
		$this->assertEquals( 100, (float) WC()->cart->get_cart_contents_total() );
	}

	/** Any host that is not the trade host: a language domain, an alias. */
	public function test_a_host_other_than_the_trade_host_is_retail_too() {
		$this->on( 'mindthetrash.example' );

		$this->assertNoGroupPrice( $this->product_page(), 'another host' );
	}

	/** The control: the contract must not hold because the price never shows anywhere. */
	public function test_the_same_partner_on_the_trade_host_sees_the_group_price_everywhere() {
		$this->on( Hosts::b2b() );

		$this->assertGroupPrice( $this->product_page(), 'the trade product page' );
		$this->assertGroupPrice( $this->archive(), 'the trade shop' );
		$this->assertGroupPrice( $this->store_api( '/wc/store/v1/products/' . $this->product_id ), 'the trade Store API' );
		$this->assertGroupPrice( $this->cart(), 'the trade cart' );
	}

	/** wp-admin lives on the retail host: staff building an order for a partner price it for the partner's group. */
	public function test_staff_acting_for_a_partner_on_the_retail_host_price_for_the_group() {
		$this->on( Hosts::retail() );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'shop_manager' ) ) );
		set_current_screen( 'woocommerce_page_wc-orders' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		PricingContext::act_for( $this->partner );

		$this->assertSame( PricingContext::for_user( $this->partner ), PricingContext::current() );
		$this->assertSame( $this->sentinel(), wc_get_product( $this->product_id )->get_price() );
	}

	/** Background work prices for the account, whatever host it ran on. */
	public function test_cron_prices_for_the_account() {
		$this->on( Hosts::retail() );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( PricingContext::for_user( $this->partner ), PricingContext::current() );
	}

	/** A wp-admin screen (not admin-ajax) is not the shop front. */
	public function test_a_wp_admin_screen_prices_for_the_account() {
		$this->on( Hosts::retail() );
		set_current_screen( 'edit-product' );

		$this->assertSame( PricingContext::for_user( $this->partner ), PricingContext::current() );
	}

	/** admin-ajax called from a retail page is the front end. */
	public function test_admin_ajax_from_the_retail_front_end_is_retail() {
		$this->on( Hosts::retail() );
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( Registry::retail_slug(), PricingContext::current() );
	}

	/** admin-post.php carries the shop's own forms (saved carts and the like): front end too. */
	public function test_admin_post_from_the_retail_front_end_is_retail() {
		$this->on( Hosts::retail() );
		set_current_screen( 'dashboard' );
		$was                = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow'] = 'admin-post.php';

		try {
			$this->assertSame( Registry::retail_slug(), PricingContext::current() );
		} finally {
			$GLOBALS['pagenow'] = $was;
		}
	}

	/** Action Scheduler's async runner is admin-ajax on the retail host, and background work. */
	public function test_the_action_scheduler_runner_prices_for_the_account() {
		$this->on( Hosts::retail() );
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['action'] = 'as_async_request_queue_runner';

		try {
			$this->assertFalse( PricingContext::is_retail_front() );
		} finally {
			unset( $_REQUEST['action'] );
		}
	}

	/**
	 * Judging someone else (the recipient of a back-in-stock email, a saved
	 * cart's owner) uses their account; only the visitor on a retail front is retail.
	 */
	public function test_another_user_is_judged_by_their_account_on_a_retail_front() {
		$b2b_only = new WC_Product_Simple();
		$b2b_only->set_regular_price( '12' );
		$b2b_only->set_status( 'publish' );
		$b2b_only->save();
		update_post_meta( $b2b_only->get_id(), Registry::regular_key( PricingContext::for_user( $this->partner ) ), '9' );
		Taxonomy::set_audience( $b2b_only->get_id(), Taxonomy::B2B );

		$this->on( Hosts::retail() );
		wp_set_current_user( 0 );

		$this->assertSame( PricingContext::for_user( $this->partner ), PricingContext::applicable( $this->partner ) );
		$this->assertTrue( \BeltoftCommerce\Catalog\CatalogAudience::can_view( $this->partner, $b2b_only->get_id() ) );
		$this->assertTrue( \BeltoftCommerce\Catalog\CatalogAudience::can_purchase( $this->partner, $b2b_only->get_id() ) );

		// The partner as the visitor on the retail front is retail.
		wp_set_current_user( $this->partner );
		$this->assertSame( Registry::retail_slug(), PricingContext::applicable( $this->partner ) );
		$this->assertFalse( \BeltoftCommerce\Catalog\CatalogAudience::can_view( $this->partner, $b2b_only->get_id() ) );
	}

	/** The account still belongs to its group; only the request's context changed. */
	public function test_the_account_keeps_its_group() {
		$this->on( Hosts::retail() );

		$this->assertTrue( Registry::is_trade( PricingContext::for_user( $this->partner ) ) );
		$this->assertSame( Registry::retail_slug(), PricingContext::current() );
	}

	/** On the retail host the partner is a retail shopper for the catalogue too: a retail-only product is theirs to buy. */
	public function test_a_partner_on_the_retail_host_may_buy_a_retail_only_product() {
		$retail_only = new WC_Product_Simple();
		$retail_only->set_regular_price( '12' );
		$retail_only->set_status( 'publish' );
		$retail_only->save();
		Taxonomy::set_audience( $retail_only->get_id(), Taxonomy::RETAIL );

		$this->on( Hosts::retail() );
		$this->assertTrue( wc_get_product( $retail_only->get_id() )->is_purchasable() );

		$this->on( Hosts::b2b() );
		\BeltoftCommerce\Catalog\CatalogAudience::flush();
		$this->assertFalse( wc_get_product( $retail_only->get_id() )->is_purchasable() );
	}
}
