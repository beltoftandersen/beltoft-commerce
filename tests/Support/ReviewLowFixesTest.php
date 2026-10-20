<?php

use BeltoftCommerce\Account\Reorder;
use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Domain\TradePages;
use BeltoftCommerce\Enforcement\FeedGuard;
use BeltoftCommerce\Enforcement\StoreApiGuard;
use BeltoftCommerce\Frontend\HostPages;
use BeltoftCommerce\Frontend\QueryResultCache;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Pricing\SaleScheduler;
use BeltoftCommerce\Registration\Status;
use BeltoftCommerce\Shipping\B2BMethods;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Installer;
use BeltoftCommerce\Vat\OrderEvidence;
use BeltoftCommerce\Vat\Validator;

/** The low-severity findings of the 2026-10-17 review, one test each. */
class ReviewLowFixesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_GET['min_price'], $_GET['order_again'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function product( ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		return $product->get_id();
	}

	/** #22: the cart's add-item does not name a product the visitor may not see. */
	public function test_cart_add_item_refuses_an_out_of_audience_product() {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_param( 'id', $this->product( Taxonomy::B2B ) );
		$this->assertWPError( StoreApiGuard::guard_cart_add( null, null, $request ) );

		$request->set_param( 'id', $this->product( null ) );
		$this->assertNull( StoreApiGuard::guard_cart_add( null, null, $request ) );
	}

	/** #23: reviews of products outside the audience are left out. */
	public function test_reviews_of_hidden_products_are_dropped() {
		$hidden  = $this->product( Taxonomy::B2B );
		$visible = $this->product( null );

		$response = new WP_REST_Response( array( array( 'product_id' => $hidden ), array( 'product_id' => $visible ) ) );
		$out      = StoreApiGuard::guard_reviews( $response, array(), new WP_REST_Request( 'GET', '/wc/store/v1/products/reviews' ) );

		$this->assertSame( array( array( 'product_id' => $visible ) ), $out->get_data() );
	}

	/** #24/#34: the early handler is told the plugin's real folder. */
	public function test_the_plugin_folder_is_recorded_for_the_early_handler() {
		$target = BeltoftCommerce\Support\MuPlugin::target();
		$before = is_readable( $target ) ? (string) file_get_contents( $target ) : null;

		delete_option( 'beltoft_commerce_basename' );
		BeltoftCommerce\Support\MuPlugin::maybe_install();

		// maybe_install() also writes the handler into the test install's
		// mu-plugins, where later runs would load it twice; put back what was there.
		null === $before ? @unlink( $target ) : file_put_contents( $target, $before );

		$this->assertSame( plugin_basename( BELTOFT_COMMERCE_FILE ), get_option( 'beltoft_commerce_basename' ) );
	}

	/** #25: trade pages are not in the sitemap or core search. */
	public function test_trade_pages_stay_out_of_sitemaps_and_search() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-envios-teste' ) );
		TradePages::forget();

		$this->assertTrue( TradePages::ignore_in_sitemap( false, get_post( $page ) ) );
		$this->assertContains( $page, TradePages::exclude_from_sitemap_query( array() )['post__not_in'] );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertContains( $page, apply_filters( 'rest_post_search_query', array(), new WP_REST_Request() )['post__not_in'] ?? array() );
	}

	/** #26: a mapped trade page redirects to the path the trade host serves it at. */
	public function test_a_mapped_trade_page_redirects_to_its_root_path() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja-teste' ) );
		update_option( HostPages::OPTION, array( 'loja-teste' => $page ) );
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->go_to( home_url( '/?page_id=' . $page ) );

		$this->assertSame( 'https://' . Hosts::b2b() . '/loja-teste/', HostRoutes::resolved_redirect_target() );
	}

	/** #27: a number never sent to VIES gets no VIES date on the order. */
	public function test_a_domestic_number_gets_no_vies_date() {
		$order = new WC_Order();
		OrderEvidence::write( $order, Validator::decide( 'PT501964843', 'PT' ) );

		$this->assertSame( '', $order->get_meta( OrderEvidence::REQUEST_DATE ) );
	}

	/** #29: rejecting takes away the per-user trade group too. */
	public function test_reject_clears_the_pricing_group_override() {
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user, PricingContext::OVERRIDE_META, 'b2b_standard' );

		$this->assertTrue( Status::reject( $user ) );
		$this->assertSame( '', get_user_meta( $user, PricingContext::OVERRIDE_META, true ) );
		$this->assertSame( 'retail', PricingContext::for_user( $user ) );
	}

	/** #30: a role changed by hand keeps the recorded decision in step. */
	public function test_status_follows_a_role_changed_by_hand() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		Status::approve( $partner );

		get_userdata( $partner )->set_role( 'b2b_pending' );
		$this->assertSame( Status::REJECTED, Status::get( $partner ) );
		// The recorded decision too (sync_with_role), which the queues read.
		$this->assertSame( Status::REJECTED, get_user_meta( $partner, Status::META_STATUS, true ) );

		get_userdata( $partner )->set_role( 'b2b_customer' );
		$this->assertSame( Status::APPROVED, Status::get( $partner ) );
		$this->assertSame( Status::APPROVED, get_user_meta( $partner, Status::META_STATUS, true ) );
	}

	/** #31: WooCommerce's own "Order again" is switched off on the trade host. */
	public function test_the_native_order_again_is_retired_on_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$_GET['order_again']  = '12';

		Reorder::retire_native_order_again();

		$this->assertFalse( has_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' ) );
		$this->assertArrayNotHasKey( 'order_again', $_GET );
		add_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
	}

	/** #32: trashing a product drops the cached trade rows. */
	public function test_trashing_a_product_drops_the_cached_rows() {
		$id = $this->product( Taxonomy::BOTH );
		QueryResultCache::commit();
		$before = get_option( 'beltoft_commerce_rows_version' );

		wp_trash_post( $id );
		QueryResultCache::commit();

		$this->assertNotSame( $before, get_option( 'beltoft_commerce_rows_version' ) );
	}

	/** #35: a feed keeps what the public shop shows, whoever builds it. */
	public function test_a_feed_is_public_whoever_builds_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-product' );

		$hidden = $this->product( Taxonomy::B2B );
		$shown  = $this->product( null );

		$this->assertSame( array( $shown ), apply_filters( 'beltoft_commerce_feed_product_ids', array( $hidden, $shown ) ) );
		set_current_screen( 'front' );
	}

	/** #36: extra public and trade-only paths come from the settings. */
	public function test_extra_paths_come_from_the_settings() {
		add_filter( 'beltoft_commerce_config', static fn( $c ) => array_merge( $c, array( 'extra_public_paths' => array( '/contacto/' ), 'extra_trade_only_paths' => array( '/revenda/' ) ) ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( '/contacto/' ) );
		$this->assertContains( '/revenda/', HostRoutes::b2b_only_paths() );
	}

	/** #37: deactivation clears the endpoints' flush flags and the plugin's jobs. */
	public function test_deactivation_cleans_up() {
		update_option( 'beltoft_saved_carts_endpoint_flushed', '1' );
		wp_schedule_event( time() + 60, 'hourly', SaleScheduler::BOUNDARY_HOOK );

		Installer::deactivate();

		$this->assertFalse( get_option( 'beltoft_saved_carts_endpoint_flushed' ) );
		$this->assertFalse( wp_next_scheduled( SaleScheduler::BOUNDARY_HOOK ) );
	}

	/** #46: an empty trade page prefix does not claim every path. */
	public function test_an_empty_trade_page_prefix_claims_nothing() {
		add_filter( 'beltoft_commerce_config', static fn( $c ) => array_merge( $c, array( 'trade_page_prefix' => '' ) ) );

		$this->assertFalse( HostRoutes::is_b2b_only_path( '/' ) );
		$this->assertFalse( HostRoutes::is_b2b_only_path( '/wp-admin/' ) );
	}

	/** #47: a trade session's price bounds go to the group table; WooCommerce's retail clause never sees them. */
	public function test_trade_price_bounds_leave_the_retail_clause() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );
		$_GET['min_price'] = '5';

		$main  = $GLOBALS['wp_the_query'];
		$query = new WP_Query();
		$query->query_vars = array( 'post_type' => 'product' );
		$GLOBALS['wp_the_query'] = $query; // So it is the main query.

		try {
			BeltoftCommerce\Hooks\PriceQuery::take_price_bounds( $query );
		} finally {
			$GLOBALS['wp_the_query'] = $main;
		}

		$this->assertSame( 5.0, $query->get( 'beltoft_min_price' ) );
		$this->assertArrayNotHasKey( 'min_price', $_GET );
	}

	/** #49: which rates are trade-only is part of the shipping package. */
	public function test_the_trade_only_rates_are_part_of_the_package() {
		update_option( B2BMethods::OPTION, array( 27, 28 ) );
		$a = B2BMethods::tag_packages( array( array() ) );
		update_option( B2BMethods::OPTION, array( 27 ) );
		$b = B2BMethods::tag_packages( array( array() ) );

		$this->assertNotSame( $a, $b );
	}

	/** #50: the boundary job runs on the hour. */
	public function test_the_boundary_job_runs_on_the_hour() {
		wp_clear_scheduled_hook( SaleScheduler::BOUNDARY_HOOK );
		wp_schedule_event( time() + 1234, 'hourly', SaleScheduler::BOUNDARY_HOOK );

		SaleScheduler::schedule();

		$this->assertLessThan( MINUTE_IN_SECONDS, wp_next_scheduled( SaleScheduler::BOUNDARY_HOOK ) % HOUR_IN_SECONDS );
		wp_clear_scheduled_hook( SaleScheduler::BOUNDARY_HOOK );
	}

	/** #48: the order's tax line keeps the rate's own name; display is reformatted. */
	public function test_the_order_keeps_the_rate_name() {
		$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '23.0000', 'tax_rate_name' => 'IVA23', 'tax_rate_priority' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertNotSame( 'IVA23', WC_Tax::get_rate_label( $rate ), 'displayed reformatted' );

		$item = new WC_Order_Item_Tax();
		$item->set_label( WC_Tax::get_rate_label( $rate ) );
		BeltoftCommerce\Vat\RateLabel::store_rate_name( $item, $rate );

		$this->assertSame( 'IVA23', $item->get_label() );
	}

	/** Verification round: the Store API's second add route is guarded too. */
	public function test_cart_items_post_is_guarded() {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/items' );
		$request->set_param( 'id', $this->product( Taxonomy::B2B ) );

		$this->assertWPError( StoreApiGuard::guard_cart_add( null, null, $request ) );
	}

	/** Verification round: Bricks loops cannot ask for private posts. */
	public function test_bricks_loops_get_published_posts_only() {
		$vars = BeltoftCommerce\Enforcement\QueryFilter::clean_bricks_query_vars( array( 'post_status' => 'private', 'perm' => 'readable' ) );

		$this->assertSame( array( 'post_status' => 'publish' ), $vars );
	}

	/** Verification round: an empty ?rest_route= is not REST and does not dodge the admin-ajax guard. */
	public function test_an_empty_rest_route_is_not_a_rest_path() {
		$this->assertSame( '/wp-admin/admin-ajax.php', LoginGate::effective_path( '/wp-admin/admin-ajax.php?action=x&rest_route=' ) );
		$this->assertSame( '/wp-admin/admin-ajax.php', LoginGate::effective_path( '/wp-admin/admin-ajax.php?rest_route=0' ) );
		$this->assertSame( '/wp-json/wc/store/v1/cart', LoginGate::effective_path( '/?rest_route=/wc/store/v1/cart' ) );
	}

	/** Verification round: rejecting never demotes staff who hold the pricing override. */
	public function test_reject_leaves_staff_alone() {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		update_user_meta( $manager, PricingContext::OVERRIDE_META, 'b2b_standard' );

		$this->assertFalse( Status::reject( $manager ) );
		$this->assertContains( 'shop_manager', get_userdata( $manager )->roles );
	}

	/** Final check: variations of a private product are not listed to visitors. */
	public function test_variations_of_a_private_product_are_not_listed() {
		$parent = new WC_Product_Variable();
		$parent->set_status( 'private' );
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '9' );
		$variation->save();
		BeltoftCommerce\Catalog\CatalogAudience::flush();

		$ids = ( new WP_Query( array( 'post_type' => 'product_variation', 'post_parent__in' => array( $parent->get_id() ), 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->posts;

		$this->assertNotContains( $variation->get_id(), array_map( 'intval', $ids ) );
	}

	/** Final check: rest_route on admin-ajax.php does not change what the gate sees. */
	public function test_admin_ajax_with_a_rest_route_is_still_guarded() {
		$this->assertFalse( LoginGate::admin_endpoint_allowed( '/wp-admin/admin-ajax.php', 'woocommerce_get_cart_totals', array( 'rest_route' => '/x' ) ) );
	}

	/** Final check: "Change role" never runs a B2B action left in the other select. */
	public function test_change_role_does_not_run_a_b2b_bulk_action() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		$_REQUEST  = array( 'action' => 'beltoft_b2b_reject', 'changeit' => 'Change', 'new_role' => 'customer', 'users' => array( $applicant ), '_wpnonce' => wp_create_nonce( 'bulk-users' ) );

		$this->assertNull( BeltoftCommerce\Registration\AdminUsers::bulk_from_request() );
		$this->assertNotSame( Status::REJECTED, Status::get( $applicant ) );
		$_REQUEST = array();
	}

	/** Final check: a checkout without the VAT field leaves the account's number alone. */
	public function test_a_checkout_without_the_vat_field_keeps_the_account_number() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$user = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		update_user_meta( $user, '_vat_number', 'DE123456789' );
		unset( $_POST['beltoft_vat_number'] );

		$customer = new WC_Customer( $user );
		BeltoftCommerce\Vat\Field::save_to_customer( $customer, array() );
		$customer->save();

		$this->assertSame( 'DE123456789', get_user_meta( $user, '_vat_number', true ) );
	}

	/** Final check: REST-created orders are priced for the order's customer. */
	public function test_rest_orders_are_priced_for_the_customer() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_param( 'customer_id', $partner );
		BeltoftCommerce\Hooks\Price::price_rest_order_for_customer( null, null, $request );

		$this->assertSame( 'b2b_standard', PricingContext::current() );
		PricingContext::stop_acting();
	}

	/** A REST line without a total, for a product the customer has no price for, is refused. */
	public function test_rest_orders_refuse_unpriced_lines() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$unpriced = $this->product( Taxonomy::BOTH );
		$priced   = $this->product( Taxonomy::BOTH );
		update_post_meta( $priced, '_price_group_b2b_standard', '6' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_param( 'customer_id', $partner );

		$request->set_param( 'line_items', array( array( 'product_id' => $priced, 'quantity' => 1 ), array( 'product_id' => $unpriced, 'quantity' => 1 ) ) );
		$this->assertWPError( BeltoftCommerce\Hooks\Price::price_rest_order_for_customer( null, null, $request ) );

		// The integration's own total is its price, and goes through.
		$request->set_param( 'line_items', array( array( 'product_id' => $unpriced, 'quantity' => 1, 'total' => '5.00' ) ) );
		$this->assertNull( BeltoftCommerce\Hooks\Price::price_rest_order_for_customer( null, null, $request ) );

		$request->set_param( 'line_items', array( array( 'product_id' => $priced, 'quantity' => 1 ) ) );
		$this->assertNull( BeltoftCommerce\Hooks\Price::price_rest_order_for_customer( null, null, $request ) );
		PricingContext::stop_acting();
	}
}
