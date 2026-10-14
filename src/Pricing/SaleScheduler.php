<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps group prices fresh in two situations the storefront cannot see on
 * its own. Architecture §2.5.
 *
 * 1. A write to any group price field must invalidate that product's
 *    variation price transients. The meta write itself is hooked, not the
 *    admin save action, because prices arrive from an import that never
 *    touches the admin screen.
 *
 * 2. A scheduled sale starting or ending is a price change with no write
 *    behind it. Core handles this for retail; the group sale fields need
 *    the same, or a B2B campaign appears late and lingers.
 */
class SaleScheduler {

	public const BOUNDARY_HOOK = 'beltoft_commerce_group_sale_boundary';

	public static function register(): void {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_write' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_write' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_write' ), 10, 3 );

		add_action( self::BOUNDARY_HOOK, array( __CLASS__, 'run_boundary' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::BOUNDARY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::BOUNDARY_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::BOUNDARY_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::BOUNDARY_HOOK );
		}
	}

	public static function is_group_price_key( string $meta_key ): bool {
		return in_array( $meta_key, Registry::all_price_keys(), true );
	}

	/**
	 * @param int|array $meta_id
	 */
	public static function on_meta_write( $meta_id, $object_id, $meta_key ): void {
		if ( ! is_string( $meta_key ) || ! self::is_group_price_key( $meta_key ) ) {
			return;
		}

		self::invalidate( (int) $object_id );
	}

	/**
	 * Invalidates the product itself and, for a variation, its parent —
	 * the parent holds the cached price range.
	 */
	public static function invalidate( int $product_id ): void {
		$parent_id = wp_get_post_parent_id( $product_id );

		wc_delete_product_transients( $product_id );

		if ( $parent_id ) {
			wc_delete_product_transients( $parent_id );
		}

		/**
		 * Fires when a group price changes, so other caches (page cache,
		 * builder query loops) can drop what they hold for this product.
		 */
		do_action( 'beltoft_commerce_group_price_changed', $product_id, $parent_id );
	}

	/**
	 * Drops cached prices for every product whose group sale window has
	 * just opened or closed.
	 */
	public static function run_boundary(): void {
		global $wpdb;

		$keys = array();

		foreach ( array_keys( Registry::groups() ) as $slug ) {
			$keys[] = Registry::sale_from_key( $slug );
			$keys[] = Registry::sale_to_key( $slug );
		}

		if ( ! $keys ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// Any product carrying a group sale date at all. The set is small
		// (only scheduled campaigns) so this is cheaper than reasoning
		// about which boundary has just been crossed.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''",
				$keys
			)
		);

		foreach ( $ids as $id ) {
			self::invalidate( (int) $id );
		}
	}
}
