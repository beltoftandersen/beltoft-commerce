<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\QuickOrder\Report;
use BeltoftCommerce\QuickOrder\Resolver;

class ResolverTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );

		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function make_product( string $sku, string $audience = 'both', float $price = 10.0 ): int {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_regular_price( (string) $price );
		$product->set_stock_status( 'instock' );
		$id = $product->save();

		Taxonomy::set_audience( $id, $audience );

		return $id;
	}

	public function test_an_unknown_code_is_rejected_as_unknown() {
		$report = Resolver::resolve( array( array( 'code' => 'NOPE-1', 'quantity' => 2 ) ), null );

		$this->assertSame( Report::UNKNOWN, $report->rejections()[0]['reason'] );
		$this->assertCount( 0, $report->successes() );
	}

	public function test_a_matching_sku_resolves_to_its_product_id() {
		$id = $this->make_product( 'QO-SKU-1' );

		$this->assertSame( $id, Resolver::find_product_id( 'QO-SKU-1' ) );
	}

	/**
	 * Codes are typed by a human off a carton or an invoice, so case is not
	 * information.
	 */
	public function test_sku_matching_ignores_case() {
		$id = $this->make_product( 'QO-SKU-2' );

		$this->assertSame( $id, Resolver::find_product_id( 'qo-sku-2' ) );
	}

	public function test_a_gtin_also_matches() {
		$id      = $this->make_product( 'QO-SKU-3' );
		$product = wc_get_product( $id );
		$product->set_global_unique_id( '5601234567890' );
		$product->save();

		$this->assertSame( $id, Resolver::find_product_id( '5601234567890' ) );
	}

	public function test_an_out_of_stock_product_is_rejected() {
		$id      = $this->make_product( 'QO-SKU-4' );
		$product = wc_get_product( $id );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$report = Resolver::resolve( array( array( 'code' => 'QO-SKU-4', 'quantity' => 1 ) ), null );

		$this->assertSame( Report::OUT_OF_STOCK, $report->rejections()[0]['reason'] );
	}

	/**
	 * A quantity of zero or less is not an order and not an error: it is the
	 * ordinary state of most rows in a downloaded sheet.
	 */
	public function test_a_zero_quantity_line_is_skipped_entirely() {
		$this->make_product( 'QO-SKU-5' );

		$report = Resolver::resolve( array( array( 'code' => 'QO-SKU-5', 'quantity' => 0 ) ), null );

		$this->assertCount( 0, $report->successes() );
		$this->assertCount( 0, $report->rejections() );
	}

	public function test_a_resolvable_line_reaches_the_cart() {
		$id = $this->make_product( 'QO-SKU-6' );

		$report = Resolver::resolve( array( array( 'code' => 'QO-SKU-6', 'quantity' => 3 ) ), null );

		$this->assertCount( 1, $report->successes() );
		$this->assertSame( $id, $report->successes()[0]['product_id'] );
		$this->assertSame( 3, $report->successes()[0]['quantity'] );

		// A stubbed add_to_cart() would let this pass; asserting the cart
		// itself proves the line really reached WC()->cart, not just the
		// Report.
		$this->assertSame( 3, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * Ruling 11 / ranked I1: a product the buyer cannot even SEE must read
	 * exactly like a code that does not exist, or a buyer pasting codes could
	 * map the B2B-only catalog one SKU at a time. The reason is kept as
	 * NOT_AVAILABLE (distinct in logs), but the message attached to the
	 * rejection borrows UNKNOWN's wording.
	 */
	public function test_a_hidden_product_reads_like_unknown_but_stays_distinct_in_the_log() {
		$id = $this->make_product( 'QO-HIDDEN-1', Taxonomy::B2B );

		wp_set_current_user( 0 );

		$report = Resolver::resolve( array( array( 'code' => 'QO-HIDDEN-1', 'quantity' => 1 ) ), null );

		$this->assertCount( 0, $report->successes() );

		$rejection = $report->rejections()[0];
		$this->assertSame( Report::NOT_AVAILABLE, $rejection['reason'], 'kept distinct from UNKNOWN for logs' );
		$this->assertSame( Report::message_for( Report::UNKNOWN ), $rejection['detail'], 'message shown must match an unknown code' );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
		$this->assertSame( $id, Resolver::find_product_id( 'QO-HIDDEN-1' ), 'sanity: the product genuinely exists' );
	}

	/**
	 * The same scenario from the buyer's side: a retail session pasting a
	 * B2B-only SKU adds nothing to the cart.
	 */
	public function test_a_retail_session_resolving_a_b2b_only_sku_adds_nothing() {
		$this->make_product( 'QO-HIDDEN-2', Taxonomy::B2B );

		wp_set_current_user( 0 );

		$report = Resolver::resolve( array( array( 'code' => 'QO-HIDDEN-2', 'quantity' => 5 ) ), null );

		$this->assertCount( 0, $report->successes() );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * A product the buyer CAN see but for whom no group price is stored is
	 * NOT_AVAILABLE with the shared "not available on your account" wording
	 * -- distinct from the hidden-product case above only in the log.
	 */
	public function test_a_visible_but_unpriced_product_is_not_available() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		Roles::install();

		$id = $this->make_product( 'QO-NOGROUPPRICE', Taxonomy::BOTH );
		// No _price_group_b2b_standard meta is set, so the group has no price.

		$b2b_user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $b2b_user );

		$report = Resolver::resolve( array( array( 'code' => 'QO-NOGROUPPRICE', 'quantity' => 1 ) ), null );

		$this->assertCount( 0, $report->successes() );

		$rejection = $report->rejections()[0];
		$this->assertSame( Report::NOT_AVAILABLE, $rejection['reason'] );
		$this->assertSame( Report::message_for( Report::NOT_AVAILABLE ), Report::message_for( Report::NO_PRICE ), 'the two share a message by design' );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * NO_PRICE is WooCommerce's own idea of purchasable (no price stored at
	 * all), which is a different path from the group-pricing gate above:
	 * CatalogAudience::can_purchase() returns true unconditionally for a
	 * retail session, so a retail-visible product with no WooCommerce price
	 * only fails at $product->is_purchasable().
	 */
	public function test_a_product_with_no_price_at_all_is_rejected_as_no_price() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'QO-NOPRICE' );
		$product->set_stock_status( 'instock' );
		$id = $product->save();

		Taxonomy::set_audience( $id, Taxonomy::BOTH );

		$this->assertFalse( wc_get_product( $id )->is_purchasable(), 'sanity: no price means WooCommerce will not sell it' );

		$report = Resolver::resolve( array( array( 'code' => 'QO-NOPRICE', 'quantity' => 1 ) ), null );

		$this->assertCount( 0, $report->successes() );
		$this->assertSame( Report::NO_PRICE, $report->rejections()[0]['reason'] );
	}

	/**
	 * I2 / Ruling 11: WC_Cart::add_to_cart() catches its own exception,
	 * wc_add_notice()s the reason ("... 9 remaining") and returns false. That
	 * reason must be lifted out of the notice queue and attached to the
	 * rejection with the SKU, not left generic.
	 */
	public function test_a_line_over_stock_is_refused_with_woocommerces_own_reason_attached() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'QO-STOCK-9' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 9 );
		$product->set_stock_status( 'instock' );
		$id = $product->save();

		Taxonomy::set_audience( $id, Taxonomy::BOTH );

		$report = Resolver::resolve( array( array( 'code' => 'QO-STOCK-9', 'quantity' => 12 ) ), null );

		$this->assertCount( 0, $report->successes() );

		$rejection = $report->rejections()[0];
		$this->assertSame( Report::REFUSED, $rejection['reason'] );
		$this->assertStringContainsString( '9', (string) $rejection['detail'] );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );

		// The line's own notice, with its SKU, replaces WooCommerce's
		// generic one in the queue -- it must not still be sitting there
		// waiting to be printed a second time with no SKU attached.
		$notices = wc_get_notices( 'error' );
		$this->assertCount( 0, $notices );
	}

	/**
	 * b2b_pending is retail-equivalent for every access purpose until
	 * approval (architecture §2.2): a pending applicant must resolve a
	 * B2B-only SKU to nothing, exactly like a guest.
	 */
	public function test_a_pending_applicant_resolves_a_b2b_only_sku_to_nothing() {
		Roles::install();

		$this->make_product( 'QO-PENDING-1', Taxonomy::B2B );

		$pending_user = $this->factory->user->create( array( 'role' => 'b2b_pending' ) );
		wp_set_current_user( $pending_user );

		$report = Resolver::resolve( array( array( 'code' => 'QO-PENDING-1', 'quantity' => 2 ) ), null );

		$this->assertCount( 0, $report->successes() );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}
}
