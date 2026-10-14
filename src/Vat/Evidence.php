<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * The VAT number on an account, and the proof that goes with it.
 *
 * ONE WRITER, BECAUSE THEY MUST MOVE TOGETHER. The number and its evidence -
 * status, VIES consultation number, registered name, date - are five separate
 * user meta keys, and the only thing that makes them meaningful is that they
 * all describe the SAME number. Two places write them: the fiscal-data page in
 * the account and the VAT field at checkout.
 *
 * Checkout used to write the number alone. A partner who saved a VIES-valid
 * German number on their account page and then typed a different one at
 * checkout ended up with the new number sitting under the old number's
 * "confirmed by VIES" and the old company's name - and if they submitted
 * checkout with the field empty, the number was wiped while the confirmation
 * stayed behind, describing nothing at all.
 *
 * A DATE ONLY WHEN SOMETHING WAS CONSULTED. VatDecision stamps checked_at on
 * construction, so a Portuguese number, a non-EU number and an empty field all
 * arrive carrying a timestamp despite never reaching VIES. Writing that
 * produced "Verificado em: today" above "Sem número de IVA associado". Only
 * the three statuses that involve an actual consultation record a date.
 */
class Evidence {

	/** Statuses that mean VIES was actually asked. */
	private const CONSULTED = array( 'valid', 'invalid', 'unverified' );

	public static function store( int $user_id, string $number, VatDecision $decision, string $status ): void {
		if ( ! $user_id ) {
			return;
		}

		$consulted = in_array( $status, self::CONSULTED, true );

		update_user_meta( $user_id, Field::META_KEY, $number );
		update_user_meta( $user_id, OrderEvidence::CHECK_STATUS, $status );
		update_user_meta( $user_id, OrderEvidence::REQUEST_ID, $consulted ? $decision->request_identifier : '' );
		update_user_meta( $user_id, OrderEvidence::REGISTERED, $consulted ? $decision->registered_name : '' );
		update_user_meta(
			$user_id,
			OrderEvidence::REQUEST_DATE,
			$consulted && $decision->checked_at ? gmdate( 'c', $decision->checked_at ) : ''
		);
	}

	/**
	 * Wipe the proof without touching the number.
	 *
	 * For the case where a number arrives from somewhere that did not check it.
	 * Leaving the previous evidence in place would attach it to a number it
	 * knows nothing about, which is worse than having none.
	 */
	public static function clear_proof( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}

		foreach ( array( OrderEvidence::CHECK_STATUS, OrderEvidence::REQUEST_ID, OrderEvidence::REGISTERED, OrderEvidence::REQUEST_DATE ) as $key ) {
			update_user_meta( $user_id, $key, '' );
		}
	}
}
