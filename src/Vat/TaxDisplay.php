<?php

namespace BeltoftCommerce\Vat;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Ex-IVA display for B2B sessions, incl-IVA for retail.
 *
 * Display is a function of the pricing group; exemption is a function of a
 * validated VAT number. They are INDEPENDENT — a B2B customer without a
 * valid VAT number sees ex-IVA prices and still pays Portuguese IVA.
 * Architecture §2.10.
 *
 * B2B also gets IVA broken out PER RATE rather than as one combined line.
 * A trade buyer is reclaiming the tax, so "IVA: 5,44 €" over an order
 * mixing 23% and 6% goods is not something they can enter in a ledger —
 * and 19 published products sit on the reduced rate, so mixed orders are
 * routine rather than exotic. Retail is left on the combined line: it
 * displays incl-IVA, where the per-rate rows are never rendered at all.
 *
 * This covers a LIVE session. An order rendered outside one — a cron-sent
 * email, the wp-admin order screen — has no pricing group and is handled
 * by OrderTaxDisplay instead.
 */
class TaxDisplay {

	public static function register(): void {
		add_filter( 'option_woocommerce_tax_display_shop', array( __CLASS__, 'display_mode' ) );
		add_filter( 'option_woocommerce_tax_display_cart', array( __CLASS__, 'display_mode' ) );
		add_filter( 'option_woocommerce_tax_total_display', array( __CLASS__, 'total_display' ) );
	}

	public static function total_display( $value ) {
		return self::is_b2b() ? 'itemized' : $value;
	}

	public static function display_mode( $value ) {
		return self::is_b2b() ? 'excl' : $value;
	}

	private static function is_b2b(): bool {
		return Registry::is_trade( PricingContext::current() );
	}
}
