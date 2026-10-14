<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\AdminUsers;
use BeltoftCommerce\Registration\Status;

class StatusTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
	}

	private function approved_partner(): int {
		$id = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		Status::approve( $id );

		return $id;
	}

	private function rejected_view_count(): string {
		$views = AdminUsers::add_view( array( 'all' => '<a>All</a>' ) );

		return $views['beltoft_b2b_rejected'];
	}

	/**
	 * Rejecting is also how access is revoked. Leaving b2b_customer in place
	 * kept wholesale prices and the trade catalog after the refusal.
	 */
	public function test_rejecting_an_approved_partner_takes_the_wholesale_role_away() {
		$id = $this->approved_partner();

		$this->assertTrue( Status::reject( $id ) );

		$roles = get_userdata( $id )->roles;
		$this->assertNotContains( 'b2b_customer', $roles );
		$this->assertContains( 'b2b_pending', $roles );
	}

	/** The account dropped out of every list: not pending, not rejected. */
	public function test_a_rejected_former_partner_appears_in_the_rejected_list() {
		$id = $this->approved_partner();

		Status::reject( $id );

		$this->assertStringContainsString( '(1)', $this->rejected_view_count() );
	}

	public function test_rejecting_an_applicant_still_leaves_the_pending_role() {
		$id = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );

		Status::reject( $id );

		$this->assertSame( array( 'b2b_pending' ), array_values( get_userdata( $id )->roles ) );
		$this->assertSame( Status::REJECTED, Status::get( $id ) );
	}

	/**
	 * Reject now changes the role, so it must not touch accounts that were
	 * never part of B2B - a bulk action run on the plain Users list would
	 * otherwise demote administrators and retail customers.
	 */
	public function test_rejecting_an_account_outside_b2b_changes_nothing() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( Status::reject( $admin ) );
		$this->assertSame( array( 'administrator' ), array_values( get_userdata( $admin )->roles ) );
		$this->assertSame( '', get_user_meta( $admin, Status::META_STATUS, true ) );
	}
}
