<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Add-to-cart and checkout validation.
 *
 * Both are required, not one. Role, approval status and catalog membership
 * can all change mid-session, so purchasability is re-validated at checkout
 * rather than trusted from add-to-cart time. Architecture §2.8.
 */
class CartGuard {

	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ) );
	}

	public static function validate_add( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}

		if ( CatalogAudience::can_purchase( get_current_user_id(), (int) $product_id ) ) {
			return true;
		}

		wc_add_notice(
			__( 'This product is not available on your account.', 'beltoft-commerce' ),
			'error'
		);

		return false;
	}

	public static function validate_cart(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$user_id = get_current_user_id();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );

			if ( ! $product_id || CatalogAudience::can_purchase( $user_id, $product_id ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			wc_add_notice(
				sprintf(
					/* translators: %s: product name */
					__( '"%s" is no longer available on your account and has been removed.', 'beltoft-commerce' ),
					$product ? $product->get_name() : __( 'A product', 'beltoft-commerce' )
				),
				'error'
			);

			WC()->cart->remove_cart_item( $key );
		}
	}
}
