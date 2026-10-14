<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Export-time filtering for product feeds.
 *
 * A feed is generated outside the normal query path and can be built from
 * raw metadata, so it needs its own filter. Architecture §2.8 and §5.
 */
class FeedGuard {

	public static function register(): void {
		add_filter( 'beltoft_commerce_feed_product_ids', array( __CLASS__, 'filter_ids' ) );

		// Related products and cross-sells are built from their own query.
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_ids' ) );
		add_filter( 'woocommerce_cross_sell_ids', array( __CLASS__, 'filter_ids' ) );
		add_filter( 'woocommerce_upsell_ids', array( __CLASS__, 'filter_ids' ) );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function filter_ids( $ids ) {
		if ( ! is_array( $ids ) || ! CatalogAudience::restricts_current_request() ) {
			return $ids;
		}

		$user_id = get_current_user_id();

		return array_values( array_filter(
			$ids,
			function ( $id ) use ( $user_id ) {
				return CatalogAudience::can_view( $user_id, (int) $id );
			}
		) );
	}
}
