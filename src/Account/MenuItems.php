<?php

namespace BeltoftCommerce\Account;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * The trade account menu carries only what a trade account has.
 *
 * The shared install adds consumer endpoints to every account menu: loyalty
 * points, referrals, gift cards, a wishlist and subscriptions. None of them
 * exist for a partner - points and referrals are a retail loyalty scheme, gift
 * cards are a consumer product, the wishlist was already taken off this host,
 * and trade does not sell subscriptions. Left in place they are eight dead ends
 * around the four links a partner actually uses.
 *
 * This removes them from the menu on the B2B host only. It is presentation, not
 * access control: an endpoint reached directly still behaves exactly as
 * WooCommerce and the plugin that owns it decide. Anything that should be
 * unreachable on this host belongs behind its own check, not behind a menu.
 */
class MenuItems {

	/**
	 * Endpoints that belong to the retail loyalty and gifting features rather
	 * than to an account as such.
	 */
	private const RETAIL_ONLY = array(
		'subscriptions',
		'loyalty-points',
		'referrals',
		'gift-cards',
		'wishlist',
	);

	public static function register(): void {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'items' ), 100 );
	}

	/**
	 * @param array $items
	 * @return array
	 */
	public static function items( $items ) {
		if ( ! Session::is_trade() || ! is_array( $items ) ) {
			return $items;
		}

		return array_diff_key( $items, array_flip( self::RETAIL_ONLY ) );
	}
}
