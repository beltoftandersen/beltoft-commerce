<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks' AJAX popup endpoint, held to the catalogue rules.
 *
 * /wp-json/bricks/v1/load_popup_content renders a popup template - the trade
 * quick view among them - with any post id the browser names as its context
 * (postId, popupContextId, or the id at the end of popupLoopId). Bricks checks
 * its public nonce and that the popup is a published AJAX popup, then loads the
 * post with get_post(), whatever its status or audience. An anonymous visitor
 * on retail got the name, codes, images, trade price and description of a
 * private B2B-only product that way, and a trade session any retail-only one.
 *
 * Each id must be a post the visitor may read and, for a product or variation,
 * one in their audience; otherwise the request is answered as if it named
 * nothing.
 */
class BricksGuard {

	private const ROUTE = '/bricks/v1/load_popup_content';

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard' ), 10, 3 );
	}

	/**
	 * @param mixed            $result
	 * @param mixed            $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function guard( $result, $server = null, $request = null ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || self::ROUTE !== untrailingslashit( strtolower( $request->get_route() ) ) ) {
			return $result;
		}

		foreach ( self::context_ids( $request ) as $id ) {
			if ( ! self::may_see( $id ) ) {
				return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message.
			}
		}

		return $result;
	}

	/** @return int[] Every post id the request could render as its context. */
	public static function context_ids( \WP_REST_Request $request ): array {
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : (array) $request->get_params();
		$ids  = array();

		foreach ( array( 'postId', 'popupContextId' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$ids[] = (int) $data[ $key ];
			}
		}

		// Bricks takes the quick view's product from the loop id, "a:b:c:<id>".
		if ( isset( $data['popupLoopId'] ) && is_string( $data['popupLoopId'] ) ) {
			$parts = explode( ':', $data['popupLoopId'] );
			$ids[] = (int) end( $parts );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function may_see( int $id ): bool {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return true; // Nothing to render; Bricks answers that itself.
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $id ) ) {
			return false;
		}

		if ( in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return CatalogAudience::can_view( get_current_user_id(), $id );
		}

		return true;
	}
}
