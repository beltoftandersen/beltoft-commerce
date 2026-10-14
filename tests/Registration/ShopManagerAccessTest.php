<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\AdminUsers;
use BeltoftCommerce\Registration\Status;

/**
 * The shop's staff decide B2B applications, not only administrators.
 *
 * WooCommerce lets a shop manager edit only users whose roles are all on its
 * woocommerce_shop_manager_editable_roles list - by default just 'customer' -
 * so the bulk approve on the Users screen answered "0 approved" to them.
 */
class ShopManagerAccessTest extends WP_UnitTestCase {

	private int $manager;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $this->manager );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_a_shop_manager_may_edit_applicants_and_partners() {
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		$partner   = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertTrue( current_user_can( 'edit_user', $applicant ) );
		$this->assertTrue( current_user_can( 'edit_user', $partner ) );
	}

	public function test_a_shop_manager_can_approve_through_the_bulk_action() {
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );

		$redirect = AdminUsers::handle_bulk( 'http://example.org/wp-admin/users.php', 'beltoft_b2b_approve', array( $applicant ) );

		$this->assertSame( Status::APPROVED, Status::get( $applicant ), $redirect );
	}

	public function test_administrators_stay_out_of_a_shop_managers_reach() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( current_user_can( 'edit_user', $admin ) );
	}
}
