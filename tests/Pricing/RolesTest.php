<?php

use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;

class RolesTest extends WP_UnitTestCase {

	public function test_b2b_customer_maps_to_the_b2b_group() {
		$this->assertSame( 'b2b_standard', Roles::group_for_roles( array( 'b2b_customer' ) ) );
	}

	/**
	 * An unapproved signup must be retail for every pricing and security
	 * purpose until approval. Architecture §2.2.
	 */
	public function test_b2b_pending_is_retail_equivalent() {
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array( 'b2b_pending' ) ) );
	}

	public function test_customer_is_retail() {
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array( 'customer' ) ) );
	}

	/**
	 * Regression test for the bug §2.2 says will not be caught in review:
	 * pattern matching on the role name would grant an unapproved or
	 * experimental b2b_* role wholesale pricing.
	 */
	public function test_an_unknown_b2b_prefixed_role_is_retail() {
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array( 'b2b_experimental' ) ) );
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array( 'b2b_' ) ) );
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array( 'b2b_customer_pending' ) ) );
	}

	public function test_no_roles_at_all_is_retail() {
		$this->assertSame( Registry::retail_slug(), Roles::group_for_roles( array() ) );
	}

	public function test_a_multi_role_user_gets_the_mapped_group() {
		$this->assertSame( 'b2b_standard', Roles::group_for_roles( array( 'customer', 'b2b_customer' ) ) );
	}

	public function test_install_creates_both_roles() {
		Roles::install();

		$this->assertNotNull( get_role( 'b2b_pending' ) );
		$this->assertNotNull( get_role( 'b2b_customer' ) );
	}

	/**
	 * A role name is written once, when the role is created, and never
	 * revisited - so a role made before the translations loaded kept the
	 * English source for good. install() has to be able to correct one.
	 */
	public function test_install_renames_a_role_that_already_exists() {
		Roles::install();

		$roles = wp_roles();
		$roles->roles['b2b_pending']['name'] = 'Something Else';
		$roles->role_names['b2b_pending']    = 'Something Else';
		update_option( $roles->role_key, $roles->roles );

		Roles::install();

		$this->assertSame(
			__( 'B2B (applicant)', 'beltoft-commerce' ),
			( new WP_Roles() )->roles['b2b_pending']['name']
		);
	}

	/**
	 * The rename edits the name in place. add_role() would have refused and
	 * remove_role() would have taken the capabilities - and every user's
	 * membership - with it.
	 */
	public function test_renaming_keeps_capabilities_and_members() {
		Roles::install();

		$user = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		$caps = get_role( 'b2b_pending' )->capabilities;

		$roles = wp_roles();
		$roles->roles['b2b_pending']['name'] = 'Stale';
		update_option( $roles->role_key, $roles->roles );

		Roles::install();

		$this->assertSame( $caps, get_role( 'b2b_pending' )->capabilities );
		$this->assertContains( 'b2b_pending', get_userdata( $user )->roles );
	}

	/**
	 * Half a translated screen is worse than none: the Users filter bar shows
	 * these side by side.
	 */
	public function test_every_role_it_installs_gets_its_translated_name() {
		Roles::install();

		$roles = ( new WP_Roles() )->roles;

		$this->assertSame( __( 'B2B (applicant)', 'beltoft-commerce' ), $roles['b2b_pending']['name'] );
		$this->assertSame( __( 'B2B Customer', 'beltoft-commerce' ), $roles['b2b_customer']['name'] );
	}

	/**
	 * The name says applicant, not pending, because a REJECTED applicant keeps
	 * this role - Registration\Status leaves the account alone deliberately.
	 * Calling it "pending approval" said something untrue about those, and
	 * read as a rival to the Users screen's own "B2B por aprovar" queue.
	 */
	public function test_the_pending_role_is_not_named_after_a_decision() {
		Roles::install();

		$name = ( new WP_Roles() )->roles['b2b_pending']['name'];

		$this->assertStringNotContainsStringIgnoringCase( 'pending', $name );
		$this->assertStringNotContainsStringIgnoringCase( 'aprova', $name );
	}

	/**
	 * b2b_pending must not gain capabilities a customer lacks — it is a
	 * retail customer with a pending application, nothing more.
	 */
	public function test_pending_has_no_more_capability_than_a_customer() {
		Roles::install();

		$customer = get_role( 'customer' );
		$pending  = get_role( 'b2b_pending' );

		$extra = array_diff_key(
			array_filter( $pending->capabilities ),
			array_filter( $customer ? $customer->capabilities : array() )
		);

		$this->assertSame( array(), $extra, 'b2b_pending gained capabilities: ' . implode( ', ', array_keys( $extra ) ) );
	}
}
