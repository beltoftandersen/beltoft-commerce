<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * A product outside the session's audience is not reachable by direct URL.
 *
 * A genuine 404, not a redirect: a redirect confirms the product exists.
 * Listing and reachability are different problems — the query filter
 * controls what appears in a list, this controls what can be fetched.
 * Architecture §2.8.
 */
class SingleProduct {

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ), 5 );
	}

	/** Whether the product page being served is one this visitor may not see. */
	public static function should_404(): bool {
		if ( ! is_singular( 'product' ) ) {
			return false;
		}

		$product_id = get_queried_object_id();

		return $product_id && ! CatalogAudience::can_view( get_current_user_id(), (int) $product_id );
	}

	public static function maybe_404(): void {
		if ( ! self::should_404() ) {
			return;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		include get_query_template( '404' );
		exit;
	}
}
