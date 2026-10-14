<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side audience enforcement on the Store API.
 *
 * The Store API is public and unauthenticated, and it exposes products
 * independently of the theme — hiding a widget does not close it. The
 * listing is already restricted by Enforcement\QueryFilter, but listing and
 * reachability are different problems: a direct fetch by id or slug bypasses
 * the listing entirely. That is where the leak lives. Architecture §2.8.
 *
 * This also closes the hole stage 1 deliberately left open: `/wp-json/` is
 * allowlisted through the B2B login gate so the Store API is reachable on
 * the B2B host, and server-side filtering is what actually restricts it.
 */
class StoreApiGuard {

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'guard_resolved' ), 10, 3 );
	}

	/**
	 * The same check, on the product WooCommerce actually resolved.
	 *
	 * guard() has to guess from the URL which product is meant, and the
	 * guess can be wrong: the slug route takes any non-space characters and
	 * runs them through sanitize_title(), so "slug/" or "slug." reach the
	 * product while failing guard()'s identifier pattern. Here the answer is
	 * already in the response, so nothing needs guessing. guard() stays as
	 * the cheap path that never builds the response at all.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 */
	public static function guard_resolved( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! self::is_single_product_route( $handler ) ) {
			return $response;
		}

		$data       = $response->get_data();
		$product_id = is_array( $data ) && isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( ! $product_id || CatalogAudience::can_view( get_current_user_id(), $product_id ) ) {
			return $response;
		}

		return self::not_found();
	}

	/** The Store API routes that return one product: by id and by slug. */
	private static function is_single_product_route( $handler ): bool {
		$callback = $handler['callback'] ?? null;
		$route    = is_array( $callback ) && is_object( $callback[0] ?? null ) ? get_class( $callback[0] ) : '';

		return in_array( $route, array(
			'Automattic\WooCommerce\StoreApi\Routes\V1\ProductsById',
			'Automattic\WooCommerce\StoreApi\Routes\V1\ProductsBySlug',
		), true );
	}

	private static function not_found(): \WP_Error {
		// 404, not 403: a 403 confirms the product exists.
		return new \WP_Error(
			'woocommerce_rest_product_invalid_id',
			__( 'Invalid product ID.', 'beltoft-commerce' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 */
	public static function guard( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$route = $request->get_route();

		if ( ! preg_match( '#^/wc/store/v\d+/products/(.+)$#', $route, $matches ) ) {
			return $result;
		}

		$identifier = $matches[1];

		// Sub-collections (categories, attributes, reviews, tags, brands,
		// collection-data) are not products.
		if ( ! preg_match( '#^[\w-]+$#', $identifier ) ) {
			return $result;
		}

		$product_id = self::resolve_product_id( $identifier );

		if ( ! $product_id ) {
			return $result;
		}

		if ( CatalogAudience::can_view( get_current_user_id(), $product_id ) ) {
			return $result;
		}

		return self::not_found();
	}

	private static function resolve_product_id( string $identifier ): int {
		if ( ctype_digit( $identifier ) ) {
			$product = wc_get_product( (int) $identifier );

			return $product ? (int) $product->get_id() : 0;
		}

		$id = wc_get_product_id_by_sku( $identifier );

		if ( $id ) {
			return (int) $id;
		}

		$post = get_page_by_path( $identifier, OBJECT, 'product' );

		return $post ? (int) $post->ID : 0;
	}
}
