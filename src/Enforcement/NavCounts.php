<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Category product counts and empty-category suppression.
 *
 * Counts are stored globally per term and reflect the whole catalog, so for
 * a restricted session they would be wrong. They are hidden rather than
 * recalculated — recalculating per group on every archive render is a cost
 * the architecture deliberately declines. Architecture §2.8.
 */
class NavCounts {

	public static function register(): void {
		add_filter( 'woocommerce_subcategory_count_html', array( __CLASS__, 'filter_count' ) );
		add_filter( 'wp_list_categories', array( __CLASS__, 'strip_counts' ) );
	}

	public static function filter_count( $html ) {
		return CatalogAudience::restricts_current_request() ? '' : $html;
	}

	public static function strip_counts( $output ) {
		if ( ! CatalogAudience::restricts_current_request() || ! is_string( $output ) ) {
			return $output;
		}

		return preg_replace( '/\s*\(\d+\)/', '', $output );
	}
}
