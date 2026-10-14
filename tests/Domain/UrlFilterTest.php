<?php

use BeltoftCommerce\Domain\UrlFilter;
use BeltoftCommerce\Support\Hosts;

class UrlFilterTest extends WP_UnitTestCase {

	public function tearDown(): void {
		// A leaked suspension would silently disarm the filter for every test
		// that ran after it.
		UrlFilter::resume();
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_home_url_stays_on_the_b2b_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertStringContainsString( Hosts::b2b(), home_url( '/loja/' ) );
	}

	public function test_home_url_does_not_leak_the_retail_host_on_b2b() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		// The retail host is a substring-free check: b2b.staging... contains
		// staging... so compare the parsed host, not the whole URL.
		$host = wp_parse_url( home_url( '/loja/' ), PHP_URL_HOST );
		$this->assertSame( Hosts::b2b(), $host );
	}

	/**
	 * On the retail host the stored option is returned untouched. The test
	 * install's stored home is example.org, so this asserts "unchanged",
	 * not a specific hostname — asserting the staging hostname here would
	 * test the fixture rather than the filter.
	 */
	public function test_the_retail_host_is_left_untouched() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$stored               = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );

		$this->assertSame( $stored, wp_parse_url( home_url( '/loja/' ), PHP_URL_HOST ) );
		$this->assertNotSame( Hosts::b2b(), wp_parse_url( home_url( '/loja/' ), PHP_URL_HOST ) );
	}

	public function test_site_url_follows_the_same_rule() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( Hosts::b2b(), wp_parse_url( site_url( '/wp-login.php' ), PHP_URL_HOST ) );
	}

	public function test_interactive_admin_and_core_login_urls_use_the_canonical_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( Hosts::retail(), wp_parse_url( admin_url(), PHP_URL_HOST ) );
		$this->assertSame( Hosts::retail(), wp_parse_url( admin_url( 'edit.php?post_type=page' ), PHP_URL_HOST ) );
		$this->assertSame( Hosts::retail(), wp_parse_url( wp_login_url( home_url( '/loja/' ) ), PHP_URL_HOST ) );
	}

	public function test_b2b_frontend_endpoints_and_logout_stay_on_b2b() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( Hosts::b2b(), wp_parse_url( admin_url( 'admin-ajax.php?action=probe' ), PHP_URL_HOST ) );
		$this->assertSame( Hosts::b2b(), wp_parse_url( admin_url( 'admin-post.php?action=probe' ), PHP_URL_HOST ) );
		$this->assertSame( Hosts::b2b(), wp_parse_url( wp_logout_url( home_url( '/entrar/' ) ), PHP_URL_HOST ) );
	}

	/**
	 * Cron, WP-CLI and queued jobs have no HTTP_HOST. These must fall back
	 * to the stored option rather than producing a hostless URL.
	 */
	public function test_it_leaves_urls_alone_with_no_http_host() {
		unset( $_SERVER['HTTP_HOST'] );

		$this->assertNotEmpty( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * The gap the HTTP_HOST rule above does not close.
	 *
	 * Action Scheduler's async runner reaches the site over HTTP, so a queued
	 * job CAN arrive with a host - the host of whichever page happened to spawn
	 * the runner. It drains the whole queue, so one trade sale decided the URLs
	 * in every job in that batch, including other people's order emails.
	 */
	public function test_queued_work_ignores_the_host_it_arrived_on() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->assertSame( Hosts::b2b(), wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'the fixture itself' );

		UrlFilter::suspend();
		$this->assertNotSame( Hosts::b2b(), wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		UrlFilter::resume();
		$this->assertSame( Hosts::b2b(), wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'and live trade requests are untouched' );
	}

	public function test_canonical_reads_past_the_filter_either_way() {
		$expected = trailingslashit( get_option( 'home' ) );

		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( $expected, UrlFilter::canonical(), 'outside queue processing' );

		UrlFilter::suspend();
		$this->assertSame( $expected, UrlFilter::canonical(), 'inside it' );

		// ...and reading it did not cancel the suspension it was called under.
		$this->assertNotSame( Hosts::b2b(), wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		UrlFilter::resume();
	}
}
