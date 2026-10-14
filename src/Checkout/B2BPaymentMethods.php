<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Trade pays by bank transfer, and by nothing else.
 *
 * Retail offers MB WAY, Multibanco, cards, PayPal and Apple/Google Pay. None of
 * those belong on a trade order: partners are invoiced and settle by transfer,
 * and a card fee on a four-figure order is money given away.
 *
 * WHY THE GATEWAY IS RENAMED HERE. The bank-transfer gateway on this install is
 * WooCommerce's 'bacs', but its title has been changed to "Teya Credit Card" -
 * retail reuses it as a manual card-payment label. Filtering the list down to
 * 'bacs' alone would therefore offer trade a payment method that reads as a
 * credit card and behaves as a transfer. The title and description are
 * overridden for the B2B host only, so retail keeps the label it depends on.
 *
 * WHY IT RUNS LAST AND REBUILDS THE LIST rather than filtering what it is
 * handed. bricks-child/functions.php has a snippet that unsets 'bacs' for
 * everyone who is not an administrator - on retail that gateway is a manual
 * card label meant for staff only. It is hooked at the same priority as this
 * class and registered after it, so filtering the incoming list down to 'bacs'
 * left trade with no payment method at all: this class kept bacs, the theme
 * then removed it, and checkout said no methods were available. Running at a
 * late priority and composing the list from the gateway registry makes the
 * outcome independent of what any other callback did first.
 *
 * WHY IT FAILS CLOSED. If 'bacs' is disabled or unavailable, this returns no
 * gateways rather than falling back to whatever else is enabled. WooCommerce
 * then says plainly that no payment method is available, which is visible and
 * fixable. The alternative - quietly offering trade the card gateways - would
 * break the rule this class exists to enforce, and nobody would notice until
 * the fees appeared. Pricing in this plugin fails closed for the same reason.
 */
class B2BPaymentMethods {

	/** WooCommerce's direct bank transfer gateway. */
	public const GATEWAY = 'bacs';

	/**
	 * Late enough to be the last word on the list. See the note above about
	 * the bricks-child snippet that removes this gateway from non-admins.
	 */
	private const PRIORITY = 9999;

	public static function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_bank_transfer' ), self::PRIORITY );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'title' ), 10, 2 );
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'description' ), 10, 2 );
	}

	/**
	 * @param array $gateways
	 * @return array
	 */
	public static function only_bank_transfer( $gateways ) {
		if ( ! self::applies() || ! is_array( $gateways ) ) {
			return $gateways;
		}

		$gateway = self::bank_transfer();

		return $gateway ? array( self::GATEWAY => $gateway ) : array();
	}

	/**
	 * The bank transfer gateway, taken from the registry rather than from the
	 * filtered list, and only if WooCommerce itself considers it usable.
	 *
	 * @return \WC_Payment_Gateway|null
	 */
	private static function bank_transfer() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$all     = WC()->payment_gateways()->payment_gateways();
		$gateway = $all[ self::GATEWAY ] ?? null;

		if ( ! $gateway instanceof \WC_Payment_Gateway || ! $gateway->is_available() ) {
			return null;
		}

		return $gateway;
	}

	public static function title( $title, $id = '' ) {
		if ( ! self::applies() || self::GATEWAY !== $id ) {
			return $title;
		}

		return __( 'Bank transfer', 'beltoft-commerce' );
	}

	public static function description( $description, $id = '' ) {
		if ( ! self::applies() || self::GATEWAY !== $id ) {
			return $description;
		}

		return __( 'We send the bank details and the order reference with the confirmation. The order is prepared once the payment is confirmed.', 'beltoft-commerce' );
	}

	private static function applies(): bool {
		return Session::is_trade();
	}
}
