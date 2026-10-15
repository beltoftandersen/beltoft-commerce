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

		/*
		 * Related products, upsells and cross-sells are built with
		 * wc_get_product() from stored ids, not a product WP_Query, so
		 * QueryFilter never sees them. The upsell and cross-sell hooks used to
		 * be woocommerce_upsell_ids / woocommerce_cross_sell_ids, which
		 * WooCommerce never fires; these are the getters' view-context filters
		 * (the product editor reads in edit context and still sees them all)
		 * and the cart's own cross-sell list.
		 */
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_ids' ) );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'filter_ids' ) );
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( __CLASS__, 'filter_ids' ) );
		add_filter( 'woocommerce_cart_crosssell_ids', array( __CLASS__, 'filter_ids' ) );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function filter_ids( $ids ) {
		/*
		 * Every session, retail included. Returning early outside trade sent
		 * B2B-only products to retail visitors as related products - on cached
		 * pages - and to any feed built from cron, which has no user.
		 */
		// Staff reading through the REST API or admin (an ERP round trip
		// writing upsells back) get them all; hiding some there deletes them.
		if ( ! is_array( $ids ) || CatalogAudience::serves_staff() ) {
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
