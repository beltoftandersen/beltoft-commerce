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
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 20, 2 );
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

		$min = $query->get( 'min_price' );
		$max = $query->get( 'max_price' );

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
			|| '' !== (string) $query->get( 'min_price' )
			|| '' !== (string) $query->get( 'max_price' );

		return $needs_price;
	}
}
