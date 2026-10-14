<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Search engines that hold their own copy of the content.
 *
 * Relevanssi (and Elasticsearch, if it arrives) maintain a separate index,
 * so pre_get_posts does not reach them. Filtering happens at query time
 * rather than at index time, because the index is shared with retail
 * sessions and must keep the full catalog. Architecture §2.8.
 */
class SearchIndex {

	public static function register(): void {
		add_filter( 'relevanssi_hits_filter', array( __CLASS__, 'filter_hits' ) );
	}

	public static function filter_hits( $hits ) {
		if ( ! CatalogAudience::restricts_current_request() ) {
			return $hits;
		}

		if ( ! is_array( $hits ) || ! isset( $hits[0] ) || ! is_array( $hits[0] ) ) {
			return $hits;
		}

		$user_id = get_current_user_id();

		$hits[0] = array_values( array_filter(
			$hits[0],
			function ( $hit ) use ( $user_id ) {
				$id = is_object( $hit ) ? ( $hit->ID ?? 0 ) : (int) $hit;

				if ( ! $id ) {
					return true;
				}

				if ( 'product' !== get_post_type( $id ) ) {
					return true;
				}

				return CatalogAudience::can_view( $user_id, (int) $id );
			}
		) );

		return $hits;
	}
}
