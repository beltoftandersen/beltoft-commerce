<?php

namespace BeltoftCommerce\Registration;

use BeltoftCommerce\Vat\PtNif;
use BeltoftCommerce\Vat\Validator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a submitted application into a b2b_pending account.
 *
 * FAIL BEFORE CREATING, NOT AFTER. Everything is validated up front and the
 * user is only inserted once the whole application is known to be good. The
 * alternative - create, then write meta, then discover the NIF is malformed -
 * leaves a half-built account that is not an applicant and not a customer, and
 * someone has to go and find it.
 *
 * THE ROLE IS ALWAYS b2b_pending. There is no argument for it and no filter to
 * change it, because the one mistake this whole flow exists to prevent is an
 * unapproved signup arriving with wholesale terms (§2.2). Approval is a
 * separate, deliberate act in Status::approve().
 */
class Registrar {

	/**
	 * @param array<string, string> $input Raw field values keyed by Fields::all() keys.
	 * @return int|WP_Error New user id, or the first problem found.
	 */
	public static function create( array $input ) {
		$clean  = array();
		$fields = Fields::all();

		foreach ( $fields as $key => $field ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

			if ( '' === $value ) {
				if ( $field['required'] ) {
					/* translators: %s: field label. */
					return new WP_Error( 'beltoft_required', sprintf( __( 'Fill in the "%s" field.', 'beltoft-commerce' ), $field['label'] ) );
				}

				continue;
			}

			$clean[ $key ] = $value;
		}

		if ( ! is_email( $clean['email'] ) ) {
			return new WP_Error( 'beltoft_email', __( 'Enter a valid email address.', 'beltoft-commerce' ) );
		}

		if ( email_exists( $clean['email'] ) ) {
			return new WP_Error( 'beltoft_email_exists', __( 'An account with this email already exists.', 'beltoft-commerce' ) );
		}

		$nif = self::validate_nif( $clean['nif'], $clean['country'] ?? 'PT' );

		if ( is_wp_error( $nif ) ) {
			return $nif;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $clean['email'],
			'user_email'   => $clean['email'],
			'user_pass'    => $clean['password'],
			'first_name'   => $clean['first_name'],
			'last_name'    => $clean['last_name'],
			'display_name' => $clean['first_name'] . ' ' . $clean['last_name'],
			'user_url'     => $clean['website'] ?? '',
			'role'         => 'b2b_pending',
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		foreach ( $fields as $key => $field ) {
			if ( ! isset( $clean[ $key ] ) ) {
				continue;
			}

			// Already handled by wp_insert_user().
			if ( in_array( $field['meta'], array( 'user_email', 'user_pass', 'user_url', 'first_name', 'last_name' ), true ) ) {
				continue;
			}

			$value = in_array( $field['meta'], array( '_b2b_company_info' ), true )
				? sanitize_textarea_field( $clean[ $key ] )
				: sanitize_text_field( $clean[ $key ] );

			update_user_meta( $user_id, $field['meta'], $value );
		}

		/*
		 * The name has to be written to the billing fields explicitly.
		 *
		 * Fields:: maps it to the WordPress user fields, which is right - that
		 * is where a name belongs - but WC_Customer_Data_Store::read() has no
		 * first_name -> billing_first_name fallback, so checkout would show an
		 * empty name for an applicant who had just typed one. The whole point
		 * of mapping onto billing fields is that the first checkout prefills;
		 * leaving the two name fields out undoes that for the two fields a
		 * buyer notices first.
		 */
		update_user_meta( $user_id, 'billing_first_name', $clean['first_name'] );
		update_user_meta( $user_id, 'billing_last_name', $clean['last_name'] );

		/*
		 * Shipping mirrors billing on day one. WooCommerce falls back to the
		 * billing address when shipping is empty for some calculations but not
		 * all of them, and an applicant who filled one address in does not
		 * expect to be asked for it twice at their first checkout.
		 */
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'city', 'postcode', 'country', 'state' ) as $part ) {
			$billing = get_user_meta( $user_id, 'billing_' . $part, true );

			if ( '' !== $billing ) {
				update_user_meta( $user_id, 'shipping_' . $part, $billing );
			}
		}

		update_user_meta( $user_id, Status::META_STATUS, Status::PENDING );
		update_user_meta( $user_id, Status::META_APPLIED, gmdate( 'c' ) );

		do_action( 'beltoft_commerce_b2b_applied', $user_id );

		return (int) $user_id;
	}

	/**
	 * Validate the NIF without ever blocking on someone else's downtime.
	 *
	 * Portuguese numbers are checked locally - Validator treats PT as domestic
	 * and never calls VIES, which is what keeps a Portuguese applicant off the
	 * critical path of an EU service. For other EU countries an unreachable
	 * VIES is recorded and allowed through, exactly as Stage 5 decided for
	 * checkout: an outage must not become a closed door.
	 */
	private static function validate_nif( string $nif, string $country ) {
		if ( ! class_exists( Validator::class ) ) {
			return $nif;
		}

		$decision = Validator::decide( $nif, $country );

		/*
		 * 'domestic' means Validator did not check anything.
		 *
		 * It short-circuits on the home country by design (Validator.php:44) -
		 * a PT number is never sent to VIES, because a domestic sale is not a
		 * zero-rating question. Correct for tax, useless as validation: a PT
		 * applicant could submit "1" and it would be stored in the key that
		 * checkout and order evidence both read. So the check digit is verified
		 * here, at the point of entry, where a wrong number can still be
		 * corrected by the person who typed it.
		 */
		if ( 'domestic' === $decision->status && ! PtNif::is_valid( $nif ) ) {
			return new WP_Error( 'beltoft_nif', __( 'The tax number given is not valid.', 'beltoft-commerce' ) );
		}

		/*
		 * Only an explicit 'invalid' stops an application. 'valid' is a
		 * confirmed EU number and 'unverified' is VIES being unreachable -
		 * which Stage 5 deliberately treats as "we do not know", never as
		 * "wrong". Rejecting on 'unverified' would turn somebody else's outage
		 * into a closed shop door, and the applicant could do nothing about it.
		 */
		if ( 'invalid' === $decision->status ) {
			return new WP_Error( 'beltoft_nif', __( 'The tax number given is not valid.', 'beltoft-commerce' ) );
		}

		// The country chosen here becomes the billing country, and reverse
		// charge needs it to be the number's own member state.
		if ( 'country_mismatch' === $decision->status ) {
			return new WP_Error( 'beltoft_nif', __( 'The VAT number given is from a different country than the one selected.', 'beltoft-commerce' ) );
		}

		return $nif;
	}

}
