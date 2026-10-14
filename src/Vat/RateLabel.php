<?php

namespace BeltoftCommerce\Vat;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * "IVA23" becomes "IVA 23%" on trade documents.
 *
 * The tax rates on this install are named IVA23 and IVA06 - fine as an internal
 * key, terse on a checkout line a partner is asked to approve. Trade orders show
 * tax as its own line for every rate, so these labels are read rather than
 * glanced at, and a rate a buyer can check against their own books should say
 * what the rate is.
 *
 * The rate names themselves are left alone. They appear on retail invoices and
 * in exports, and renaming a WooCommerce tax rate rewrites history rather than
 * presentation, so this reformats the label at display time on the B2B host
 * only. The percentage is read from the rate rather than parsed out of the
 * name, so a rate renamed or added later still prints correctly.
 */
class RateLabel {

	public static function register(): void {
		add_filter( 'woocommerce_rate_label', array( __CLASS__, 'label' ), 10, 2 );
	}

	/**
	 * @param string     $name Rate name as stored.
	 * @param int|string $key  Rate id.
	 * @return string
	 */
	public static function label( $name, $key = 0 ) {
		if ( ! Session::is_trade() || ! is_string( $name ) || '' === $name ) {
			return $name;
		}

		$percent = self::percent( $key );

		if ( null === $percent ) {
			return $name;
		}

		/* translators: %s: tax rate percentage, e.g. "23". */
		return sprintf( __( 'VAT %s%%', 'beltoft-commerce' ), $percent );
	}

	/**
	 * The rate as a trimmed percentage, or null when it cannot be read.
	 *
	 * @param int|string $key
	 * @return string|null
	 */
	private static function percent( $key ): ?string {
		$key = (int) $key;

		if ( ! $key ) {
			return null;
		}

		global $wpdb;

		$rate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tax_rate FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d",
				$key
			)
		);

		if ( null === $rate || '' === $rate ) {
			return null;
		}

		// 23.0000 -> 23, 6.5000 -> 6,5 in pt_PT.
		return wc_format_localized_price( wc_format_decimal( $rate, '', true ) );
	}
}
