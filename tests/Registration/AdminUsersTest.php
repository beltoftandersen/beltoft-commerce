<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\AdminUsers;
use BeltoftCommerce\Registration\Status;

class AdminUsersTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
	}

	public function tearDown(): void {
		unset( $_GET[ 'beltoft_b2b_status' ] );
		parent::tearDown();
	}

	private function applicant( string $status = '' ): int {
		$id = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );

		if ( '' !== $status ) {
			update_user_meta( $id, Status::META_STATUS, $status );
		}

		return $id;
	}

	private function views(): array {
		return AdminUsers::add_view( array( 'all' => '<a>All</a>', 'b2b_pending' => '<a>B2B</a>' ) );
	}

	/**
	 * The role link counted applications AND refusals as one number, next to a
	 * pending count that excluded refusals - two links about "pending" that
	 * disagreed. The two questions a person has get a link each instead.
	 */
	public function test_the_pending_role_link_is_replaced_by_the_two_real_questions() {
		$views = $this->views();

		$this->assertArrayNotHasKey( 'b2b_pending', $views );
		$this->assertArrayHasKey( 'beltoft_b2b_pending', $views );
		$this->assertArrayHasKey( 'beltoft_b2b_rejected', $views );
		$this->assertArrayHasKey( 'all', $views, 'Everything else is left alone.' );
	}

	/** Approving grants b2b_customer, so WordPress's own role link is that list. */
	public function test_there_is_no_separate_approved_link() {
		$this->assertArrayNotHasKey( 'beltoft_b2b_approved', $this->views() );
	}

	public function test_the_counts_split_applications_from_refusals() {
		$this->applicant( Status::PENDING );
		$this->applicant( Status::PENDING );
		$this->applicant( Status::REJECTED );

		$views = $this->views();

		$this->assertStringContainsString( '(2)', $views['beltoft_b2b_pending'] );
		$this->assertStringContainsString( '(1)', $views['beltoft_b2b_rejected'] );
	}

	/**
	 * Status::get() calls anything it does not recognise pending, and this has
	 * to agree - a role set by hand, or a value left by an older version, is
	 * still an application nobody has answered.
	 */
	public function test_an_account_with_no_or_unknown_status_counts_as_pending() {
		$this->applicant();
		$this->applicant( 'something-else' );

		$this->assertStringContainsString( '(2)', $this->views()['beltoft_b2b_pending'] );
	}

	/**
	 * WordPress marks "All" current because it does not recognise this query
	 * arg, which highlighted two links at once - the bar claiming the list was
	 * both everybody and a subset.
	 */
	public function test_only_the_active_link_is_marked_current() {
		$_GET['beltoft_b2b_status'] = Status::REJECTED;

		$views = AdminUsers::add_view( array(
			'all'         => '<a href="#" class="current">All</a>',
			'b2b_pending' => '<a>B2B</a>',
		) );

		$this->assertStringNotContainsString( 'current', $views['all'] );
		$this->assertStringNotContainsString( 'current', $views['beltoft_b2b_pending'] );
		$this->assertStringContainsString( 'current', $views['beltoft_b2b_rejected'] );
	}

	public function test_the_highlight_is_left_alone_when_no_b2b_filter_is_on() {
		$views = AdminUsers::add_view( array( 'all' => '<a href="#" class="current">All</a>' ) );

		$this->assertStringContainsString( 'current', $views['all'] );
	}

	/**
	 * The approved filter looked under b2b_pending, where an approved account
	 * never is - so it answered "none", silently, for as long as nothing linked
	 * to it. Reachable by URL, so it still has to work.
	 *
	 * ONE filter_query test, deliberately. The method guards itself with a
	 * process-static so that the count queries behind the view links cannot
	 * re-enter it (pre_get_users fires for every WP_User_Query, not just the
	 * list table's), and that static does not reset between tests. A second
	 * test would be skipped by the guard and pass for the wrong reason.
	 */
	public function test_the_approved_filter_looks_where_approved_accounts_are() {
		$approved = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		update_user_meta( $approved, Status::META_STATUS, Status::APPROVED );
		$this->applicant( Status::REJECTED );

		set_current_screen( 'users' );
		$GLOBALS['pagenow']         = 'users.php';
		$_GET['beltoft_b2b_status'] = Status::APPROVED;

		$query = new WP_User_Query();
		$query->set( 'role', 'unchanged' );
		AdminUsers::filter_query( $query );

		$this->assertSame( 'b2b_customer', $query->get( 'role' ), 'not b2b_pending, where it used to look' );

		$found = get_users( array(
			'role'       => $query->get( 'role' ),
			'meta_query' => $query->get( 'meta_query' ),
			'fields'     => 'ID',
		) );

		$this->assertSame( array( $approved ), array_map( 'intval', $found ) );
	}
}
