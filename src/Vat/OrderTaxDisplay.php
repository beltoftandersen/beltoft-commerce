<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a net-priced order render as one, wherever it is rendered.
 *
 * TaxDisplay decides from the live session, which is the right source while
 * the customer is shopping and useless afterwards. WooCommerce renders an
 * order's totals from `get_option( 'woocommerce_tax_display_cart' )` when no
 * mode is passed (abstract-wc-order.php:2845), and the two places that matter
 * most have no B2B session at all: an email sent from cron or from an admin
 * status change, and the wp-admin order screen.
 *
 * Measured on order #191185, two rates, before this class existed:
 *
 *   in the customer's session       outside it (email / wp-admin)
 *   Subtotal   27,78                Subtotal   30,98
 *   IVA         5,44                Total      42,98 (inclui 5,44 IVA)
 *   Total      42,98
 *
 * So a trade order confirmation went out reading like a consumer receipt,
 * with the net figures the buyer needs nowhere on it.
 *
 * The test is the order's own `prices_include_tax` flag rather than the
 * customer's role. It is the honest question — were these stored prices net?
 * — it needs no session, and it stays correct for an order placed under a
 * pricing setup that has since changed.
 */
class OrderTaxDisplay {

	/** True only while re-rendering an order's totals in net mode. */
	private static bool $rendering_net = false;

	public static function register(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 10, 3 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( __CLASS__, 'line_subtotal' ), 10, 3 );
		add_filter( 'option_woocommerce_tax_total_display', array( __CLASS__, 'total_display' ) );
	}

	/**
	 * Per-rate rows while re-rendering a net order, for the same reason B2B
	 * sessions get them — see TaxDisplay. Scoped to the re-entry below so it
	 * cannot leak into an unrelated option read.
	 */
	public static function total_display( $value ) {
		return self::$rendering_net ? 'itemized' : $value;
	}

	/**
	 * @param array $rows
	 * @return array
	 */
	public static function totals( $rows, $order, $tax_display ) {
		if ( self::$rendering_net || ! self::is_net_order( $order ) || 'excl' === $tax_display ) {
			return $rows;
		}

		self::$rendering_net = true;

		try {
			// Re-enters this filter once, and the guard above returns
			// immediately, so there is exactly one extra pass.
			return $order->get_order_item_totals( 'excl' );
		} finally {
			self::$rendering_net = false;
		}
	}

	/**
	 * Line subtotals in order tables and emails go through their own
	 * inc/excl branch (abstract-wc-order.php:2578), so fixing the totals
	 * block alone would leave an order whose lines are gross and whose
	 * totals are net — worse than either on its own.
	 */
	public static function line_subtotal( $subtotal, $item, $order ) {
		if ( ! self::is_net_order( $order ) ) {
			return $subtotal;
		}

		return wc_price(
			$order->get_line_subtotal( $item ),
			array( 'currency' => $order->get_currency() )
		);
	}

	private static function is_net_order( $order ): bool {
		return $order instanceof \WC_Abstract_Order && ! $order->get_prices_include_tax();
	}
}
