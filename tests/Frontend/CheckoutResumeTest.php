<?php

use BeltoftCommerce\Frontend\CheckoutResume;
use BeltoftCommerce\Support\Hosts;

class CheckoutResumeTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function output(): string {
		ob_start();
		CheckoutResume::render();

		return (string) ob_get_clean();
	}

	/**
	 * WooCommerce's own pages are not installed in the test database, so
	 * is_checkout() has nothing to recognise until one is made and pointed at.
	 */
	private function make_checkout_page(): int {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Finalizar' ) );
		update_option( 'woocommerce_checkout_page_id', $id );

		return $id;
	}

	private function on_trade_checkout(): void {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->go_to( get_permalink( $this->make_checkout_page() ) );

		$this->assertTrue( is_checkout(), 'the fixture itself' );
	}

	public function test_nothing_is_printed_away_from_the_checkout() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->make_checkout_page();
		$this->go_to( home_url( '/' ) );

		$this->assertSame( '', $this->output() );
	}

	public function test_nothing_is_printed_on_the_retail_host() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->go_to( get_permalink( $this->make_checkout_page() ) );

		$this->assertSame( '', $this->output(), 'Retail has its own single-page checkout and was not asked for.' );
	}

	public function test_the_trade_checkout_gets_the_script() {
		$this->on_trade_checkout();
		$out = $this->output();

		$this->assertStringContainsString( 'form.checkout', $out );
		$this->assertStringContainsString( 'sessionStorage', $out );
		$this->assertStringContainsString( 'brxCheckoutActiveStep', $out );
		$this->assertStringContainsString( '"passo"', $out, 'The step has to be named in the URL.' );
	}

	/**
	 * A consent is given, not remembered - so `terms` stays in the skip list
	 * even though every other answer is kept.
	 */
	public function test_the_terms_box_is_never_restored() {
		$this->on_trade_checkout();

		$this->assertMatchesRegularExpression( '/SKIP\s*=\s*\/\^\([^\/]*terms/', $this->output() );
	}

	/**
	 * The nonce would be stale on the next request and the cart quantities
	 * belong to the cart, which already keeps them.
	 */
	public function test_the_nonce_and_the_cart_are_not_stored() {
		$this->on_trade_checkout();
		$out = $this->output();

		$this->assertMatchesRegularExpression( '/SKIP\s*=\s*\/\^\([^\/]*cart\\\\\[/', $out );
		$this->assertMatchesRegularExpression( '/SKIP\s*=\s*\/\^\([^\/]*woocommerce-process-checkout-nonce/', $out );
	}

	/**
	 * Once the order exists the stored answers describe something finished, and
	 * the next sale on this machine must not start half filled with them.
	 */
	public function test_the_thank_you_page_throws_the_answers_away() {
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		$this->on_trade_checkout();
		$out = $this->output();
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );

		$this->assertStringContainsString( 'removeItem', $out );
		$this->assertStringNotContainsString( 'checkoutStepMode', $out, 'Nothing to resume once the sale is placed.' );
	}

	/**
	 * Two shoppers served from the same machine must not inherit each other's
	 * details, so the storage key carries who the session belongs to.
	 */
	public function test_the_storage_key_is_scoped_to_the_session() {
		$this->on_trade_checkout();

		$this->assertMatchesRegularExpression( '/KEY\s*=\s*"beltoft-checkout-[^"]+"/', $this->output() );
	}
}
