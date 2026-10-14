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
 * a trade account menu. That holds only while every group on that host trades
 * on trade terms; a group registered with 'trade' => false would get none of
 * them on the same host.
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
}
