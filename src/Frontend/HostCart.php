<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * One cart per host kind for a signed-in partner.
 *
 * WHY THIS EXISTS. The session cookie is host-only, but a signed-in
 * customer's WooCommerce session is not: its row is keyed by the user id, so
 * the trade host and the retail host read and write the same cart. That was
 * harmless while price followed the account. Since 2026-10-20 a partner is
 * priced at retail on the retail host (architecture §2.3, amended), and the
 * shared row would carry the trade cart there - with the totals stored at
 * trade prices, which WooCommerce shows (mini cart, fragments) without
 * recalculating - and would prune the trade lines the retail catalogue does
 * not sell from the cart the partner is building on the trade host.
 *
 * HOW. The session remembers which host kind wrote its cart. When the cart is
 * loaded on the other kind, the cart's session keys are put aside for that
 * kind and this kind's own are put back (none, the first time), before
 * WooCommerce reads any of them. A shopper who uses one host only is never
 * touched. The persistent cart, one per account in user meta, stays with
 * the trade cart: it is neither read nor written for a partner on the retail
 * host, so a retail visit cannot replace it.
 *
 * Kind, not host: the retail domains of the other languages share one retail
 * cart as before. A second trade host (planned) would need the host here.
 *
 * KNOWN LIMITS, left as WooCommerce's own behaviour:
 * - Signing in with a guest cart: WooCommerce moves the guest session into
 *   the user's row and replaces it whole (WC_Session_Handler::
 *   migrate_guest_session_to_user_session()), the put-aside cart of the other
 *   host included. A trade cart lost that way comes back from the persistent
 *   cart on the trade host, as of its last save there.
 * - Concurrent requests on both hosts: each reads the row, swaps and writes
 *   it back whole, so the last write wins and the other host's latest change
 *   can be lost. As with any two tabs changing one WooCommerce session.
 */
class HostCart {

	/** Session key: which host kind the cart keys below belong to. */
	public const MARK = 'beltoft_cart_host';

	/** Session key: the other kind's cart keys, put aside. */
	public const STASH = 'beltoft_cart_stash';

	/** Everything WooCommerce keeps about the cart and the checkout under way. */
	private const KEYS = array(
		'cart',
		'cart_totals',
		'applied_coupons',
		'coupon_discount_totals',
		'coupon_discount_tax_totals',
		'removed_cart_contents',
		'chosen_shipping_methods',
		'shipping_method_counts',
		'previous_shipping_methods',
		'chosen_payment_method',
		'order_awaiting_payment',
		'store_api_draft_order',
	);

	public static function register(): void {
		// Before WooCommerce reads the totals (WC_Cart_Session::get_cart_from_session()).
		add_action( 'woocommerce_load_cart_from_session', array( __CLASS__, 'swap' ), 0 );
		add_filter( 'woocommerce_persistent_cart_enabled', array( __CLASS__, 'persistent_cart' ) );
	}

	private static function kind(): string {
		return Hosts::is_b2b_host() ? 'trade' : 'retail';
	}

	public static function swap(): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		if ( ! $session ) {
			return;
		}

		$here = self::kind();
		$was  = $session->get( self::MARK );

		if ( $here === $was ) {
			return;
		}

		$session->set( self::MARK, $here );

		if ( ! is_string( $was ) || '' === $was ) {
			$was = self::unmarked_owner( $session, $here );
		}

		// Never marked, and nothing to put aside: whatever is there is this host's.
		if ( '' === $was || $here === $was ) {
			return;
		}

		$stash = (array) $session->get( self::STASH, array() );
		$mine  = isset( $stash[ $here ] ) && is_array( $stash[ $here ] ) ? $stash[ $here ] : array();
		$aside = array();

		foreach ( self::KEYS as $key ) {
			$aside[ $key ] = $session->get( $key );
			$session->set( $key, $mine[ $key ] ?? null );
		}

		unset( $stash[ $here ] );
		$stash[ $was ] = $aside;

		$session->set( self::STASH, $stash );
	}

	/**
	 * Whose cart an unmarked session holds: the first load after this class
	 * was deployed. Until then a partner was priced at trade on every host,
	 * so a partner's cart found on the retail host is the trade cart - put
	 * aside as trade, and retail starts empty. Anyone else's is this host's.
	 *
	 * @param \WC_Session $session
	 */
	private static function unmarked_owner( $session, string $here ): string {
		if ( 'retail' !== $here || empty( $session->get( 'cart' ) ) ) {
			return '';
		}

		return PricingContext::is_retail( get_current_user_id() ) ? '' : 'trade';
	}

	/**
	 * @param mixed $enabled
	 * @return mixed
	 */
	public static function persistent_cart( $enabled ) {
		if ( ! $enabled || ! PricingContext::is_retail_front() ) {
			return $enabled;
		}

		return PricingContext::is_retail( get_current_user_id() );
	}
}
