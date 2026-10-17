<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * Evidence written to the order.
 *
 * The consultation number is the only field that cannot be reconstructed
 * after the fact, so it is captured on every check. Architecture §2.10.
 *
 * Orders live in wp_posts (HPOS was deliberately not enabled), so these are
 * ordinary order meta and are read back through the WooCommerce order API.
 */
class OrderEvidence {

	public const VAT_NUMBER   = '_vat_number';
	public const CHECK_STATUS = '_vat_check_status';
	public const REQUEST_ID   = '_vies_request_identifier';
	public const REQUEST_DATE = '_vies_request_date';
	public const REGISTERED   = '_vies_registered_name';

	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach' ), 10, 2 );
	}

	public static function attach( $order, $data ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$number = Field::posted();

		if ( '' === $number ) {
			return;
		}

		/*
		 * From what this checkout already learnt, not a second VIES call: the
		 * totals were worked out from the answer Field::validate() cached (or
		 * from the account's), and asking again could get a different one -
		 * an order charged IVA recorded as 'valid' with nothing flagging the
		 * refund, or a zero-rated one recorded as 'invalid' - and while VIES
		 * is down it added another ten-second wait to the checkout.
		 */
		$decision = Validator::decide( $number, (string) ( $data['billing_country'] ?? '' ), false );

		/*
		 * VIES was down, and the totals were zero-rated on the account's own
		 * stored confirmation of this number (Exemption). Recording only the
		 * failed check wrote "IVA has been charged" on an order that was not
		 * charged it, and left out the consultation that justifies the zero
		 * rate. The account's evidence is what the order was decided on.
		 */
		if ( 'unverified' === $decision->status && 'yes' === $order->get_meta( 'is_vat_exempt' ) && self::write_from_account( $order, $number ) ) {
			return;
		}

		self::write( $order, $decision );

		/*
		 * Exemption is NOT applied here. This hook fires after
		 * set_data_from_cart() has already stamped the order's tax lines,
		 * total and is_vat_exempt meta, so setting it now changed nothing —
		 * the order was saved with IVA charged and marked not exempt. The
		 * flag is set while the totals are calculated instead; see
		 * Exemption. This method's job is the evidence trail only.
		 */
	}

	/**
	 * Copy the account's stored VIES confirmation onto the order, if it is a
	 * confirmation of this same number.
	 */
	private static function write_from_account( \WC_Order $order, string $number ): bool {
		$user_id = (int) $order->get_customer_id();

		if ( ! $user_id || ! Exemption::account_says_valid( $user_id ) ) {
			return false;
		}

		$stored = (string) get_user_meta( $user_id, Field::META_KEY, true );

		if ( Field::normalise( $stored ) !== Field::normalise( $number ) ) {
			return false;
		}

		$request_id = (string) get_user_meta( $user_id, self::REQUEST_ID, true );
		$date       = (string) get_user_meta( $user_id, self::REQUEST_DATE, true );

		$order->update_meta_data( self::VAT_NUMBER, Field::normalise( $stored ) );
		$order->update_meta_data( self::CHECK_STATUS, 'valid' );
		$order->update_meta_data( self::REQUEST_ID, $request_id );
		$order->update_meta_data( self::REQUEST_DATE, $date );
		$order->update_meta_data( self::REGISTERED, (string) get_user_meta( $user_id, self::REGISTERED, true ) );

		$order->add_order_note( sprintf(
			/* translators: 1: date of the account's VIES check, 2: VIES consultation number */
			__( 'VIES was unreachable at checkout. Reverse charge was applied on the account\'s VIES confirmation of %1$s (consultation %2$s).', 'beltoft-commerce' ),
			$date ?: __( 'unknown date', 'beltoft-commerce' ),
			$request_id ?: __( 'none', 'beltoft-commerce' )
		) );

		return true;
	}

	public static function write( \WC_Order $order, VatDecision $decision ): void {
		$order->update_meta_data( self::VAT_NUMBER, $decision->vat_number );
		$order->update_meta_data( self::CHECK_STATUS, $decision->status );
		$order->update_meta_data( self::REQUEST_ID, $decision->request_identifier );
		$order->update_meta_data( self::REQUEST_DATE, $decision->checked_at ? gmdate( 'c', $decision->checked_at ) : '' );
		$order->update_meta_data( self::REGISTERED, $decision->registered_name );

		if ( 'unverified' === $decision->status ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error detail from the VIES service */
				__( 'VAT number could not be verified: VIES was unreachable (%s). IVA has been charged and this order needs manual review.', 'beltoft-commerce' ),
				$decision->error ?: __( 'no detail', 'beltoft-commerce' )
			) );
		}
	}
}
