<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * (product, pricing group) → effective price.
 *
 * The ONLY place group price resolution happens. WC_Product::get_price() and
 * the variation-price path are genuinely different code paths in WooCommerce;
 * duplicating this logic across them produces a cart total that disagrees
 * with the displayed price. Every price surface consumes this class — see
 * architecture §2.5.
 *
 * Every price is a stored value read at render. There is no rules engine and
 * no per-request evaluation, which is what keeps B2B category pages as fast
 * as retail ones.
 */
class PriceResolver {

	/**
	 * Returns null when the group has no stored regular price. Callers MUST
	 * treat null as "not purchasable for this group" and never substitute
	 * retail.
	 */
	public static function regular( \WC_Product $product, string $group ): ?string {
		if ( Registry::inherits_prices( $group ) ) {
			return self::clean( $product->get_regular_price( 'edit' ) );
		}

		return self::clean( get_post_meta( $product->get_id(), Registry::regular_key( $group ), true ) );
	}

	/**
	 * The group's sale price, but only inside its date window. Outside the
	 * window this is null even though a value is stored.
	 */
	public static function sale( \WC_Product $product, string $group ): ?string {
		if ( Registry::inherits_prices( $group ) ) {
			return self::clean( $product->get_sale_price( 'edit' ) );
		}

		$value = self::clean( get_post_meta( $product->get_id(), Registry::sale_key( $group ), true ) );

		if ( null === $value ) {
			return null;
		}

		return self::within_sale_window( $product, $group ) ? $value : null;
	}

	public static function is_on_sale( \WC_Product $product, string $group ): bool {
		$sale    = self::sale( $product, $group );
		$regular = self::regular( $product, $group );

		if ( null === $sale || null === $regular ) {
			return false;
		}

		return (float) $sale < (float) $regular;
	}

	/**
	 * Group sale price inside its window, else group regular price, else
	 * null — fail closed.
	 */
	public static function resolve( \WC_Product $product, string $group ): ?string {
		$sale = self::sale( $product, $group );

		if ( null !== $sale ) {
			return $sale;
		}

		return self::regular( $product, $group );
	}

	public static function has_price( \WC_Product $product, string $group ): bool {
		return null !== self::resolve( $product, $group );
	}

	/**
	 * Distinguishes "no price stored" from a stored price of zero, which is
	 * a legitimate value.
	 */
	private static function clean( $value ): ?string {
		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}

	private static function within_sale_window( \WC_Product $product, string $group ): bool {
		$from = get_post_meta( $product->get_id(), Registry::sale_from_key( $group ), true );
		$to   = get_post_meta( $product->get_id(), Registry::sale_to_key( $group ), true );
		$now  = time();

		if ( $from && $now < (int) $from ) {
			return false;
		}

		// The "to" date is inclusive of that day, matching core behaviour.
		if ( $to && $now > (int) $to ) {
			return false;
		}

		return true;
	}
}
