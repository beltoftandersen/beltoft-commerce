<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Scanner\ScannerContext;
use BeltoftCommerce\Support\Hosts;

/**
 * The B2B loja's part of the scanner, exercised through the scanner's filters
 * without the scanner loaded - the contract is the filters, not its classes.
 */
class ScannerContextTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		ScannerContext::register();
	}

	public function tearDown(): void {
		remove_all_filters( 'beltoft_scanner_contexts' );
		remove_all_filters( 'beltoft_scanner_assess' );
		remove_all_filters( 'beltoft_scanner_notify' );
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function context(): array {
		$contexts = apply_filters( 'beltoft_scanner_contexts', array() );

		$this->assertArrayHasKey( 'b2b-loja', $contexts );

		return $contexts['b2b-loja'];
	}

	private function may_scan( string $role ): bool {
		$user = get_userdata( self::factory()->user->create( array( 'role' => $role ) ) );

		return call_user_func( $this->context()['may_scan'], $user );
	}

	/** Partners and shop staff scan on the trade list. */
	public function test_partners_and_shop_staff_may_scan() {
		$this->assertTrue( $this->may_scan( 'b2b_customer' ) );
		$this->assertTrue( $this->may_scan( 'shop_manager' ) );
		$this->assertTrue( $this->may_scan( 'administrator' ) );
	}

	/** A pending applicant can sign in before anyone has approved them. */
	public function test_applicants_and_retail_customers_may_not() {
		$this->assertFalse( $this->may_scan( 'b2b_pending' ) );
		$this->assertFalse( $this->may_scan( 'customer' ) );
	}

	/** The till scans on its own page, through beltoft-pos's own context. */
	public function test_the_till_is_not_a_trade_list_scanner() {
		add_role( 'pos_operator', 'POS', array( 'read' => true ) );

		$this->assertFalse( $this->may_scan( 'pos_operator' ) );
	}

	public function test_it_does_not_apply_on_the_retail_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( call_user_func( $this->context()['applies'] ) );
	}

	public function test_it_does_not_apply_off_the_ordering_page_on_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );

		$this->assertFalse( call_user_func( $this->context()['applies'] ) );
	}

	private function product( ?string $audience ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '6' );

		return wc_get_product( $product->get_id() );
	}

	private function assess( WC_Product $product, int $user_id ) {
		return apply_filters( 'beltoft_scanner_assess', null, $product, $user_id );
	}

	/** Same answer the catalogue gives: a retail-only product is invisible to trade. */
	public function test_a_product_outside_the_partners_catalogue_is_not_visible() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame( 'not_visible', $this->assess( $this->product( null ), $partner ) );
	}

	public function test_a_product_in_the_partners_catalogue_passes() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertNull( $this->assess( $this->product( Taxonomy::BOTH ), $partner ) );
	}

	/** A variation is judged by its parent's audience, as the catalogue does. */
	public function test_a_variation_is_judged_by_its_parent() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$parent = new WC_Product_Variable();
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->save();

		$this->assertSame( 'not_visible', $this->assess( wc_get_product( $variation->get_id() ), $partner ) );
	}

	/** An earlier refusal from another rule is never overturned. */
	public function test_an_earlier_refusal_stands() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame(
			'not_purchasable',
			apply_filters( 'beltoft_scanner_assess', 'not_purchasable', $this->product( Taxonomy::BOTH ), $partner )
		);
	}

	// --- "Notify me": back-in-stock subscriptions through the notifier --------

	/** The in-stock notifier plugin, loaded the way the site loads it. */
	private function with_notifier(): void {
		if ( ! class_exists( '\\BeltoftInStockNotifier\\Subscription\\Repository' ) ) {
			require_once BELTOFT_SITE_PLUGINS . '/beltoft-in-stock-notifier/beltoft-in-stock-notifier.php';
		}

		\BeltoftInStockNotifier\Support\Installer::activate();

		// The handler hooks in only when the notifier exists, so register again now it does.
		ScannerContext::register();
	}

	private function notify( WC_Product $product, WP_User $user, int $quantity = 1 ) {
		return apply_filters( 'beltoft_scanner_notify', null, $product, $user, $quantity );
	}

	private function subscriptions_for( string $email ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, variation_id, quantity_requested, status FROM {$wpdb->prefix}bisn_subscriptions WHERE email = %s",
			$email
		), ARRAY_A );
	}

	/**
	 * Scanning an out-of-stock product offers "Notify me"; the scanner keeps
	 * no subscriptions itself and hands them to whoever handles the hook.
	 */
	public function test_notify_me_subscribes_the_partner_to_the_back_in_stock_email() {
		$this->with_notifier();
		$partner = get_userdata( self::factory()->user->create( array( 'role' => 'b2b_customer', 'user_email' => 'parceiro@example.com' ) ) );
		$product = $this->product( Taxonomy::BOTH );

		$this->assertSame( 'subscribed', $this->notify( $product, $partner, 6 ) );

		$rows = $this->subscriptions_for( 'parceiro@example.com' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $product->get_id(), (string) $rows[0]['product_id'] );
		$this->assertSame( '6', (string) $rows[0]['quantity_requested'] );
		$this->assertSame( 'active', $rows[0]['status'] );
	}

	public function test_asking_twice_says_already() {
		$this->with_notifier();
		$partner = get_userdata( self::factory()->user->create( array( 'role' => 'b2b_customer', 'user_email' => 'parceiro2@example.com' ) ) );
		$product = $this->product( Taxonomy::BOTH );

		$this->notify( $product, $partner );

		$this->assertSame( 'already', $this->notify( $product, $partner ) );
	}

	/** A variation is subscribed under its parent, as the notifier stores it. */
	public function test_a_variation_is_subscribed_under_its_parent() {
		$this->with_notifier();
		$partner = get_userdata( self::factory()->user->create( array( 'role' => 'b2b_customer', 'user_email' => 'parceiro3@example.com' ) ) );

		$parent = new WC_Product_Variable();
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$this->assertSame( 'subscribed', $this->notify( wc_get_product( $variation->get_id() ), $partner ) );

		$rows = $this->subscriptions_for( 'parceiro3@example.com' );
		$this->assertSame( (string) $parent->get_id(), (string) $rows[0]['product_id'] );
		$this->assertSame( (string) $variation->get_id(), (string) $rows[0]['variation_id'] );
	}

	/** Another handler that already answered is left alone. */
	public function test_an_earlier_answer_stands() {
		$this->with_notifier();
		$partner = get_userdata( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertSame( 'already', apply_filters( 'beltoft_scanner_notify', 'already', $this->product( Taxonomy::BOTH ), $partner, 1 ) );
	}
}
