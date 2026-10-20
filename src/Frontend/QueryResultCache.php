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
			// The rows carry group prices. They change by meta write (imports
			// never save the product) and when a group sale opens or closes;
			// SaleScheduler announces both here.
			'beltoft_commerce_group_price_changed',
		) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump' ) );
		}

		// Terms: only a product's. This fired for every post, order and menu
		// item saved anywhere on the site, each one an options-table write.
		add_action( 'set_object_terms', array( __CLASS__, 'bump_on_terms' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'bump_on_terms' ) );

		/*
		 * A product leaving or entering the list without a WooCommerce save:
		 * trashed or restored from the products screen (a simple product has
		 * no variations to sync), deleted, or published/unpublished directly.
		 */
		foreach ( array( 'trashed_post', 'untrashed_post', 'delete_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump_on_terms' ) );
		}

		add_action( 'transition_post_status', array( __CLASS__, 'bump_on_status' ), 10, 3 );
	}

	/**
	 * @param string   $new
	 * @param string   $old
	 * @param \WP_Post $post
	 */
	public static function bump_on_status( $new, $old, $post ): void {
		if ( $new !== $old && $post instanceof \WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			self::bump();
		}
	}

	/** Whether this request changed something the rows show. */
	private static bool $dirty = false;

	/**
	 * Mark the rows stale; they are dropped once, at the end of the request.
	 *
	 * A product save fires several of these hooks, and a retail order fires
	 * one per line as stock goes down - each was an options-table write. At
	 * shutdown every change the request made is in place, so one new version
	 * covers them all.
	 */
	public static function bump(): void {
		if ( ! self::$dirty ) {
			self::$dirty = true;
			// Last: WooCommerce syncs variable parents at shutdown 10, and the
			// save that sync makes is a change too.
			add_action( 'shutdown', array( __CLASS__, 'commit' ), PHP_INT_MAX - 1 );
		}
	}

	/** Write the new version now if anything is pending (shutdown; tests and CLI may call it). */
	public static function commit(): void {
		if ( ! self::$dirty ) {
			return;
		}

		self::$dirty = false;

		// Always a new value: time() plus a random suffix could repeat within a
		// second, and an unchanged option drops nothing.
		$current = (int) get_option( self::VERSION_OPTION, 0 );
		update_option( self::VERSION_OPTION, (string) max( (int) round( microtime( true ) * 1000 ), $current + 1 ), false );
	}

	/**
	 * @param mixed  $object_id
	 * @param mixed  $terms
	 * @param mixed  $tt_ids
	 * @param string $taxonomy
	 */
	public static function bump_on_terms( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ): void {
		if ( in_array( get_post_type( (int) $object_id ), array( 'product', 'product_variation' ), true ) ) {
			self::bump();
		}
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

		// The group the rows are priced in (PricingContext::current()), not merely the account's.
		$group = PricingContext::current();

		if ( '' === $group ) {
			return '';
		}

		/*
		 * Not for anyone who sees more than their group does. The rows are
		 * rendered with the requester's capabilities, and the query includes
		 * private products for staff who may read them: cached under the
		 * group key, a shop manager's rows reached customers in that group.
		 */
		if ( current_user_can( 'read_private_products' ) ) {
			return '';
		}

		/*
		 * A hit is answered before Bricks runs its own permission check, which
		 * is this nonce; so it is checked here.
		 */
		if ( ! wp_verify_nonce( (string) ( $body['nonce'] ?? '' ), 'bricks-nonce' ) ) {
			return '';
		}

		/*
		 * The whole request, except the nonce. It used to be a list of the
		 * fields known to matter, and Bricks sends more than that list held:
		 * urlParams (element conditions, {url_parameter} tags), afTags,
		 * mainQueryId, activeSearchTemplate (replaces the query), componentId.
		 * Two requests differing only there shared one entry - one buyer got
		 * rows rendered for another's URL, and a crafted body could poison the
		 * entry the whole group reads. Keying on everything means a field Bricks
		 * adds later is covered too.
		 *
		 * The nonce stays out: it is per session and would make the cache per
		 * person, which is the one thing this must not be.
		 */
		unset( $body['nonce'] );
		ksort( $body );

		/*
		 * And the language the rows are rendered in. On a REST request a
		 * language plugin (Zerolang) takes it from the Referer, so one
		 * partner's English rows - English names, /en/ links - were served to
		 * every partner in the group.
		 */
		$vary = array(
			'group'    => $group,
			'body'     => $body,
			'language' => class_exists( '\Zerolang\Request' ) ? (string) \Zerolang\Request::current() : '',
			'locale'   => determine_locale(),
		);

		return 'blt_rows_' . self::version() . '_' . md5( (string) wp_json_encode( $vary ) );
	}
}
