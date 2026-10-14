<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Session;

defined( 'ABSPATH' ) || exit;

/**
 * An optional purchase-order reference on the trade checkout.
 *
 * A trade buyer usually orders against a PO raised in their own system, and the
 * number has to travel with the order: onto the confirmation they forward to
 * accounts, and onto the order in their account so they can match a delivery to
 * a PO months later. Optional, because plenty of shops order without one and a
 * required field would stop them.
 *
 * Rendered on `woocommerce_after_checkout_billing_form`, the same hook
 * Vat\Field uses — verified to fire under Bricks' Checkout v2, so the field
 * appears whatever the layout does, rather than depending on a Bricks element
 * that a future edit could delete.
 */
class PurchaseOrder {

	public const FIELD    = 'beltoft_po_number';
	public const META_KEY = '_beltoft_po_number';

	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach' ), 10, 2 );

		// Customer-facing: the account order page and both order emails.
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_to_totals' ), 20, 3 );
		// Staff-facing: the order screen, where fulfilment reads it.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin' ) );
	}

	private static function applies(): bool {
		return Session::is_trade();
	}

	public static function label(): string {
		return __( 'Número da nota de encomenda', 'beltoft-commerce' );
	}

	public static function render(): void {
		if ( ! self::applies() ) {
			return;
		}

		woocommerce_form_field( self::FIELD, array(
			'type'        => 'text',
			'label'       => self::label(),
			'required'    => false,
			/*
			 * 'brxe-woocommerce-form-field' is what carries the Bricks v2
			 * checkout's field styling, including the floating label. This
			 * field is WooCommerce-rendered rather than a Bricks element, so
			 * without the class it lands in the middle of the form as an
			 * unstyled 26px box. The class is inert anywhere the Bricks
			 * checkout is not present, and this field is B2B-only regardless.
			 */
			'class'       => array( 'form-row-wide', 'brxe-woocommerce-form-field' ),
			'description' => __( 'Se a sua empresa usa uma referência interna, indique-a aqui. Aparece na confirmação e na encomenda.', 'beltoft-commerce' ),
		), self::posted() );
	}

	public static function posted(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
		return isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
	}

	public static function attach( $order, $data ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$po = self::posted();

		if ( '' === $po ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, $po );
	}

	public static function of( \WC_Abstract_Order $order ): string {
		return trim( (string) $order->get_meta( self::META_KEY ) );
	}

	/**
	 * Shown in the totals block, which is the one place rendered by the account
	 * order page AND both order emails — so the reference travels with the order
	 * without touching three templates.
	 *
	 * @param array $rows
	 * @return array
	 */
	public static function add_to_totals( $rows, $order, $tax_display ) {
		if ( ! is_array( $rows ) || ! $order instanceof \WC_Abstract_Order ) {
			return $rows;
		}

		$po = self::of( $order );

		if ( '' === $po ) {
			return $rows;
		}

		// First, so it reads as a reference for the order rather than a charge
		// sitting among the money.
		return array_merge(
			array( 'beltoft_po' => array(
				'type'  => 'po',
				'label' => self::label() . ':',
				'value' => esc_html( $po ),
			) ),
			$rows
		);
	}

	public static function render_admin( $order ): void {
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return;
		}

		$po = self::of( $order );

		if ( '' === $po ) {
			return;
		}

		printf(
			'<p><strong>%s</strong><br>%s</p>',
			esc_html( self::label() ),
			esc_html( $po )
		);
	}
}
