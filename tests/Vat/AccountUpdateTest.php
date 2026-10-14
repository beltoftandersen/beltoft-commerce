<?php

use BeltoftCommerce\Vat\AccountUpdate;
use BeltoftCommerce\Vat\VatDecision;

/**
 * One row per line of the table in AccountUpdate's docblock.
 */
class AccountUpdateTest extends WP_UnitTestCase {

	private function decision( string $status ): VatDecision {
		return new VatDecision( $status, 'X' );
	}

	public function test_an_empty_submission_clears_the_number() {
		$result = AccountUpdate::from_decision( $this->decision( '' ), '' );

		$this->assertSame( AccountUpdate::CLEARED, $result['outcome'] );
		$this->assertSame( '', $result['reason'] );
	}

	/** Whitespace is not a number. */
	public function test_whitespace_only_also_clears() {
		$this->assertSame(
			AccountUpdate::CLEARED,
			AccountUpdate::from_decision( $this->decision( '' ), "  \t " )['outcome']
		);
	}

	public function test_a_portuguese_number_with_a_bad_check_digit_is_refused() {
		$result = AccountUpdate::from_decision( $this->decision( 'domestic' ), '500000001' );

		$this->assertSame( AccountUpdate::REFUSED, $result['outcome'] );
		$this->assertSame( 'pt_check_digit', $result['reason'] );
	}

	public function test_a_portuguese_number_that_checks_out_is_stored() {
		$result = AccountUpdate::from_decision( $this->decision( 'domestic' ), '500000000' );

		$this->assertSame( AccountUpdate::STORED, $result['outcome'] );
		$this->assertSame( 'domestic', $result['status'] );
	}

	public function test_a_vies_confirmed_number_is_stored() {
		$result = AccountUpdate::from_decision( $this->decision( 'valid' ), 'ES12345678Z' );

		$this->assertSame( AccountUpdate::STORED, $result['outcome'] );
		$this->assertSame( 'valid', $result['status'] );
	}

	/** The one answer that is a real "no such number". */
	public function test_a_vies_rejected_number_is_refused() {
		$result = AccountUpdate::from_decision( $this->decision( 'invalid' ), 'ES00000000X' );

		$this->assertSame( AccountUpdate::REFUSED, $result['outcome'] );
		$this->assertSame( 'vies_invalid', $result['reason'] );
	}

	/**
	 * An outage is "we do not know", never "no" - the same reading registration
	 * and checkout already take. Refusing the save here would make a member
	 * state's downtime into a closed door on our own account page.
	 */
	public function test_a_vies_outage_still_stores_the_number() {
		$result = AccountUpdate::from_decision( $this->decision( 'unverified' ), 'DE123456789' );

		$this->assertSame( AccountUpdate::STORED, $result['outcome'] );
		$this->assertSame( 'unverified', $result['status'] );
	}

	/**
	 * Validator strips everything that is not a letter or a digit, so these
	 * arrive as a non-empty submission with an empty number. They used to be
	 * treated as a successful save of "", deleting the partner's VAT number
	 * while the page told them it had been stored.
	 */
	public function test_input_that_normalises_away_to_nothing_is_refused() {
		foreach ( array( '-', '  -- ', '!!!', '. . .' ) as $junk ) {
			$decision = new VatDecision( '', '' );
			$result   = AccountUpdate::from_decision( $decision, $junk );

			$this->assertSame( AccountUpdate::REFUSED, $result['outcome'], var_export( $junk, true ) );
			$this->assertSame( 'unreadable', $result['reason'], var_export( $junk, true ) );
		}
	}

	/** Out of scope for VIES; handled as an export at checkout. */
	public function test_a_non_eu_number_is_stored() {
		$decision = new VatDecision( '', 'CH123456' );
		$result   = AccountUpdate::from_decision( $decision, 'CH123456' );

		$this->assertSame( AccountUpdate::STORED, $result['outcome'] );
	}
}
