<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Bank transfer is the only way a partner can pay. It has to say where to pay.
 *
 * B2BPaymentMethods restricts trade to WooCommerce's 'bacs' gateway. That
 * gateway prints its account details on the order-received page and in the
 * customer email - and on this install every one of those fields is empty, and
 * the instructions box with them. So a partner completes a four-figure order,
 * is told to pay by transfer, and is shown nothing to pay into. They then
 * telephone somebody, which is the failure this is meant to prevent.
 *
 * Nobody can invent an IBAN, so this does the two things that can be done
 * without one: it says so plainly to anyone who can fix it, and it stops the
 * order-received page and the email from claiming a transfer is possible when
 * the details are not there - a partner reading "make your payment directly
 * into our bank account" followed by nothing is worse served than one told to
 * expect the details separately.
 *
 * Remove the notice by filling in WooCommerce > Settings > Payments > Direct
 * bank transfer. Nothing here needs changing when that happens.
 */
class BankDetailsGuard {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'description' ), 20, 2 );
		add_filter( 'woocommerce_bacs_accounts', array( __CLASS__, 'accounts' ) );
	}

	/**
	 * True when the gateway has nothing a partner could actually pay into.
	 *
	 * WooCommerce keeps the account rows in their own option and the free-text
	 * instructions in the gateway settings; either one alone is enough to tell
	 * somebody where to send money, so both are checked before complaining.
	 */
	public static function details_missing(): bool {
		$settings = (array) get_option( 'woocommerce_bacs_settings', array() );

		if ( '' !== trim( (string) ( $settings['instructions'] ?? '' ) ) ) {
			return false;
		}

		foreach ( (array) get_option( 'woocommerce_bacs_accounts', array() ) as $account ) {
			foreach ( array( 'iban', 'account_number', 'bic', 'bank_name', 'account_name' ) as $field ) {
				if ( '' !== trim( (string) ( $account[ $field ] ?? '' ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::details_missing() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Transferência bancária sem dados.', 'beltoft-commerce' ),
			esc_html__( 'Os parceiros só podem pagar por transferência, e a conta bancária não está preenchida — quem encomenda não recebe o IBAN nem a referência.', 'beltoft-commerce' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ) ),
			esc_html__( 'Preencher os dados bancários', 'beltoft-commerce' )
		);
	}

	/**
	 * Say what will actually happen, rather than describe a transfer the
	 * partner has no way to make.
	 *
	 * @param string $description
	 * @param string $id
	 * @return string
	 */
	public static function description( $description, $id = '' ) {
		if ( ! self::applies() || B2BPaymentMethods::GATEWAY !== $id || ! self::details_missing() ) {
			return $description;
		}

		return __( 'Entramos em contacto com os dados para a transferência e a referência da encomenda. A encomenda é preparada após a confirmação do pagamento.', 'beltoft-commerce' );
	}

	/**
	 * Keep the empty account table off the order-received page and the email.
	 *
	 * WooCommerce renders a heading and a table of blank rows when the fields
	 * are empty, which reads as a fault in the shop. With no rows it prints
	 * nothing, leaving only the instructions above.
	 *
	 * @param array $accounts
	 * @return array
	 */
	public static function accounts( $accounts ) {
		return self::applies() && self::details_missing() ? array() : $accounts;
	}

	/**
	 * Trade only, for both filters.
	 *
	 * Retail shows this same gateway to administrators under a different name -
	 * the theme relabels it as a manual card payment - so rewriting its
	 * description or its account table there would change retail output to fix
	 * a trade problem. The admin notice is not gated: whoever can fix this is
	 * looking at wp-admin, not at a shop.
	 */
	private static function applies(): bool {
		return Session::is_trade();
	}
}
