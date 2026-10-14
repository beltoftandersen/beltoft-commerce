<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * A price lookup table keyed by (product, pricing group).
 *
 * wc_product_meta_lookup backs price sorting and price filtering and holds
 * retail values, so any price-derived query run for a B2B session sorts or
 * filters against the wrong numbers. This mirrors group prices into a table
 * this extension owns outright. Architecture §2.6.
 *
 * WooCommerce treats wc_product_meta_lookup as internal and regenerates it
 * on upgrade, so adding columns there would make every WooCommerce release a
 * compatibility problem. A separate table avoids that.
 *
 * Fail-closed applies here too: a product with no price for a group gets NO
 * ROW, so it is absent from that group's price-sorted and price-filtered
 * listings rather than appearing at retail values.
 */
class LookupIndex {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'product_group_price_lookup';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				product_id  BIGINT UNSIGNED NOT NULL,
				price_group VARCHAR(32) NOT NULL,
				min_price   DECIMAL(19,4) NULL DEFAULT NULL,
				max_price   DECIMAL(19,4) NULL DEFAULT NULL,
				PRIMARY KEY  (product_id, price_group),
				KEY price_group_min (price_group, min_price),
				KEY price_group_max (price_group, max_price)
			) {$collate};"
		);
	}

	/**
	 * Whether the table is present. Cached per request: a price import
	 * writes thousands of meta rows and each one reaches this check.
	 *
	 * Without this guard, an install where the table has not been created
	 * yet (plugin activated before install() ran, or a failed migration)
	 * emits a database error on every single price write.
	 */
	public static function table_exists(): bool {
		global $wpdb;

		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		$table  = self::table();
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists;
	}

	public static function register(): void {
		add_action( 'beltoft_commerce_group_price_changed', array( __CLASS__, 'on_price_changed' ), 10, 2 );
	}

	public static function on_price_changed( int $product_id, $parent_id = 0 ): void {
		self::update_for_product( $parent_id ? (int) $parent_id : $product_id );
	}

	/**
	 * Writes one row per registered group, or deletes the row when the
	 * group has no price for this product.
	 */
	public static function update_for_product( int $product_id ): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		foreach ( array_keys( Registry::groups() ) as $group ) {
			$prices = self::prices_for( $product, (string) $group );

			if ( ! $prices ) {
				$wpdb->delete(
					self::table(),
					array( 'product_id' => $product_id, 'price_group' => $group ),
					array( '%d', '%s' )
				);

				continue;
			}

			$wpdb->replace(
				self::table(),
				array(
					'product_id'  => $product_id,
					'price_group' => $group,
					'min_price'   => min( $prices ),
					'max_price'   => max( $prices ),
				),
				array( '%d', '%s', '%f', '%f' )
			);
		}
	}

	/**
	 * @return float[]
	 */
	private static function prices_for( \WC_Product $product, string $group ): array {
		$prices = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );

				if ( ! $child ) {
					continue;
				}

				$price = PriceResolver::resolve( $child, $group );

				if ( null !== $price ) {
					$prices[] = (float) $price;
				}
			}

			return $prices;
		}

		$price = PriceResolver::resolve( $product, $group );

		return null === $price ? array() : array( (float) $price );
	}

	/**
	 * Rebuilds the whole index. Returns the number of products processed.
	 */
	public static function backfill(): int {
		if ( ! self::table_exists() ) {
			return 0;
		}

		$ids = wc_get_products( array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => array( 'publish', 'private' ),
		) );

		foreach ( $ids as $id ) {
			self::update_for_product( (int) $id );
		}

		return count( $ids );
	}
}
