<?php

use BeltoftCommerce\Support\Installer;

class InstallerTest extends WP_UnitTestCase {

	public function tearDown(): void {
		wp_clear_scheduled_hook( Installer::BACKFILL_HOOK );
		parent::tearDown();
	}

	/** A deploy without the manual install still gets the roles and the backfill job. */
	public function test_the_first_admin_request_installs_what_is_missing() {
		delete_option( Installer::VERSION_OPTION );
		remove_role( 'b2b_customer' );

		Installer::maybe_install();

		$this->assertNotNull( get_role( 'b2b_customer' ) );
		$this->assertNotNull( get_role( 'b2b_pending' ) );
		$this->assertSame( Installer::VERSION, get_option( Installer::VERSION_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Installer::BACKFILL_HOOK ) );
	}

	public function test_it_runs_once_per_version() {
		update_option( Installer::VERSION_OPTION, Installer::VERSION );
		remove_role( 'b2b_customer' );

		Installer::maybe_install();

		$this->assertNull( get_role( 'b2b_customer' ) );
	}
}
