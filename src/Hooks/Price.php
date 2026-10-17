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
		add_action( 'wp_ajax_woocommerce_add_order_item', array( __CLASS__, 'refuse_unpriced_batch' ), 1 );
		add_filter( 'woocommerce_ajax_add_order_item_validation', array( __CLASS__, 'refuse_unpriced_item' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'price_rest_order_for_customer' ), 10, 3 );
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
	 * Before any line is added: WooCommerce saves each product as it goes, so
	 * refusing the second of three left the first on the order but not on
	 * screen. The whole batch is checked first, and refused as a whole.
	 */
	public static function refuse_unpriced_batch(): void {
		$group = PricingContext::current();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the nonce in its handler; nothing is changed here.
		$items = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();

		if ( Registry::inherits_prices( $group ) || ! $items || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$product = wc_get_product( absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : 0 ) );

			if ( $product instanceof \WC_Product && null === PriceResolver::resolve( $product, $group ) ) {
				wp_send_json_error( array(
					/* translators: %s: product name */
					'error' => sprintf( __( '%s has no price for this customer.', 'beltoft-commerce' ), $product->get_name() ),
				) );
			}
		}
	}

	/**
	 * Orders created or edited through WooCommerce's REST API are priced for
	 * the order's customer, as in wp-admin: a line sent without totals is
	 * priced from get_price(), which otherwise answers for the API key's user.
	 *
	 * @param mixed            $result
	 * @param mixed            $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function price_rest_order_for_customer( $result, $server = null, $request = null ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $result;
		}

		if ( ! preg_match( '#^/wc/v\d+/orders(?:/(\d+))?$#', $request->get_route(), $m ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return $result;
		}

		$customer = (int) $request->get_param( 'customer_id' );

		if ( ! $customer && ! empty( $m[1] ) ) {
			$order    = wc_get_order( (int) $m[1] );
			$customer = $order instanceof \WC_Order ? (int) $order->get_customer_id() : 0;
		}

		if ( ! $customer || ! get_userdata( $customer ) ) {
			return $result;
		}

		PricingContext::act_for( $customer );

		return self::refuse_unpriced_rest_lines( $request ) ?? $result;
	}

	/**
	 * A line sent without its own total, for a product the customer has no
	 * price for, is refused - as in wp-admin - rather than written as a free
	 * line (the price fails closed to ''). Lines that carry a total or
	 * subtotal are the integration's own price and are left alone.
	 */
	private static function refuse_unpriced_rest_lines( \WP_REST_Request $request ): ?\WP_Error {
		$group = PricingContext::current();
		$lines = $request->get_param( 'line_items' );

		if ( Registry::inherits_prices( $group ) || ! is_array( $lines ) ) {
			return null;
		}

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || isset( $line['total'] ) || isset( $line['subtotal'] ) ) {
				continue;
			}

			$id      = absint( $line['variation_id'] ?? 0 ) ?: absint( $line['product_id'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : null;

			if ( $product instanceof \WC_Product && null === PriceResolver::resolve( $product, $group ) ) {
				return new \WP_Error(
					'beltoft_commerce_no_group_price',
					/* translators: %s: product name */
					sprintf( __( '%s has no price for this customer.', 'beltoft-commerce' ), $product->get_name() ),
					array( 'status' => 400 )
				);
			}
		}

		return null;
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
