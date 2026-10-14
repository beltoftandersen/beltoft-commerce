<?php

namespace BeltoftCommerce\Vat;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * The VAT number field at checkout.
 *
 * B2B SITE ONLY (Chris, 2026-09-27). Not gated on role there: a
 * b2b_pending account still needs to supply one. It was on the retail site
 * too, for a consumer with a foreign EU VAT number (reverse charge), but
 * retail checkout already has the Portuguese NIF field, and two tax-number
 * fields read as a mistake. Optional — an empty field is a normal sale.
 * Architecture §2.10.
 *
 * CLASSIC CHECKOUT ONLY. If this store migrates to block checkout,
 * `woocommerce_checkout_process` does not fire and validation must be
 * re-registered against the Store API checkout hook. The failure mode is
 * silent: the field renders and nothing validates.
 */
class Field {

	public const META_KEY = '_vat_number';

	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'save_to_customer' ), 10, 2 );
	}

	public static function label(): string {
		return __( 'VAT number (NIF/VAT)', 'beltoft-commerce' );
	}

	public static function stored_value( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();

		return $user_id ? (string) get_user_meta( $user_id, self::META_KEY, true ) : '';
	}

	/**
	 * The trade host's checkout. This field belongs to an ACCOUNT: it prefills
	 * from the signed-in partner and saves back to them.
	 */
	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}

	public static function render( $checkout = null ): void {
		if ( ! self::applies() ) {
			return;
		}

		woocommerce_form_field( 'beltoft_vat_number', array(
			'type'        => 'text',
			'label'       => self::label(),
			'required'    => false,
			// See PurchaseOrder::render() - this class is what carries the
			// Bricks v2 checkout's field styling, floating label included.
			'class'       => array( 'form-row-wide', 'brxe-woocommerce-form-field' ),
			'description' => __( 'If you have an EU VAT number, enter it here. Leave empty for a normal purchase.', 'beltoft-commerce' ),
		), self::posted() ?: self::stored_value() );
	}

	/**
	 * Whether the checkout submitted the field at all, empty included.
	 *
	 * posted() cannot tell "left empty" from "not on this form", and the two
	 * mean opposite things: an emptied field is the customer choosing a normal
	 * sale, a missing one is a form this field is not part of.
	 */
	public static function was_posted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
		return isset( $_POST['beltoft_vat_number'] );
	}

	public static function posted(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
		return isset( $_POST['beltoft_vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['beltoft_vat_number'] ) ) : '';
	}

	public static function validate(): void {
		if ( ! self::applies() ) {
			return;
		}

		$number = self::posted();

		if ( '' === $number ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$country = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';

		// Revalidated at each checkout rather than trusted from last time.
		$decision = Validator::decide( $number, $country );

		if ( 'country_mismatch' === $decision->status ) {
			wc_add_notice(
				__( 'This VAT number is from a different country than your billing address. Change the billing country to the VAT number\'s country, or leave the field empty.', 'beltoft-commerce' ),
				'error'
			);
		} elseif ( $decision->blocks_checkout ) {
			wc_add_notice(
				__( 'The VAT number you entered could not be validated. Please correct it or leave the field empty.', 'beltoft-commerce' ),
				'error'
			);
		}
	}

	/**
	 * Checkout writes the number AND settles its evidence.
	 *
	 * It used to write the number alone. A partner who had confirmed a German
	 * number on the fiscal-data page and then typed a different one here ended
	 * up with the new number sitting under the old one's "confirmed by VIES"
	 * and the old company's name; submitting with the field empty wiped the
	 * number and left the confirmation behind, describing nothing.
	 *
	 * An UNCHANGED number is left entirely alone, evidence included - retyping
	 * the same number at checkout must not discard the consultation number that
	 * proves it was checked.
	 */
	public static function save_to_customer( $customer, $data ): void {
		if ( ! self::applies() ) {
			return;
		}

		if ( ! $customer instanceof \WC_Customer ) {
			return;
		}

		$number   = self::posted();
		$user_id  = (int) $customer->get_id();
		$previous = (string) $customer->get_meta( self::META_KEY );

		$customer->update_meta_data( self::META_KEY, $number );

		if ( ! $user_id || $number === $previous ) {
			return;
		}

		/*
		 * The number changed here, and this field does not consult VIES - the
		 * checkout validation it runs is a pass/fail, not a record. So the
		 * proof is cleared rather than carried over to a number it never saw.
		 * The partner re-confirms on the fiscal-data page, which does record it.
		 */
		Evidence::clear_proof( $user_id );
	}
}
