<?php

namespace BeltoftCommerce\Pos;

use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * The ordering list, read by a till rather than by a buyer.
 *
 * The list is built for trade, where the two numbers that matter are what the
 * partner pays (before IVA) and what they are expected to sell it for. At a
 * counter neither of those is true:
 *
 * - the price shown INCLUDES IVA, because the shop's prices are stored gross
 *   and 8,50 on the shelf edge is 8,50 in the drawer. A heading reading
 *   "s/IVA" over a gross number is worse than a wrong number, because it looks
 *   deliberate;
 * - the PVP column is the price the till is already charging, so it is the same
 *   figure printed twice.
 *
 * Done by element id rather than by CSS so the markup is simply absent - a
 * hidden column still costs a grid track and still lands in the accessibility
 * tree. The ids are the human ones set in the builder (hdrprc, hdrpvp,
 * rowrrp), not Bricks' generated ones, so they survive a re-save; if somebody
 * renames them in the builder these rules stop matching and the till quietly
 * gets the trade headings back, which is the failure mode to watch for.
 */
class ListColumns {

	/** The price column heading, and what it should say at a counter. */
	private const PRICE_HEADING = 'hdrprc';

	/**
	 * Markup that says nothing at a till.
	 *
	 * hdrpvp/rowrrp: the PVP heading and its cell - the price the till is
	 * already charging, printed a second time.
	 *
	 * crtship: the inline cart's shipping row. Pos\Checkout already stops
	 * WooCommerce needing or costing a delivery, so the row had stopped saying
	 * "12,00 EUR" and started saying "Envio - Gratis", which is worse: it
	 * offers a delivery that is not going to happen. The customer is holding
	 * the goods.
	 */
	private const DROP = array( 'hdrpvp', 'rowrrp', 'crtship' );

	public static function register(): void {
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'adjust' ), 10, 2 );
	}

	/**
	 * @param mixed $html
	 * @param mixed $element
	 * @return mixed
	 */
	public static function adjust( $html, $element = null ) {
		if ( ! is_string( $html ) || ! is_object( $element ) || ! Session::is_pos() ) {
			return $html;
		}

		$id = (string) ( $element->id ?? '' );

		if ( in_array( $id, self::DROP, true ) ) {
			return '';
		}

		if ( self::PRICE_HEADING === $id ) {
			return str_replace(
				array( 's/IVA', 's&#47;IVA' ),
				'c/IVA',
				$html
			);
		}

		return $html;
	}
}
