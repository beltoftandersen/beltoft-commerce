<?php

namespace BeltoftCommerce\Pos;

use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * How a till sale was paid for.
 *
 * NOT A PAYMENT GATEWAY in the usual sense: nothing is authorised, captured or
 * talked to. The money has already changed hands at the counter - in cash, on
 * the Teya terminal, by MB WAY, by transfer - and this records which of those
 * happened. WooCommerce's checkout already draws a radio list of payment
 * methods, so registering one of these per tender gives the operator the
 * selector with no interface to build.
 *
 * WHY A GATEWAY RATHER THAN A FIELD ON THE ORDER. The choice lands in
 * `_payment_method` and `_payment_method_title`, which every part of
 * WooCommerce already understands: the order screen, the orders list, the
 * emails, the REST API, CSV exports and whatever the accounting integration
 * reads. A meta field of our own would have to be surfaced in each of those by
 * hand, and would be missing from any report nobody thought to change.
 *
 * WHY ONE CLASS AND NOT FOUR. WC_Payment_Gateways::init() accepts instances as
 * well as class names (class-wc-payment-gateways.php:109), so the tenders are a
 * list in Tenders::all() rather than a file each. Adding one is a line.
 *
 * WHY payment_complete() AND NOT update_status(). Both `bacs` and `cod` park
 * the order awaiting payment, because for them the money has not arrived yet.
 * At a till it already has. payment_complete() marks it paid and reduces stock,
 * leaving the order in processing.
 */
class Tender extends WC_Payment_Gateway {

	public function __construct( string $id = '', string $title = '' ) {
		$this->id                 = $id;
		$this->method_title       = $title;
		$this->method_description = __( 'Records how a counter sale was paid. No payment is processed.', 'beltoft-commerce' );
		$this->title              = $title;
		$this->has_fields         = false;

		/*
		 * Enabled outright rather than from a settings row. These are not
		 * gateways anyone configures - there is nothing to configure - and a
		 * disabled one would silently remove a tender the till needs. They are
		 * kept off retail and trade by Pos\PaymentMethods, not by this flag.
		 */
		$this->enabled = 'yes';

		$this->supports = array( 'products' );
	}

	/**
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: the tender, e.g. Dinheiro. */
				__( 'Counter sale paid: %s', 'beltoft-commerce' ),
				$this->title
			)
		);

		// The money was taken before this ran, so the order is paid on arrival.
		$order->payment_complete();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
