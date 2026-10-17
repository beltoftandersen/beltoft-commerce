<?php

use BeltoftCommerce\Support\MuPlugin;

/** The early host handler is shipped with the plugin and put in place by it. */
class MuPluginTest extends WP_UnitTestCase {

	private ?string $before = null;

	public function setUp(): void {
		parent::setUp();
		$this->before = is_readable( MuPlugin::target() ) ? (string) file_get_contents( MuPlugin::target() ) : null;
	}

	public function tearDown(): void {
		if ( null === $this->before ) {
			@unlink( MuPlugin::target() );
		} else {
			file_put_contents( MuPlugin::target(), $this->before );
		}

		parent::tearDown();
	}

	public function test_it_installs_and_updates_the_shipped_copy() {
		@unlink( MuPlugin::target() );
		$this->assertFalse( MuPlugin::is_current() );

		$this->assertTrue( MuPlugin::install() );
		$this->assertTrue( MuPlugin::is_current() );

		file_put_contents( MuPlugin::target(), "<?php // an older version\n" );
		MuPlugin::maybe_install();
		$this->assertTrue( MuPlugin::is_current() );
	}

	public function test_deactivation_removes_only_its_own_copy() {
		MuPlugin::install();
		MuPlugin::remove();
		$this->assertFileDoesNotExist( MuPlugin::target() );

		file_put_contents( MuPlugin::target(), "<?php // changed by hand\n" );
		MuPlugin::remove();
		$this->assertFileExists( MuPlugin::target() );
	}
}
