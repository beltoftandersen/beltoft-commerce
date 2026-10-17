<?php

namespace BeltoftCommerce\Hooks;

use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires PriceResolver into WooCommerce's simple-product price path.
 *
 * Retail short-circuits immediately, so logged-out requests pay nothing for
 * any of this and the cached guest bucket is unchanged. Architecture §2.5.
 */
class Price {

	/**
	 * Priority matters here and priority 10 is wrong.
	 *
	 * Two other callbacks sit on woocommerce_product_get_price at 99 and
	 * overwrite whatever came before them:
	 *   - Automattic\WooCommerce\Internal\ScheduledSalePriceReconciler (core, WC 11)
	 *   - WCS_ATT_Product_Price_Filters (WooCommerce Subscriptions)
	 * Registering at 10 meant the group price was computed and then thrown
	 * away, and the storefront showed retail prices to B2B customers. The
	 * unit suite could not catch it: it loads WooCommerce alone, so neither
	 * callback is present.
	 *
	 * The resolver is the single source of truth for a group's price, so it
	 * runs last.
	 */
	private const PRIORITY = 1000;

	public static function register(): void {
		/*
		 * Two hook prefixes, not one. WC_Data::get_prop() builds its filter
		 * name from get_hook_prefix(), and WC_Product_Variation overrides
		 * that to `woocommerce_product_variation_get_`. Registering only the
		 * `woocommerce_product_get_` variants leaves every variation object
		 * returning retail prices to B2B customers — while the variable
		 * product's price *range* resolves correctly, because that goes
		 * through the separate woocommerce_variation_prices_* filters in
		 * Hooks\VariationPrice. The result is a price range that disagrees
		 * with the price of the variation the customer actually selects.
		 */
		foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
			add_filter( $prefix . 'price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'regular_price', array( __CLASS__, 'regular' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'sale_price', array( __CLASS__, 'sale' ), self::PRIORITY, 2 );
		}

		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'on_sale' ), self::PRIORITY, 2 );

		// Before WooCommerce's own handler (10), which prices the new lines.
		add_action( 'wp_ajax_woocommerce_add_order_item', array( __CLASS__, 'price_added_items_for_customer' ), 0 );
		add_filter( 'woocommerce_ajax_add_order_item_validation', array( __CLASS__, 'refuse_unpriced_item' ), 10, 4 );
		add_action( 'admin_footer', array( __CLASS__, 'send_customer_with_added_items' ) );
		add_filter( 'woocommerce_product_variation_is_on_sale', array( __CLASS__, 'on_sale' ), self::PRIORITY, 2 );
	}

	public static function price( $value, $product ) {
		$group = PricingContext::current();

		if ( Registry::inherits_prices( $group ) || ! $product instanceof \WC_Product ) {
			return $value;
		}

		$resolved = PriceResolver::resolve( $product, $group );

		// Fail closed: an empty string renders as no price and makes the
		// product non-purchasable, rather than falling back to retail.
		return null === $resolved ? '' : $resolved;
	}

	public static function regular( $value, $product ) {
		$group = PricingContext::current();

		if ( Registry::inherits_prices( $group ) || ! $product instanceof \WC_Product ) {
			return $value;
		}

		return PriceResolver::regular( $product, $group ) ?? '';
	}

	public static function sale( $value, $product ) {
		$group = PricingContext::current();

		if ( Registry::inherits_prices( $group ) || ! $product instanceof \WC_Product ) {
			return $value;
		}

		return PriceResolver::sale( $product, $group ) ?? '';
	}

	public static function on_sale( $on_sale, $product ) {
		$group = PricingContext::current();

		if ( Registry::inherits_prices( $group ) || ! $product instanceof \WC_Product ) {
			return $on_sale;
		}

		// Must reflect the group's own sale state, or B2B customers see
		// sale badges driven by retail campaigns that do not apply.
		return PriceResolver::is_on_sale( $product, $group );
	}

	/**
	 * Lines added to an order in wp-admin are priced for the order's customer.
	 * WooCommerce's add-item handler prices them from get_price(), which these
	 * filters answer for the signed-in user - the shop manager, at retail - so
	 * a phone order for a partner came out at retail prices.
	 */
	public static function price_added_items_for_customer(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checks the nonce and capability in its own handler, which runs next.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id && current_user_can( 'edit_shop_orders' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		/*
		 * A new order is not saved until Create, so its customer is still 0
		 * when items are added; send_customer_with_added_items() sends the one
		 * chosen on the screen.
		 */
		$customer = (int) $order->get_customer_id();

		if ( ! $customer && isset( $_POST['beltoft_customer_user'] ) ) {
			$customer = absint( wp_unslash( $_POST['beltoft_customer_user'] ) );
		}
		// phpcs:enable

		if ( $customer && get_userdata( $customer ) ) {
			PricingContext::act_for( $customer );
		}
	}

	/**
	 * A product the order's customer has no price for is refused, not added
	 * at 0: under act_for() its price fails closed to '', which WooCommerce
	 * writes as a free line.
	 *
	 * @param \WP_Error $error
	 * @param mixed     $product
	 * @param mixed     $order
	 * @param mixed     $qty
	 * @return \WP_Error
	 */
	public static function refuse_unpriced_item( $error, $product = null, $order = null, $qty = 1 ) {
		$group = PricingContext::current();

		if ( ! $error instanceof \WP_Error || ! $product instanceof \WC_Product || Registry::inherits_prices( $group ) ) {
			return $error;
		}

		if ( null === PriceResolver::resolve( $product, $group ) ) {
			$error->add(
				'beltoft_commerce_no_group_price',
				/* translators: %s: product name */
				sprintf( __( '%s has no price for this customer.', 'beltoft-commerce' ), $product->get_name() )
			);
		}

		return $error;
	}

	/**
	 * On the order screen, add the chosen customer to WooCommerce's add-item
	 * request, which otherwise sends only the order id.
	 */
	public static function send_customer_with_added_items(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		?>
<script>
jQuery( function ( $ ) {
	$.ajaxPrefilter( function ( options ) {
		if ( typeof options.data === 'string' && options.data.indexOf( 'action=woocommerce_add_order_item' ) !== -1 ) {
			var customer = $( '#customer_user' ).val();
			if ( customer ) {
				options.data += '&beltoft_customer_user=' + encodeURIComponent( customer );
			}
		}
	} );
} );
</script>
		<?php
	}
}
