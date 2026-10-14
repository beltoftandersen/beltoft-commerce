<?php

use BeltoftCommerce\Vat\PtNif;

class PtNifTest extends WP_UnitTestCase {

	public function test_check_digit_accepts_well_formed_numbers() {
		foreach ( array( '500000000', '198701101', '234567899', '999999990' ) as $nif ) {
			$this->assertTrue( PtNif::is_valid( $nif ), $nif );
		}
	}

	/** One digit out is the typo this exists to catch. */
	public function test_a_wrong_check_digit_is_rejected() {
		$this->assertFalse( PtNif::is_valid( '500000001' ) );
	}

	/**
	 * The reason this check exists at all: Validator short-circuits on PT and
	 * never asks VIES, so without it "1" would reach the key that checkout and
	 * order evidence both read.
	 */
	public function test_placeholders_and_wrong_lengths_are_rejected() {
		foreach ( array( '', '1', '12345678', '1234567890', 'abcdefghi' ) as $nif ) {
			$this->assertFalse( PtNif::is_valid( $nif ), var_export( $nif, true ) );
		}
	}

	/** Partners paste numbers with spaces and dots in them. */
	public function test_separators_are_ignored() {
		$this->assertTrue( PtNif::is_valid( '500 000 000' ) );
		$this->assertTrue( PtNif::is_valid( '500.000.000' ) );
	}
}
