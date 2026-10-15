<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Hosts;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The SKU and EAN follow the line into the checkout.
 *
 * The ordering list shows both against every row, because that is what a buyer
 * reads off a box to confirm they picked the right thing - and then the
 * checkout summary showed a name and a price and nothing to check against. On
 * a trade order of thirty lines, several of them variations of the same
 * product whose names differ by a word, the name alone is not enough to
 * approve an order by.
 *
 * WHY woocommerce_get_item_data AND NOT THE TEMPLATE. This is WooCommerce's own
 * hook for "extra facts about this line", the one variation attributes already
 * travel through, so anything that renders a cart line renders these too - the
 * Bricks order summary, the cart page, the emails' item meta. Nothing here
 * knows or cares which of those is asking.
 *
 * EAN comes from whichever key holds it. The catalogue was imported more than
 * once: some products carry WooCommerce core's `_global_unique_id`, others the
 * `_alg_ean` its previous plugin wrote. Reading both is not defensive
 * programming, it is the actual state of the data.
 */
class ItemCodes {

	/** Where an EAN might live, best first. */
	private const EAN_KEYS = array( '_global_unique_id', '_alg_ean' );

	public static function register(): void {
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'add' ), 10, 2 );
	}

	/**
	 * @param mixed $data
	 * @param mixed $item
	 * @return mixed
	 */
	public static function add( $data, $item = null ) {
		if ( ! is_array( $data ) || ! Hosts::is_b2b_host() ) {
			return $data;
		}

		$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;

		if ( ! $product instanceof WC_Product ) {
			return $data;
		}

		$sku = trim( (string) $product->get_sku() );

		if ( '' !== $sku ) {
			$data[] = array(
				'key'     => __( 'SKU', 'beltoft-commerce' ),
				'value'   => $sku,
				'display' => $sku,
			);
		}

		$ean = self::ean( $product );

		if ( '' !== $ean ) {
			$data[] = array(
				'key'     => __( 'EAN', 'beltoft-commerce' ),
				'value'   => $ean,
				'display' => $ean,
			);
		}

		return $data;
	}

	/**
	 * A product's EAN, from whichever key holds it.
	 *
	 * A variation with no code of its own inherits its parent's. The ordering
	 * list (Frontend\ResellerTags) and the scanned-code match
	 * (Frontend\VariationRows) read through here too, so the list, the search
	 * and the cart never disagree - the list used to read the core key only and
	 * printed a bare "EAN:" for products whose code sits in _alg_ean.
	 *
	 * @param bool $inherit Fall back to the parent's code.
	 */
	public static function ean( WC_Product $product, bool $inherit = true ): string {
		$ids = array( $product->get_id() );

		if ( $inherit && $product->get_parent_id() ) {
			$ids[] = $product->get_parent_id();
		}

		foreach ( $ids as $id ) {
			foreach ( self::EAN_KEYS as $key ) {
				$value = trim( (string) get_post_meta( (int) $id, $key, true ) );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}
}
