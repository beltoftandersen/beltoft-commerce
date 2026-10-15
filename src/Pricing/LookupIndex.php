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

	/** Parents whose row is rebuilt at the end of the request. @var array<int,true> */
	private static array $pending = array();

	public static function register(): void {
		add_action( 'beltoft_commerce_group_price_changed', array( __CLASS__, 'on_price_changed' ), 10, 2 );

		/*
		 * A variable product's range also changes when a variation is added,
		 * disabled, trashed or deleted - none of which writes a group price,
		 * so the row kept the range of variations nobody could buy any more.
		 * WooCommerce re-syncs the parent at shutdown in each case; the row
		 * is rebuilt after that (see flush_pending()), once per parent.
		 */
		add_action( 'woocommerce_variable_product_sync_data', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_variation' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue_variation' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_variation' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ), 20 );
	}

	/** @param mixed $product */
	public static function queue_product( $product ): void {
		$id = $product instanceof \WC_Product ? $product->get_id() : (int) $product;

		if ( $id ) {
			self::$pending[ $id ] = true;
		}
	}

	/** @param mixed $variation_id */
	public static function queue_variation( $variation_id ): void {
		$variation_id = (int) $variation_id;

		if ( ! $variation_id || 'product_variation' !== get_post_type( $variation_id ) ) {
			return;
		}

		$parent = wp_get_post_parent_id( $variation_id );

		if ( $parent ) {
			self::$pending[ (int) $parent ] = true;
		}
	}

	/** Rebuild the queued rows. Runs at shutdown; callable directly (tests, CLI). */
	public static function flush_pending(): void {
		$ids           = array_keys( self::$pending );
		self::$pending = array();

		foreach ( $ids as $id ) {
			self::update_for_product( (int) $id );
		}
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

				// A disabled variation is not for sale; its price is no part of the range.
				if ( ! $child || 'publish' !== $child->get_status() ) {
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

		/*
		 * Every product, whoever is asking: this runs from cron, which has no
		 * user and so reads as retail, and the audience filter would leave the
		 * B2B-only products - the ones that most need a trade price row - out.
		 */
		$ids = get_posts( array(
			'post_type'               => 'product',
			'post_status'             => array( 'publish', 'private' ),
			'numberposts'             => -1,
			'fields'                  => 'ids',
			'beltoft_bypass_audience' => true,
		) );

		foreach ( $ids as $id ) {
			self::update_for_product( (int) $id );
		}

		return count( $ids );
	}
}
