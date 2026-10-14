<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Purchasability consults the audience service rather than re-implementing
 * the policy. Architecture §2.8.
 */
class Purchasable {

	public static function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter' ), 1000, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter' ), 1000, 2 );
	}

	public static function filter( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof \WC_Product ) {
			return $purchasable;
		}

		// A variation's audience is its parent's — variations do not carry
		// product terms.
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return CatalogAudience::can_purchase( get_current_user_id(), (int) $id );
	}
}
