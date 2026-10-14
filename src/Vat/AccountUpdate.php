<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a VAT number a partner typed into their account gets stored.
 *
 * Separated from AccountEndpoint so the rule can be tested without a request,
 * a user or a network call. Everything here is a pure function of a decision
 * Validator already made.
 *
 * THE RULE IS REGISTRATION'S RULE. A partner supplied this number once when
 * they applied; changing it later must not be judged more harshly than
 * supplying it in the first place, or a typo becomes a support ticket instead
 * of an edit. So, exactly as Registrar does:
 *
 * | Decision                  | Stored? | Why                                 |
 * |---------------------------|---------|-------------------------------------|
 * | Empty submission          | yes     | Clears the number. A partner may    |
 * |                           |         | genuinely not have one.             |
 * | PT, check digit fails     | NO      | Caught here because VIES never sees |
 * |                           |         | a PT number. See PtNif.             |
 * | PT, check digit passes    | yes     | Domestic. No exemption either way.  |
 * | Non-PT EU, VIES valid     | yes     | With the evidence VIES returned.    |
 * | Non-PT EU, VIES invalid   | NO      | The one case that is a real answer  |
 * |                           |         | of "no such number".                |
 * | VIES unreachable          | yes     | An outage is "we do not know", not  |
 * |                           |         | "no". Blocking a save because a     |
 * |                           |         | member state is down is a closed    |
 * |                           |         | door for something we cannot see.   |
 * | Non-EU                    | yes     | Out of scope here, handled as an    |
 * |                           |         | export at checkout.                 |
 *
 * Note the asymmetry with checkout, and that it is deliberate. At checkout an
 * invalid number BLOCKS the order, because the alternative is charging the
 * wrong tax on a real sale. Here it only refuses the save; nothing is bought,
 * so there is nothing to protect but the stored value itself.
 */
class AccountUpdate {

	/** Stored, and the number is now set. */
	public const STORED = 'stored';

	/** Stored, and the number is now empty. */
	public const CLEARED = 'cleared';

	/** Not stored. The previous value, whatever it was, stands. */
	public const REFUSED = 'refused';

	/**
	 * @param VatDecision $decision  What Validator made of the submission.
	 * @param string      $submitted The raw string the partner typed.
	 *
	 * @return array{outcome:string, status:string, reason:string}
	 *         `outcome` is one of the constants above. `status` is the
	 *         decision status to record alongside the number. `reason` is a
	 *         machine key for the refusal, empty when nothing was refused.
	 */
	public static function from_decision( VatDecision $decision, string $submitted ): array {
		if ( '' === trim( $submitted ) ) {
			return array(
				'outcome' => self::CLEARED,
				'status'  => '',
				'reason'  => '',
			);
		}

		/*
		 * Typed something, but nothing survived normalisation.
		 *
		 * Validator strips everything that is not a letter or a digit, so "-"
		 * and "!!!" reach here as an empty number with a non-empty submission.
		 * Without this they were treated as a successful save of "", which
		 * deleted the partner's VAT number while telling them it was stored.
		 */
		if ( '' === $decision->vat_number ) {
			return array(
				'outcome' => self::REFUSED,
				'status'  => $decision->status,
				'reason'  => 'unreadable',
			);
		}

		if ( 'domestic' === $decision->status && ! PtNif::is_valid( $submitted ) ) {
			return array(
				'outcome' => self::REFUSED,
				'status'  => $decision->status,
				'reason'  => 'pt_check_digit',
			);
		}

		if ( 'invalid' === $decision->status ) {
			return array(
				'outcome' => self::REFUSED,
				'status'  => $decision->status,
				'reason'  => 'vies_invalid',
			);
		}

		return array(
			'outcome' => self::STORED,
			'status'  => $decision->status,
			'reason'  => '',
		);
	}
}
