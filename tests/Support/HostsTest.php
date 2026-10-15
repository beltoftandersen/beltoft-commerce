<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Domain\UrlFilter;
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

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_a_trailing_dot_is_the_same_host() {
		$this->assertTrue( Hosts::is_b2b_host( Hosts::b2b() . '.' ) );
	}

	/** No trade host configured: nothing is the trade host, nothing redirects there. */
	public function test_an_unconfigured_trade_host_matches_nothing() {
		add_filter( 'beltoft_commerce_b2b_host', '__return_empty_string' );

		$this->assertFalse( Hosts::is_b2b_configured() );
		$this->assertFalse( Hosts::is_b2b_host( '' ) );
		$this->assertFalse( Hosts::is_b2b_host( 'b2b.staging.mindthetrash.pt' ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertFalse( HostRoutes::should_redirect( '/encomenda-rapida/' ) );
	}

	/** Another host the install answers on is not retail either. */
	public function test_only_the_retail_host_is_retail() {
		$this->assertTrue( Hosts::is_retail_host( strtoupper( Hosts::retail() ) ) );
		$this->assertFalse( Hosts::is_retail_host( Hosts::b2b() ) );
		$this->assertFalse( Hosts::is_retail_host( 'other.example.invalid' ) );
		$this->assertFalse( Hosts::is_retail_host( '' ) );
	}

	/** Another plugin's option_home filter does not change the canonical address. */
	public function test_canonical_ignores_every_home_filter() {
		$expected = trailingslashit( Hosts::stored_home() );
		$other    = static fn() => 'https://other.example.invalid';

		add_filter( 'option_home', $other, 99 );

		$this->assertSame( $expected, UrlFilter::canonical() );
		$this->assertStringNotContainsString( 'other.example.invalid', UrlFilter::canonical() );
	}
}
