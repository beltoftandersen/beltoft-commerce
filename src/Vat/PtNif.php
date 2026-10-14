<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * The Portuguese NIF check digit.
 *
 * Validator deliberately never sends a PT number to VIES: a domestic sale is
 * not a zero-rating question, so there is nothing to ask. Correct for tax, and
 * useless as validation - without this an applicant could submit "1" and it
 * would be stored in the key that checkout and order evidence both read.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. The check digit catches a typo and a
 * placeholder. It does not prove the number belongs to a registered company,
 * which only the tax authority can say. Worth being clear about, because
 * "validated" reads as more than it is.
 *
 * Lives here rather than in Registration because two entry points need it now:
 * the registration form and the fiscal-data page in the account. One copy of
 * the algorithm, not two that can drift.
 */
class PtNif {

	public static function is_valid( string $nif ): bool {
		$digits = preg_replace( '/\D/', '', $nif );

		if ( 9 !== strlen( (string) $digits ) ) {
			return false;
		}

		$sum = 0;

		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $digits[ $i ] * ( 9 - $i );
		}

		$check = 11 - ( $sum % 11 );

		if ( $check >= 10 ) {
			$check = 0;
		}

		return $check === (int) $digits[8];
	}
}
