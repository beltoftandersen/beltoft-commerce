<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Support\Hosts;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Caches the rendered product rows that Bricks returns over REST.
 *
 * Every filter, sort, search and page change on the trade ordering list is a
 * POST to bricks/v1/query_result, which comes back with the rows as a single
 * block of HTML - about 75KB and 370ms for twenty products. That is most of
 * what a partner waits for when they tick a category.
 *
 * WHY THIS WORKS WHERE THE PAGE-RENDER CACHE DID NOT. Bricks renders a page's
 * elements flat and assembles them afterwards, so no element's output contains
 * the rows - an earlier attempt to cache the list in the page found a container
 * holding 1,202 bytes and no products. This endpoint has no such problem: it
 * exists precisely to hand back the rows as one string, so there is one thing
 * to store and one thing to serve.
 *
 * WHY IT IS SAFE ACROSS PEOPLE. The rows carry no nonce, no user id, no cart
 * reference and no session token - checked against the rendered output before
 * this was written. What varies is the pricing group, and that is in the key.
 * The cart is not in this payload at all, so the mistake that would leak one
 * partner's basket to another is not available to make here.
 *
 * INVALIDATION is a version bumped by anything that could change a row, so a
 * bump orphans every old entry at once, plus a short TTL so a change nobody
 * hooked still corrects itself within minutes rather than never.
 */
class QueryResultCache {

	private const ROUTE = '/bricks/v1/query_result';

	private const VERSION_OPTION = 'beltoft_commerce_rows_version';

	/** Backstop for any invalidation nobody remembered to hook. */
	private const TTL = 300;

	/** Key for the request currently being served, if it is cacheable. */
	private static string $pending = '';

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'serve' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'store' ), 10, 3 );

		foreach ( array(
			'woocommerce_update_product',
			'woocommerce_new_product',
			'woocommerce_variation_set_stock',
			'woocommerce_product_set_stock',
			'set_object_terms',
			// The rows carry group prices. They change by meta write (imports
			// never save the product) and when a group sale opens or closes;
			// SaleScheduler announces both here.
			'beltoft_commerce_group_price_changed',
		) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump' ) );
		}
	}

	public static function bump(): void {
		update_option( self::VERSION_OPTION, (string) ( time() . wp_rand( 100, 999 ) ), false );
	}

	private static function version(): string {
		$v = (string) get_option( self::VERSION_OPTION, '' );

		if ( '' === $v ) {
			$v = (string) time();
			update_option( self::VERSION_OPTION, $v, false );
		}

		return $v;
	}

	/**
	 * @param mixed            $result
	 * @param mixed            $server
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public static function serve( $result, $server = null, $request = null ) {
		self::$pending = '';

		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$key = self::key( $request );

		if ( '' === $key ) {
			return $result;
		}

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			$response = new WP_REST_Response( $cached, 200 );
			$response->header( 'X-Beltoft-Rows-Cache', 'hit' );

			return $response;
		}

		self::$pending = $key;

		return $result;
	}

	/**
	 * @param mixed $response
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public static function store( $response, $server = null, $request = null ) {
		if ( '' === self::$pending || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$key           = self::$pending;
		self::$pending = '';

		if ( 200 !== $response->get_status() ) {
			return $response;
		}

		$data = $response->get_data();

		// Only keep a render that actually produced markup; an empty one would
		// pin a broken result in place for the life of the entry.
		if ( is_array( $data ) && ! empty( $data['html'] ) ) {
			set_transient( $key, $data, self::TTL );
			$response->header( 'X-Beltoft-Rows-Cache', 'miss' );
		}

		return $response;
	}

	/**
	 * What makes two row renders the same render, or '' if it must not be kept.
	 */
	private static function key( WP_REST_Request $request ): string {
		if ( self::ROUTE !== $request->get_route() || ! Hosts::is_b2b_host() ) {
			return '';
		}

		if ( ! apply_filters( 'beltoft_commerce_rows_cache', true ) ) {
			return '';
		}

		$body = (array) $request->get_json_params();

		if ( empty( $body['queryElementId'] ) ) {
			return '';
		}

		$group = PricingContext::for_user();

		if ( '' === $group ) {
			return '';
		}

		/*
		 * Everything the rendered rows can depend on. The nonce is deliberately
		 * not part of it: it is per session and would make the cache per person,
		 * which is the one thing this must not be.
		 */
		$vary = array(
			'group'    => $group,
			'post'     => $body['postId'] ?? 0,
			'element'  => $body['queryElementId'],
			'filters'  => $body['filters'] ?? array(),
			'selected' => $body['selectedFilters'] ?? array(),
			'page'     => $body['pageFilters'] ?? array(),
			'infinite' => $body['infinitePage'] ?? 1,
			'base'     => $body['baseUrl'] ?? '',
			'lang'     => $body['lang'] ?? '',
			'vars'     => $body['originalQueryVars'] ?? '',
		);

		return 'blt_rows_' . self::version() . '_' . md5( (string) wp_json_encode( $vary ) );
	}
}
