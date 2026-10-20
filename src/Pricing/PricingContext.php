<?php

namespace BeltoftCommerce\Pricing;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * User → pricing group, and the group this request quotes.
 *
 * Resolution runs User → PricingContext → group → PriceResolver. This class
 * answers only "which commercial terms apply", never "what may this user
 * access" — that is CatalogAudience. See architecture §2.2.
 *
 * TWO QUESTIONS. for_user() is the account's group: what the partner is,
 * wherever they are. current() and applicable() are the group this request
 * prices in, and since 2026-10-20 that depends on the host too (architecture
 * §2.3, amended): "a partner sees retail prices on retail hosts" (Christian).
 * On a front-end request - pages, the Store API, REST, admin-ajax and
 * admin-post called from a page - on any host that is not the trade host,
 * the answer is retail, whoever is signed in. Without a configured trade
 * host every host is a retail host. The catalogue follows the same answer
 * (CatalogAudience asks applicable()), and so does the cart (HostCart).
 *
 * The group still applies where there is no shop front to show it on:
 * - staff acting for a customer (act_for(): order lines added in wp-admin,
 *   orders written through the WooCommerce REST API) - wp-admin lives on
 *   the retail host;
 * - a wp-admin screen, cron, Action Scheduler's async runner and WP-CLI;
 * - any user other than the request's own (applicable( $other_id )).
 * Orders keep the prices they were placed at; nothing re-prices a stored line.
 */
class PricingContext {

	public const OVERRIDE_META = '_beltoft_pricing_group';

	/** A customer whose prices this request quotes in place of the signed-in user's. */
	private static ?int $acting_for = null;

	/** The group this request quotes prices in. */
	public static function current(): string {
		if ( null !== self::$acting_for ) {
			return self::for_user( self::$acting_for );
		}

		return self::applicable( get_current_user_id() );
	}

	/**
	 * The group a user is priced in on this request: retail for the visitor
	 * on a shop front that is not the trade host, the account's own group
	 * otherwise. Only the request's own user: judging someone else from a
	 * retail request (a back-in-stock email's recipient, a saved cart's
	 * owner) is about their account, not about this page.
	 */
	public static function applicable( ?int $user_id = null ): string {
		$visitor = null === $user_id || get_current_user_id() === (int) $user_id;

		return $visitor && self::is_retail_front() ? Registry::retail_slug() : self::for_user( $user_id );
	}

	/**
	 * Whether this is a shop-front request on a host other than the trade host.
	 *
	 * admin-ajax and admin-post count as the front end: is_admin() is true
	 * there, but the calls come from the shop's pages (fragments, filters,
	 * add to cart, the saved-cart forms). Asked on every price filter, so it
	 * reads constants, a global and one host comparison, nothing else.
	 */
	public static function is_retail_front(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || self::is_queue_runner() ) {
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() && 'admin-post.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}

		return ! Hosts::is_b2b_host();
	}

	/**
	 * Quote prices for another customer for the rest of this request (or until
	 * stop_acting()). For staff building an order for a partner in wp-admin:
	 * WooCommerce prices the line from get_price(), which otherwise answers for
	 * the shop manager - at retail.
	 */
	public static function act_for( int $user_id ): void {
		self::$acting_for = $user_id;
	}

	public static function stop_acting(): void {
		self::$acting_for = null;
	}

	/**
	 * Answers already worked out this request, by user id.
	 *
	 * WooCommerce asks for a price, a regular price, a sale price and whether
	 * a product is purchasable many times per product drawn, and each of those
	 * filters asks this first: on a till or retail page that was several
	 * hundred lookups of the same user's roles and meta per request. Cleared
	 * when a role or the override changes (see flush()).
	 *
	 * @var array<int,string>
	 */
	private static array $groups = array();

	public static function register(): void {
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'profile_update', 'clean_user_cache' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		foreach ( array( 'added', 'updated', 'deleted' ) as $verb ) {
			add_action( "{$verb}_user_meta", array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		}
	}

	public static function flush(): void {
		self::$groups = array();
	}

	/**
	 * @param mixed  $meta_id
	 * @param mixed  $user_id
	 * @param string $meta_key
	 */
	public static function flush_on_meta( $meta_id, $user_id, $meta_key ): void {
		if ( self::OVERRIDE_META === $meta_key ) {
			self::flush();
		}
	}

	public static function for_user( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! $user_id ) {
			return Registry::retail_slug();
		}

		if ( isset( self::$groups[ $user_id ] ) ) {
			return self::$groups[ $user_id ];
		}

		return self::$groups[ $user_id ] = self::resolve_for_user( $user_id );
	}

	private static function resolve_for_user( int $user_id ): string {

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return Registry::retail_slug();
		}

		// An explicit per-user group beats role mapping, for the one
		// customer who needs different terms without inventing a role.
		// Validated against the registry: this is user meta, and anything
		// able to write user meta could otherwise invent a group.
		$override = get_user_meta( $user_id, self::OVERRIDE_META, true );

		if ( is_string( $override ) && '' !== $override && Registry::has( $override ) ) {
			return $override;
		}

		return Roles::group_for_roles( (array) $user->roles );
	}

	/**
	 * Action Scheduler's async runner: a loopback admin-ajax request on the
	 * site address that runs queued background work (the notifier's
	 * back-in-stock emails among it). Background work, not a shop front.
	 */
	private static function is_queue_runner(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared; the runner checks its own nonce.
		// The request field first: wp_doing_ajax() runs a filter, and this is asked on every price filter.
		return isset( $_REQUEST['action'] ) && 'as_async_request_queue_runner' === $_REQUEST['action'] && wp_doing_ajax();
	}

	/** Whether the ACCOUNT is retail (its own group, not this request's). */
	public static function is_retail( ?int $user_id = null ): bool {
		return Registry::retail_slug() === self::for_user( $user_id );
	}
}
