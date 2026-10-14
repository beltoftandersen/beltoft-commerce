<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A variation's SKU and GTIN are indexed onto its parent product.
 *
 * THE PROBLEM. Relevanssi indexes the `product` post type only, so codes stored
 * on `product_variation` posts were unfindable - 257 variation SKUs and a
 * similar number of GTINs, every one of them a code a buyer might read off an
 * invoice or a carton and type in.
 *
 * WHY NOT JUST INDEX VARIATIONS. Adding `product_variation` to the indexed post
 * types would make the codes findable and the results useless: the hit would be
 * a variation, which has no catalog page of its own, is excluded by the audience
 * tax_query, and cannot be added to the cart from a list. The buyer would search
 * a code they can read and get a result they cannot buy.
 *
 * SO THE CODES MOVE, NOT THE POSTS. Each variable product's indexed content
 * gains its children's codes, and a search for any of them returns the parent -
 * the thing that is purchasable, priced for the viewer's group, and already
 * handled by every enforcement rule.
 *
 * NOT HOST-GATED, because indexing runs from CLI and cron where there is no
 * request host, and the index is shared by both stores anyway. The retail shop
 * gains the same behaviour, which is an improvement there too.
 */
class VariationCodes {

	private const CODE_KEYS = array( '_sku', '_global_unique_id' );

	public static function register(): void {
		add_filter( 'relevanssi_content_to_index', array( __CLASS__, 'add_variation_codes' ), 10, 2 );
	}

	/**
	 * @param string   $content Additional content Relevanssi will index.
	 * @param \WP_Post $post    The post being indexed.
	 * @return string
	 */
	public static function add_variation_codes( $content, $post = null ) {
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return $content;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $content;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return $content;
		}

		$codes = array();

		foreach ( $product->get_children() as $variation_id ) {
			foreach ( self::CODE_KEYS as $key ) {
				$value = trim( (string) get_post_meta( $variation_id, $key, true ) );

				if ( '' !== $value ) {
					$codes[] = $value;
				}
			}
		}

		if ( empty( $codes ) ) {
			return $content;
		}

		return trim( $content . ' ' . implode( ' ', array_unique( $codes ) ) );
	}
}
