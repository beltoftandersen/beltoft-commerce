<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Frontend\QuickOrderPagePathShim;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Trade has no cart page. The ordering page is the cart.
 *
 * On the B2B host a buyer works from /encomendar/, where the inline cart sits
 * beside the product list and updates as lines are added. A separate cart page
 * would be a second, worse view of something already on screen, so there is
 * none - but WooCommerce does not know that and keeps emitting cart URLs: the
 * "return to cart" link on checkout, the redirect when a coupon fails
 * (woocommerce_cart_redirect_after_error), the empty-cart bounce off checkout,
 * and any template calling wc_get_cart_url(). Each one would land the buyer on
 * the retail cart page, which on this host is styled for consumers and priced
 * with VAT included.
 *
 * WHY THE URL AND NOT THE PAGE ID. The sister class B2BCheckoutPage swaps
 * `woocommerce_get_checkout_page_id` precisely so the B2B page becomes a real
 * checkout to `is_checkout()`. The opposite is wanted here: pointing
 * `woocommerce_get_cart_page_id` at /encomendar/ would make `is_cart()` true
 * there, and the ordering page is a product list that happens to show a cart.
 * Turning it into WooCommerce's cart page changes which scripts and fragments
 * load and what the Bricks query on that page is asked to render. Filtering the
 * URL moves every link without redefining what the page is.
 *
 * The template_redirect guard covers the other direction: a bookmarked or
 * hand-typed cart URL on the B2B host.
 */
class NoCartPage {

	public static function register(): void {
		add_filter( 'woocommerce_get_cart_url', array( __CLASS__, 'cart_url' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 5 );
	}

	public static function cart_url( $url ) {
		return self::applies() ? self::ordering_url() : $url;
	}

	public static function maybe_redirect(): void {
		if ( ! self::applies() || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		wp_safe_redirect( self::ordering_url(), 302 );
		exit;
	}

	private static function ordering_url(): string {
		return home_url( QuickOrderPagePathShim::ordering_path() );
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}
}
