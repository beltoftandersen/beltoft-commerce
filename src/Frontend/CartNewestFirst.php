<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * The line you just added appears at the top of the order panel.
 *
 * WooCommerce appends to the cart, which is the right storage order and the
 * wrong reading order here: the panel is a short scrolling column beside a long
 * list, so an appended line lands out of sight and the buyer gets no
 * confirmation that the click did anything. Newest first puts it where they are
 * already looking.
 *
 * Reversed at the Bricks LOOP, not in the cart itself. `WC()->cart` order
 * reaches the order line items and anything else reading the cart; this only
 * changes what this one panel draws.
 */
class CartNewestFirst {

	/** After VariationRows, which works on post queries and ignores this one. */
	private const PRIORITY = 60;

	public static function register(): void {
		add_filter( 'bricks/query/result', array( __CLASS__, 'newest_first' ), self::PRIORITY, 2 );
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function newest_first( $result, $query = null ) {
		if ( ! Hosts::is_b2b_host() || ! is_array( $result ) || ! $query ) {
			return $result;
		}

		if ( 'wooCart' !== ( $query->object_type ?? '' ) ) {
			return $result;
		}

		// Keys are cart item hashes and every element in the row reads from
		// them, so preserve them rather than renumbering.
		return array_reverse( $result, true );
	}
}
