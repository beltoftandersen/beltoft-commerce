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
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-users' );

		$redirect = AdminUsers::handle_bulk( 'http://example.org/wp-admin/users.php', 'beltoft_b2b_approve', array( $applicant ) );

		unset( $_REQUEST['_wpnonce'] );
		$this->assertSame( Status::APPROVED, Status::get( $applicant ), $redirect );
	}

	/** A custom bulk action gets no nonce check from core; a forged request is refused. */
	public function test_the_bulk_action_without_the_nonce_is_refused() {
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		unset( $_REQUEST['_wpnonce'] );

		try {
			AdminUsers::handle_bulk( 'http://example.org/wp-admin/users.php', 'beltoft_b2b_approve', array( $applicant ) );
			$this->fail( 'the request went through' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( Status::APPROVED, Status::get( $applicant ) );
		}
	}

	public function test_administrators_stay_out_of_a_shop_managers_reach() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( current_user_can( 'edit_user', $admin ) );
	}

	/** The list form's request, as WordPress sends it: approved on load, nonce checked. */
	public function test_the_bulk_approve_runs_on_the_request_with_the_nonce() {
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		$_REQUEST  = array(
			'action'           => 'beltoft_b2b_approve',
			'users'            => array( $applicant ),
			'_wpnonce'         => wp_create_nonce( 'bulk-users' ),
			'_wp_http_referer' => '/wp-admin/users.php',
		);

		$this->assertNotNull( AdminUsers::bulk_from_request() );
		$this->assertSame( Status::APPROVED, Status::get( $applicant ) );
		$_REQUEST = array();
	}

	public function test_a_forged_bulk_request_is_refused() {
		$applicant = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		$_REQUEST  = array( 'action' => 'beltoft_b2b_reject', 'users' => array( $applicant ) );

		try {
			AdminUsers::bulk_from_request();
			$this->fail( 'went through without the nonce' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( Status::REJECTED, Status::get( $applicant ) );
		} finally {
			$_REQUEST = array();
		}
	}
}
