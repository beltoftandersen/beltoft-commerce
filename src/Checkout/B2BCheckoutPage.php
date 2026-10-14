<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A checkout of its own for trade, without a second checkout implementation.
 *
 * The fields are the retail ones — same address, same payment, same validation.
 * What differs is the layout: trade orders are long, so the B2B page uses
 * Bricks' multistep Checkout v2 rather than one tall column.
 *
 * WHY THE PAGE ID IS FILTERED rather than the URL. `wc_get_page_id( 'checkout' )`
 * runs its option through `woocommerce_get_checkout_page_id`
 * (wc-page-functions.php:89), and everything downstream asks that one function:
 * `is_checkout()`, `wc_get_checkout_url()`, the order-pay and order-received
 * endpoints, notice output, and Bricks' own checkout-state detection. Swapping
 * the id makes the B2B page a REAL checkout to all of them. Filtering only the
 * URL would have produced a page that looks like a checkout and is not one —
 * `is_checkout()` false, endpoints resolving to the retail page, and no notices.
 *
 * Retail is untouched: the filter answers only for a B2B session.
 */
class B2BCheckoutPage {

	/** Option holding the B2B checkout page id, so the id is not hard-coded. */
	public const OPTION = 'beltoft_commerce_b2b_checkout_page_id';

	/** Where the trade checkout lives on the trade host - Frontend\HostPages serves it there. */
	public const PATH = '/finalizar/';

	/*
	 * Where it used to live. Order e-mails already sent carry thank-you and
	 * pay links on this path, so it forwards rather than 404s - endpoint tail
	 * and ?key= included, or the link in the e-mail would open to nothing.
	 */
	public const LEGACY_PATHS = array( '/finalizar-b2b/' );

	public static function register(): void {
		add_filter( 'woocommerce_get_checkout_page_id', array( __CLASS__, 'page_id' ) );
		add_action( 'template_redirect', array( __CLASS__, 'forward_legacy' ), 1 );
	}

	/** The old checkout path forwards to the new one, endpoint and query intact. */
	public static function forward_legacy(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		$target = self::legacy_target( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );

		if ( null !== $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/** Where a legacy URI should go, or null if it is not one. Pure, for tests. */
	public static function legacy_target( string $request_uri ): ?string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		foreach ( self::LEGACY_PATHS as $legacy ) {
			if ( '' === $path || 0 !== strpos( $path, $legacy ) ) {
				continue;
			}

			$target = home_url( self::PATH . substr( $path, strlen( $legacy ) ) );
			$query  = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

			return '' !== $query ? $target . '?' . $query : $target;
		}

		return null;
	}

	public static function page_id( $id ) {
		if ( ! self::applies() ) {
			return $id;
		}

		$b2b = self::configured_id();

		return $b2b ?: $id;
	}

	public static function configured_id(): int {
		$id = (int) get_option( self::OPTION, 0 );

		// A trashed or deleted page must fall back to retail rather than send
		// the buyer to a 404 with a full cart.
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}
}
