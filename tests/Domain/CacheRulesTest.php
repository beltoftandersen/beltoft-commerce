<?php

use BeltoftCommerce\Domain\CacheRules;
use BeltoftCommerce\Support\Hosts;

class CacheRulesTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_the_b2b_host_is_declared_uncacheable() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( 'no-store, no-cache, must-revalidate, private', CacheRules::cache_control() );
	}

	public function test_the_retail_host_gets_no_directive_from_us() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertNull( CacheRules::cache_control() );
	}

	/**
	 * The guest bucket on the retail domain is the whole performance story,
	 * so this must never start emitting a no-store there.
	 */
	public function test_it_never_declares_the_retail_host_uncacheable() {
		foreach ( array( Hosts::retail(), 'example.org', '' ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertNull( CacheRules::cache_control(), "host {$host} must not be marked no-store" );
		}
	}
}
