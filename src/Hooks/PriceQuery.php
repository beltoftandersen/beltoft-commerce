<?php

namespace BeltoftCommerce\Hooks;

use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Routes price-derived queries at the group lookup index instead of
 * wc_product_meta_lookup, which holds retail values.
 *
 * This covers every price-derived operation, not just the frontend widget:
 * catalog orderby=price, the price filter widget, min_price/max_price query
 * args, and the Store API parameters that expose the same thing
 * independently of the theme. Architecture §2.6.
 *
 * Retail short-circuits, so guest queries are untouched and the cached guest
 * bucket is unchanged.
 */
class PriceQuery {

	public static function register(): void {
		add_action( 'pre_get_posts', array( __CLASS__, 'take_price_bounds' ), 30 );
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 20, 2 );
		add_filter( 'woocommerce_enable_post_clause_filtering', array( __CLASS__, 'drop_retail_price_clause' ), 10, 2 );
	}

	private const MIN = 'beltoft_min_price';
	private const MAX = 'beltoft_max_price';

	/**
	 * For a trade session, the price bounds become this class's, in euros.
	 *
	 * WooCommerce and the Store API each add their own clause on the RETAIL
	 * lookup table for min_price/max_price, so a trade filter used to be
	 * judged on retail prices as well - and the Store API sends the bounds in
	 * cents (minor units), which this class read as euros. The bounds are
	 * taken off the query here, converted, and applied to the group table
	 * only.
	 *
	 * @param mixed $query
	 */
	public static function take_price_bounds( $query ): void {
		if ( ! $query instanceof \WP_Query || Registry::inherits_prices( PricingContext::current() ) ) {
			return;
		}

		$post_type = (array) $query->get( 'post_type' );

		if ( ! in_array( 'product', $post_type, true ) && ! ( $query->is_main_query() && ( $query->is_post_type_archive( 'product' ) || $query->is_tax( get_object_taxonomies( 'product' ) ) ) ) ) {
			return;
		}

		$store_api = function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
		$scale     = $store_api ? 10 ** wc_get_price_decimals() : 1;

		foreach ( array( 'min_price' => self::MIN, 'max_price' => self::MAX ) as $var => $own ) {
			$value = $query->get( $var );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a shop filter, read only.
			if ( ( '' === $value || null === $value ) && $query->is_main_query() && isset( $_GET[ $var ] ) && is_scalar( $_GET[ $var ] ) ) {
				$value = wp_unslash( $_GET[ $var ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			if ( '' === $value || null === $value || false === $value || ! is_numeric( $value ) ) {
				continue;
			}

			$query->set( $own, (float) $value / $scale );
			$query->set( $var, '' );
		}
	}

	/**
	 * WooCommerce's own ?min_price= clause (classic shop) reads $_GET, not
	 * the query: switched off for trade sessions, whose bounds are applied to
	 * the group table instead.
	 *
	 * @param mixed     $enable
	 * @param \WP_Query $query
	 * @return mixed
	 */
	public static function drop_retail_price_clause( $enable, $query = null ) {
		if ( ! $enable || Registry::inherits_prices( PricingContext::current() ) ) {
			return $enable;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ) ? false : $enable;
	}

	public static function clauses( $clauses, $query ) {
		global $wpdb;

		if ( ! self::applies( $query ) ) {
			return $clauses;
		}

		// No table yet (see Support\Installer): sort and filter as WooCommerce
		// would rather than join a table that is not there.
		if ( ! LookupIndex::table_exists() ) {
			return $clauses;
		}

		$group = PricingContext::current();
		$table = LookupIndex::table();

		// LEFT JOIN, not INNER: a product with no row for this group must
		// still be queryable, it simply has no price to sort or filter on.
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$table} AS beltoft_price ON beltoft_price.product_id = {$wpdb->posts}.ID AND beltoft_price.price_group = %s ",
			$group
		);

		$orderby = $query->get( 'orderby' );

		if ( 'price' === $orderby ) {
			$order              = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
			// Rows with no group price sort last in either direction, so an
			// unpriced product never leads a B2B listing.
			$clauses['orderby'] = "beltoft_price.min_price IS NULL ASC, beltoft_price.min_price {$order}, {$wpdb->posts}.ID {$order}";
		}

		$min = $query->get( self::MIN );
		$max = $query->get( self::MAX );

		if ( '' !== $min && null !== $min && false !== $min ) {
			$clauses['where'] .= $wpdb->prepare( ' AND beltoft_price.max_price >= %f ', (float) $min );
		}

		if ( '' !== $max && null !== $max && false !== $max ) {
			$clauses['where'] .= $wpdb->prepare( ' AND beltoft_price.min_price <= %f ', (float) $max );
		}

		return $clauses;
	}

	private static function applies( $query ): bool {
		if ( ! $query instanceof \WP_Query ) {
			return false;
		}

		if ( Registry::inherits_prices( PricingContext::current() ) ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_array( $post_type ) ? $post_type : array( $post_type );

		if ( ! in_array( 'product', $post_type, true ) ) {
			return false;
		}

		$needs_price = 'price' === $query->get( 'orderby' )
			|| '' !== (string) $query->get( self::MIN )
			|| '' !== (string) $query->get( self::MAX );

		return $needs_price;
	}
}
