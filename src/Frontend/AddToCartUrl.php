<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Repair the add-to-cart link on rows that were rendered inside an AJAX request.
 *
 * WC_Product_Simple::add_to_cart_url() builds its link by calling
 * add_query_arg() with no base (class-wc-product-simple.php:44), and
 * add_query_arg() with no base means "the URL of the current request". On a
 * normal page view that is exactly right - the link adds the product and puts
 * the buyer back where they were.
 *
 * The ordering list, however, is re-rendered server side inside Bricks' REST
 * endpoint: on every pagination click, on every filter change, and - since
 * Frontend\TwoPhaseList - for the first paint as well. "The URL of the current
 * request" is then /wp-json/bricks/v1/query_result, so every button in the
 * returned markup carried
 *
 *     href="/wp-json/bricks/v1/query_result?add-to-cart=172371"
 *
 * which answers a JSON 404 (`rest_no_route`: the route exists, GET with a stray
 * query string does not match it) instead of adding anything to the cart.
 *
 * WHY IT WAS INVISIBLE. Woo's own add-to-cart.js calls preventDefault() on
 * these buttons and posts to wc-ajax instead, so the href is never followed -
 * until a buyer taps a row before that script has finished loading. The rows
 * are painted early on purpose now, which makes that gap easy to hit on a
 * phone, and the href is what the browser falls back to. A wrong href is
 * therefore not cosmetic: it is the no-JS and not-JS-yet path, and it has to
 * work on its own.
 *
 * The fix does not touch the timing. It makes the link correct, so following it
 * adds the product and lands on the list - a slower add, but the right one.
 */
class AddToCartUrl {

	/**
	 * The page the markup is being rendered for, as Bricks reported it.
	 *
	 * Bricks sends the address of the page whose list is being re-queried, which
	 * is a better answer than any guess this class could make: the same loop is
	 * used on more than one page.
	 */
	private static ?string $base = null;

	public static function register(): void {
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'repair' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'capture' ), 10, 3 );
	}

	/**
	 * Remember the rendering page before the route runs.
	 *
	 * @param mixed           $result  Untouched, always.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function capture( $result, $server = null, $request = null ) {
		if ( $request instanceof WP_REST_Request ) {
			$raw = $request->get_param( 'baseUrl' );

			/*
			 * Assigned either way, so a dispatch that reports no page cannot
			 * inherit the one before it. Routes can be dispatched more than once
			 * in a request, and a stale base is a wrong link rather than a
			 * missing one - the worse of the two failures.
			 */
			self::$base = is_string( $raw ) && '' !== $raw ? $raw : null;
		}

		return $result;
	}

	/**
	 * @param mixed $url     The URL WooCommerce built.
	 * @param mixed $product The product it belongs to.
	 * @return mixed
	 */
	public static function repair( $url, $product = null ) {
		if ( ! is_string( $url ) || '' === $url || ! self::applies() ) {
			return $url;
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		// No query string means a plain permalink, which is already absolute.
		if ( '' === $query ) {
			return $url;
		}

		$args = array();
		wp_parse_str( $query, $args );

		// Only a link that actually adds something is this class's business.
		if ( ! isset( $args['add-to-cart'] ) ) {
			return $url;
		}

		/*
		 * Only the add-to-cart intent travels. The rest of that query string
		 * belongs to the AJAX route - element ids, nonces, a whole serialised
		 * query - and carrying any of it onto a page URL would be nonsense at
		 * best and a cached nonce at worst.
		 */
		$keep = array_intersect_key(
			$args,
			array_flip( array( 'add-to-cart', 'quantity', 'variation_id' ) )
		);

		return add_query_arg( $keep, self::display_page() );
	}

	/**
	 * The page URL to hang the add-to-cart arguments on.
	 */
	private static function display_page(): string {
		$path = self::reported_path();

		return '' === $path
			? home_url( QuickOrderPagePathShim::ordering_path() )
			: home_url( $path );
	}

	/**
	 * The path Bricks reported, and nothing else from it.
	 *
	 * baseUrl arrives in a request body, so it is a value a client chose. Only
	 * the path is taken and it is re-hung on home_url(), which makes the scheme
	 * and the host this site's own no matter what was sent - there is no
	 * off-site URL this can be talked into producing, and nothing to validate.
	 */
	private static function reported_path(): string {
		if ( null === self::$base ) {
			return '';
		}

		$path = (string) wp_parse_url( self::$base, PHP_URL_PATH );

		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '..' ) ) {
			return '';
		}

		// A REST path is the bug, not a destination.
		if ( 0 === strpos( ltrim( $path, '/' ), rest_get_url_prefix() . '/' ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Trade host, and only while rendering something other than a page view.
	 *
	 * On a real page view WooCommerce's own answer is the right one and this
	 * class must keep its hands off it.
	 */
	private static function applies(): bool {
		return Hosts::is_b2b_host()
			&& (
				( defined( 'REST_REQUEST' ) && REST_REQUEST )
				|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			);
	}
}
