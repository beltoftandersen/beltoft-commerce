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

		$decision = Validator::decide( $number, (string) ( $data['billing_country'] ?? '' ) );

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
