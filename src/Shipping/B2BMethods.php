<?php

namespace BeltoftCommerce\Shipping;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Trade delivery rates, shown only to trade.
 *
 * WooCommerce scopes shipping methods by geography, not by who is buying, so
 * a rate meant for wholesale is offered to every consumer in the same zone.
 * The zone stays the unit of configuration — the methods are ordinary
 * instances an operator can edit in the usual screen — and this class decides
 * which of them a given session is allowed to see.
 *
 * Nominated by instance id in an option rather than by title, because a title
 * is translated and edited and a rule keyed on one breaks silently the first
 * time somebody rewords it.
 */
class B2BMethods {

	/** @var string Option holding the trade-only shipping instance ids. */
	public const OPTION = 'beltoft_commerce_b2b_shipping_instances';

	/** Package key carrying the pricing group into the rate cache hash. */
	private const PACKAGE_KEY = 'beltoft_pricing_group';

	public static function register(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'tag_packages' ), 20 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 20, 2 );
	}

	/**
	 * Shipping rates are cached per session against a hash of the package, and
	 * `woocommerce_package_rates` only runs on a miss (class-wc-shipping.php:390
	 * sits inside the miss branch at :330). Without the group in the package,
	 * the hash cannot tell trade from retail, so a b2b_pending account approved
	 * mid-session would keep being offered consumer rates until its cart
	 * contents happened to change. Putting the group in the package is also the
	 * truthful description: these really are different packages to price.
	 *
	 * @param array $packages
	 * @return array
	 */
	public static function tag_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $i => $package ) {
			$packages[ $i ][ self::PACKAGE_KEY ] = PricingContext::current();

			// Which rates are trade-only is part of the price too: changing
			// the option left sessions on rates cached under the old rule.
			$packages[ $i ][ self::PACKAGE_KEY . '_instances' ] = implode( ',', array_map( 'intval', (array) get_option( self::OPTION, array() ) ) );
		}

		return $packages;
	}

	/**
	 * @param array $rates Rate id => WC_Shipping_Rate.
	 * @return array
	 */
	public static function filter_rates( $rates, $package = array() ) {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}

		$trade_instances = self::instances();

		if ( ! $trade_instances ) {
			return $rates;
		}

		$trade = array();

		foreach ( $rates as $key => $rate ) {
			if ( in_array( (int) $rate->get_instance_id(), $trade_instances, true ) ) {
				$trade[ $key ] = $rate;
			}
		}

		// A consumer is never offered a wholesale rate.
		if ( ! self::is_b2b() ) {
			return array_diff_key( $rates, $trade );
		}

		/*
		 * A zone with no trade rate of its own keeps its ordinary ones. The
		 * alternative is a buyer in an unconfigured zone reaching checkout with
		 * no shipping method at all, which does not degrade — it stops the
		 * order outright.
		 */
		if ( ! $trade ) {
			return $rates;
		}

		/*
		 * Once the order qualifies for free carriage, free carriage is the
		 * offer — not one of two. Leaving a paid trade rate beside it asks the
		 * buyer to choose not to pay.
		 */
		$free = array();

		foreach ( $trade as $key => $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() ) {
				$free[ $key ] = $rate;
			}
		}

		return $free ?: $trade;
	}

	/**
	 * @return int[]
	 */
	public static function instances(): array {
		return array_values( array_filter( array_map( 'intval', (array) get_option( self::OPTION, array() ) ) ) );
	}

	/**
	 * @param int[] $instance_ids
	 */
	public static function set_instances( array $instance_ids ): void {
		update_option( self::OPTION, array_values( array_unique( array_filter( array_map( 'intval', $instance_ids ) ) ) ) );
	}

	private static function is_b2b(): bool {
		return Registry::is_trade( PricingContext::current() );
	}
}
