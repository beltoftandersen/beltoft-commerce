<?php

use BeltoftCommerce\Vat\Evidence;
use BeltoftCommerce\Vat\Field;
use BeltoftCommerce\Vat\OrderEvidence;
use BeltoftCommerce\Vat\VatDecision;

/**
 * The number and its proof have to describe the same number, always.
 */
class EvidenceTest extends WP_UnitTestCase {

	private function decision( string $status ): VatDecision {
		$d                     = new VatDecision( $status, 'DE123456789' );
		$d->request_identifier = 'WAPIAAAA';
		$d->registered_name    = 'Beispiel GmbH';

		return $d;
	}

	private function meta( int $user_id ): array {
		return array(
			'number'  => (string) get_user_meta( $user_id, Field::META_KEY, true ),
			'status'  => (string) get_user_meta( $user_id, OrderEvidence::CHECK_STATUS, true ),
			'request' => (string) get_user_meta( $user_id, OrderEvidence::REQUEST_ID, true ),
			'name'    => (string) get_user_meta( $user_id, OrderEvidence::REGISTERED, true ),
			'date'    => (string) get_user_meta( $user_id, OrderEvidence::REQUEST_DATE, true ),
		);
	}

	public function test_a_vies_confirmed_number_keeps_its_whole_evidence() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, 'DE123456789', $this->decision( 'valid' ), 'valid' );
		$m = $this->meta( $uid );

		$this->assertSame( 'DE123456789', $m['number'] );
		$this->assertSame( 'valid', $m['status'] );
		$this->assertSame( 'WAPIAAAA', $m['request'] );
		$this->assertSame( 'Beispiel GmbH', $m['name'] );
		$this->assertNotSame( '', $m['date'] );
	}

	/**
	 * VatDecision stamps checked_at on construction, so a Portuguese number
	 * arrives carrying a timestamp despite never reaching VIES. Writing it
	 * printed "Verificado em: today" on an account nobody had verified.
	 */
	public function test_a_domestic_number_records_no_verification_date() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, '500000000', $this->decision( 'domestic' ), 'domestic' );
		$m = $this->meta( $uid );

		$this->assertSame( '500000000', $m['number'] );
		$this->assertSame( 'domestic', $m['status'] );
		$this->assertSame( '', $m['date'] );
		$this->assertSame( '', $m['request'] );
		$this->assertSame( '', $m['name'] );
	}

	/** Out of VIES's scope entirely, so there is nothing to date. */
	public function test_a_non_eu_number_records_no_verification_date() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, 'CH123456', $this->decision( '' ), '' );

		$this->assertSame( '', $this->meta( $uid )['date'] );
	}

	/** Clearing the number must not leave a date behind describing nothing. */
	public function test_clearing_the_number_clears_the_evidence() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, 'DE123456789', $this->decision( 'valid' ), 'valid' );
		Evidence::store( $uid, '', $this->decision( '' ), '' );
		$m = $this->meta( $uid );

		$this->assertSame( '', $m['number'] );
		$this->assertSame( '', $m['request'] );
		$this->assertSame( '', $m['name'] );
		$this->assertSame( '', $m['date'] );
	}

	/** A VIES outage is a consultation that happened and failed, so it is dated. */
	public function test_an_unreachable_check_still_records_when_it_was_attempted() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, 'DE123456789', $this->decision( 'unverified' ), 'unverified' );

		$this->assertNotSame( '', $this->meta( $uid )['date'] );
	}

	public function test_clear_proof_leaves_the_number_alone() {
		$uid = self::factory()->user->create();

		Evidence::store( $uid, 'DE123456789', $this->decision( 'valid' ), 'valid' );
		Evidence::clear_proof( $uid );
		$m = $this->meta( $uid );

		$this->assertSame( 'DE123456789', $m['number'] );
		$this->assertSame( '', $m['status'] );
		$this->assertSame( '', $m['request'] );
		$this->assertSame( '', $m['name'] );
		$this->assertSame( '', $m['date'] );
	}
}
