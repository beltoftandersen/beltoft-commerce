<?php

namespace BeltoftCommerce\Support;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * What kind of session this is, in commercial terms.
 *
 * WHY THIS EXISTS. Behaviour used to be selected by hostname: anything on the
 * trade host got trade payment methods, a purchase-order field, net prices and
 * a trade account menu. That held while the trade host had exactly one kind of
 * visitor. The till breaks it - a POS operator works on the same host, on the
 * same ordering screen, and must get none of those things.
 *
 * The host decides which INTERFACE you see. The group decides what you are
 * charged and on what terms. They are different questions and this class is
 * where they stop being confused with each other.
 */
class Session {

	/** Trade terms: net prices, transfer payment, purchase orders, trade shipping. */
	public static function is_trade(): bool {
		return Hosts::is_b2b_host() && Registry::is_trade( PricingContext::current() );
	}

	/**
	 * The till.
	 *
	 * One comparison. An earlier version also asked whether the group kept its
	 * own stock, which the group name already implies and which would have made
	 * any second own-stock group answer "no" to being itself.
	 */
	public static function is_pos(): bool {
		return Registry::POS === PricingContext::current();
	}

	/** The group an order was placed under, for work that happens later. */
	public static function group_for_order( \WC_Order $order ): string {
		$group = (string) $order->get_meta( '_beltoft_pricing_group' );

		return '' !== $group && Registry::has( $group ) ? $group : Registry::retail_slug();
	}
}
