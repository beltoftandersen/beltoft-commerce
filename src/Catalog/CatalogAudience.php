<?php

namespace BeltoftCommerce\Catalog;

use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The single catalog access policy.
 *
 * Access control gets the same treatment as pricing: one canonical service,
 * not a policy re-implemented at each hook. Every enforcement point in §2.8
 * consults this rather than testing terms itself, so changing the audience
 * model changes one file.
 *
 * Listing and reachability are different problems: the tax_query controls
 * what appears in a list, and can_view controls what can be fetched. Both
 * are required.
 */
class CatalogAudience {

	/**
	 * Answers already worked out during this request, keyed user:product.
	 *
	 * WHY THIS IS HERE. Enforcement\Purchasable answers WooCommerce's
	 * `woocommerce_is_purchasable` with can_purchase(), and is_purchasable() is
	 * asked many times while a single row renders - by the price, the stock,
	 * the add-to-cart control and Bricks' own elements. Each call rebuilt the
	 * product, and for a variable product every one of its variations too,
	 * because wc_get_product() has no per-request memory.
	 *
	 * Rendering 39 rows of the trade list constructed 2,523 product objects -
	 * one parent 369 times - for roughly 268,000 object-cache lookups and 1.1
	 * seconds of the 1.8 second response. Neither the audience terms nor the
	 * group prices can change while a page is being drawn, so each answer is
	 * worked out once.
	 *
	 * @var array<string,bool>
	 */
	private static array $view = array();

	/** @var array<string,bool> */
	private static array $purchase = array();

	/** @var array<int,\WC_Product|null> */
	private static array $products = array();

	/**
	 * Anything that could change an answer clears the memory.
	 *
	 * Long-running processes - imports, WP-CLI, the test suite - do change a
	 * product's terms or prices and then ask again in the same process, and a
	 * cache that outlived the truth would be worse than no cache.
	 */
	public static function register(): void {
		foreach ( array( 'set_object_terms', 'woocommerce_update_product', 'woocommerce_new_product', 'clean_post_cache' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		/*
		 * Who is asking changes the answer too: a role change, or the
		 * per-user group override, moves an account between catalogues; a
		 * group price written as plain meta (as imports do) changes what it
		 * may buy. Each answer is keyed user:product, so these also clear it.
		 */
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		foreach ( array( 'added', 'updated', 'deleted' ) as $verb ) {
			add_action( "{$verb}_user_meta", array( __CLASS__, 'flush_on_meta' ), 10, 3 );
			add_action( "{$verb}_post_meta", array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		}
	}

	/**
	 * @param mixed  $meta_id
	 * @param mixed  $object_id
	 * @param string $meta_key
	 */
	public static function flush_on_meta( $meta_id, $object_id, $meta_key ): void {
		$key = (string) $meta_key;

		if ( PricingContext::OVERRIDE_META === $key || 0 === strpos( $key, '_price_group_' ) || 0 === strpos( $key, '_sale_price_' ) ) {
			self::flush();
		}
	}

	public static function flush(): void {
		self::$view     = array();
		self::$purchase = array();
		self::$products = array();
		self::$hidden   = array();
	}

	/** @var array<string,int[]> Products outside each audience, keyed trade|retail. */
	private static array $hidden = array();

	/**
	 * The products a session may not see: for retail the B2B-only ones, for
	 * trade every product not in its audience (untagged ones included).
	 * For filters that need ids rather than a tax_query - see QueryFilter.
	 *
	 * @return int[]
	 */
	public static function hidden_product_ids( bool $trade ): array {
		$key = $trade ? 'trade' : 'retail';

		if ( isset( self::$hidden[ $key ] ) ) {
			return self::$hidden[ $key ];
		}

		global $wpdb;

		$slugs  = $trade ? Taxonomy::b2b_visible_slugs() : Taxonomy::retail_hidden_slugs();
		$tagged = $wpdb->prepare(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = %s AND t.slug IN (" . implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) . ')',
			array_merge( array( Taxonomy::TAXONOMY ), $slugs )
		);

		$ids = $trade
			? $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND ID NOT IN ( {$tagged} )" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
			: $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND ID IN ( {$tagged} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.

		return self::$hidden[ $key ] = array_map( 'intval', $ids );
	}

	/**
	 * Whether this request is staff managing the catalogue rather than a
	 * visitor shopping it: wp-admin and its AJAX, the authenticated REST API
	 * (not the Store API, which is the shop), WP-CLI. Such requests see the
	 * whole catalogue - the variations panel of a B2B-only product, an ERP
	 * reading upsells to write them back - since hiding products there loses
	 * data instead of protecting it.
	 */
	public static function serves_staff(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return false;
		}

		if ( is_admin() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$store_api = function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();

			return ! $store_api;
		}

		return false;
	}

	/** wc_get_product() with a per-request memory. Read-only use only. */
	private static function product( int $id ) {
		if ( ! array_key_exists( $id, self::$products ) ) {
			$product               = wc_get_product( $id );
			self::$products[ $id ] = $product instanceof \WC_Product ? $product : null;
		}

		return self::$products[ $id ];
	}

	public static function can_view( ?int $user_id, int $product_id ): bool {
		$key = (int) $user_id . ':' . $product_id;

		if ( isset( self::$view[ $key ] ) ) {
			return self::$view[ $key ];
		}

		return self::$view[ $key ] = self::compute_can_view( $user_id, $product_id );
	}

	private static function compute_can_view( ?int $user_id, int $product_id ): bool {
		$audience = Taxonomy::audience_of( $product_id );
		$group    = PricingContext::for_user( $user_id );

		if ( ! Registry::is_trade( $group ) ) {
			// Retail sessions see everything except B2B-only products.
			return ! in_array( $audience, Taxonomy::retail_hidden_slugs(), true );
		}

		return in_array( $audience, Taxonomy::b2b_visible_slugs(), true );
	}

	/**
	 * Purchasability is viewability plus a resolvable price for the user's
	 * group. This is where the fail-closed rule from §2.5 meets the
	 * audience rule: a product visible to a group but unpriced for it is
	 * not buyable, and must not fall back to retail.
	 */
	public static function can_purchase( ?int $user_id, int $product_id ): bool {
		$key = (int) $user_id . ':' . $product_id;

		if ( isset( self::$purchase[ $key ] ) ) {
			return self::$purchase[ $key ];
		}

		return self::$purchase[ $key ] = self::compute_can_purchase( $user_id, $product_id );
	}

	private static function compute_can_purchase( ?int $user_id, int $product_id ): bool {
		if ( ! self::can_view( $user_id, $product_id ) ) {
			return false;
		}

		$group = PricingContext::for_user( $user_id );

		if ( ! Registry::is_trade( $group ) ) {
			return true;
		}

		$product = self::product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = self::product( (int) $child_id );

				if ( $child && PriceResolver::has_price( $child, $group ) ) {
					return true;
				}
			}

			return false;
		}

		return PriceResolver::has_price( $product, $group );
	}

	/**
	 * Whether the current request needs any audience restriction at all.
	 * Retail short-circuits, so guest queries are untouched and the cached
	 * guest bucket is unchanged.
	 */
	public static function restricts_current_request(): bool {
		return Registry::is_trade( PricingContext::current() );
	}
}
