<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * User → pricing group.
 *
 * Resolution runs User → PricingContext → group → PriceResolver. This class
 * answers only "which commercial terms apply", never "what may this user
 * access" — that is CatalogAudience. See architecture §2.2.
 */
class PricingContext {

	public const OVERRIDE_META = '_beltoft_pricing_group';

	public static function current(): string {
		return self::for_user( get_current_user_id() );
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

	public static function is_retail( ?int $user_id = null ): bool {
		return Registry::retail_slug() === self::for_user( $user_id );
	}
}
