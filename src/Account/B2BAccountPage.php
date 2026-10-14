<?php

namespace BeltoftCommerce\Account;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * An account area of trade's own, built on Bricks' Account v2.
 *
 * Retail's My Account page is shared by both hosts and styled for consumers.
 * Trade needs the same endpoints - orders, addresses, account details, password
 * - in the trade chrome and next to the trade checkout, so this is a second
 * page carrying Bricks' fourteen Account v2 states.
 *
 * WHY THE PAGE ID IS FILTERED rather than the URL, exactly as with
 * Checkout\B2BCheckoutPage: wc_get_page_id( 'myaccount' ) is what
 * `is_account_page()`, `wc_get_account_endpoint_url()`, every endpoint link in
 * the order emails and WooCommerce's own redirects all resolve through.
 * Swapping the id makes this page the real account page to all of them.
 * Filtering the URL alone would leave endpoints resolving to the retail page.
 *
 * Retail is untouched: the filter answers only for a B2B request, and a missing
 * or unpublished page falls back to the retail id rather than leaving trade
 * with no account area.
 *
 * NOTE for whoever changes this slug: Domain\LoginGate allowlists the
 * password-recovery endpoint underneath it by literal path, because that gate runs
 * from the mu-plugin before WooCommerce exists and cannot ask for the page.
 * Change the slug there too or trade partners cannot reset a password.
 */
class B2BAccountPage {

	/** Option holding the B2B account page id, so the id is not hard-coded. */
	public const OPTION = 'beltoft_commerce_b2b_account_page_id';

	/** Kept in step with the literal in Domain\LoginGate::allowlist(). */
	public const PATH = '/conta/';

	/**
	 * Where the account area has lived before.
	 *
	 * Reset links already in partners' inboxes point at this, and so do
	 * bookmarks. The move left no _wp_old_slug behind - WordPress records one
	 * for a slug change, not a re-parent - so the forwarding is done here.
	 */
	public const LEGACY_PATHS = array( '/conta-b2b/' );

	public static function register(): void {
		add_filter( 'woocommerce_get_myaccount_page_id', array( __CLASS__, 'page_id' ) );
		add_action( 'template_redirect', array( __CLASS__, 'forward_legacy_path' ), 1 );
	}

	/**
	 * Send the old account URLs to the new ones, endpoint and query intact.
	 *
	 * Priority 1 so it runs before anything that would render a 404 page, and
	 * a 301 because the move is permanent - a reset link forwarded with its
	 * ?key= and ?login= still works, which is the whole point.
	 */
	public static function forward_legacy_path(): void {
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		foreach ( self::LEGACY_PATHS as $legacy ) {
			if ( '' === $path || 0 !== strpos( $path, $legacy ) ) {
				continue;
			}

			// Whatever followed the old base - an endpoint, an order id, nothing.
			$target = home_url( self::PATH . substr( $path, strlen( $legacy ) ) );
			$query  = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY );

			if ( '' !== $query ) {
				$target .= '?' . $query;
			}

			wp_safe_redirect( $target, 301 );
			exit;
		}
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

		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}
}
