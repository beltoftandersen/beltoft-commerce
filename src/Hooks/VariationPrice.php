<?php

namespace BeltoftCommerce\Hooks;

use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Variable-product price ranges, resolved per pricing group.
 *
 * Participating in woocommerce_get_variation_prices_hash is MANDATORY, not
 * optional. WooCommerce caches variable-product price ranges in a transient
 * keyed by that hash; without the group in the key, groups collide and one
 * sees another's range. WooCommerce 11.0 changed the default hashing
 * behaviour and the platform advisory is explicit that extensions returning
 * prices which vary by user or role must participate. Architecture §2.5.
 */
class VariationPrice {

	/** Runs last, for the same reason as Hooks\Price::PRIORITY. */
	private const PRIORITY = 1000;

	public static function register(): void {
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'hash' ), self::PRIORITY, 3 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'price' ), self::PRIORITY, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'regular' ), self::PRIORITY, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'sale' ), self::PRIORITY, 3 );
	}

	/**
	 * Folds the pricing group into the variation price cache key.
	 */
	public static function hash( $hash, $product = null, $for_display = false ) {
		$hash = is_array( $hash ) ? $hash : array();

		$hash['beltoft_pricing_group'] = PricingContext::current();

		return $hash;
	}

	public static function price( $price, $variation, $product ) {
		return self::resolve_with( $price, $variation, 'resolve' );
	}

	public static function regular( $price, $variation, $product ) {
		return self::resolve_with( $price, $variation, 'regular' );
	}

	public static function sale( $price, $variation, $product ) {
		return self::resolve_with( $price, $variation, 'sale' );
	}

	private static function resolve_with( $price, $variation, string $method ) {
		$group = PricingContext::current();

		if ( Registry::inherits_prices( $group ) || ! $variation instanceof \WC_Product ) {
			return $price;
		}

		// Fail closed, exactly as the simple-product path does.
		return PriceResolver::$method( $variation, $group ) ?? '';
	}
}
