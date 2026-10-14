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
}
