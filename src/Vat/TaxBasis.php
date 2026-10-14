<?php

namespace BeltoftCommerce\Vat;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Group prices are NET; retail prices are GROSS.
 *
 * The store is a Portuguese consumer shop, so `woocommerce_prices_include_tax`
 * is `yes` and a retail price of 10,05 means 10,05 at the till. Wholesale is
 * quoted the other way round: the number on a trade price list is what the
 * buyer pays BEFORE IVA. Without this, a 4,50 typed into the B2B price field
 * was read as gross and became a 3,66 line — every trade price 18,7% under
 * what was entered (5,7% on the reduced rate), which is a loss that looks
 * exactly like a correct-looking price list.
 *
 * The basis is a property of the pricing group, so it is decided the same way
 * and in the same place as ex-IVA display — see TaxDisplay, which is this
 * class's sibling. Display and basis are still independent questions: this one
 * says what the stored number MEANS, that one says how it is SHOWN.
 *
 * Filtering the option rather than the `woocommerce_prices_include_tax` filter
 * is deliberate and load-bearing. WC_Checkout::create_order() reads the raw
 * option directly (class-wc-checkout.php:460) to stamp the order, bypassing
 * that filter entirely; an order whose totals were computed net but which is
 * stamped "prices include tax" recalculates wrongly the first time anyone
 * opens it in wp-admin. The option filter covers both readers.
 *
 * KNOWN LIMIT: an order created by hand in wp-admin for a B2B customer is
 * stamped from retail context, because wp-admin has no pricing group. Orders
 * placed by the customer — every normal order — are correct.
 */
class TaxBasis {

	public static function register(): void {
		add_filter( 'option_woocommerce_prices_include_tax', array( __CLASS__, 'basis' ) );
	}

	public static function basis( $value ) {
		return self::stored_net() ? 'no' : $value;
	}

	/**
	 * Whether the number stored against a product means the price BEFORE IVA.
	 *
	 * It does when the group carries its own price meta, because that is how a
	 * trade price list is quoted. It does not when the group is priced from
	 * WooCommerce's own fields - those are the shop's gross prices, and the
	 * till sells at the number on the shelf edge.
	 *
	 * This used to ask "is this group not retail?", which was the same question
	 * while B2B was the only group. It stopped being the same question when the
	 * till arrived: POS is not retail, so a PVP of 8,50 that already contains
	 * IVA was read as net and rung up at 9,01.
	 */
	private static function stored_net(): bool {
		return ! Registry::inherits_prices( PricingContext::current() );
	}
}
