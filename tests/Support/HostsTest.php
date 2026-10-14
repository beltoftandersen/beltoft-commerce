<?php

use BeltoftCommerce\Support\Hosts;

class HostsTest extends WP_UnitTestCase {

	public function test_it_identifies_the_b2b_host() {
		$this->assertTrue( Hosts::is_b2b_host( Hosts::b2b() ) );
	}

	public function test_it_does_not_treat_the_retail_host_as_b2b() {
		$this->assertFalse( Hosts::is_b2b_host( Hosts::retail() ) );
	}

	/**
	 * HTTP_HOST carries the port on non-standard ports, and a raw ===
	 * comparison silently returns retail behaviour on the B2B host.
	 */
	public function test_it_ignores_port_and_case() {
		$this->assertTrue( Hosts::is_b2b_host( strtoupper( Hosts::b2b() ) . ':443' ) );
	}

	public function test_an_unknown_host_is_not_b2b() {
		$this->assertFalse( Hosts::is_b2b_host( 'example.invalid' ) );
	}
}
