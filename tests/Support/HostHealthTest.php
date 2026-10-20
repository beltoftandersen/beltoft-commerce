<?php

use BeltoftCommerce\Frontend\HostPages;
use BeltoftCommerce\Plugin;
use BeltoftCommerce\Support\HostHealth;
use BeltoftCommerce\Support\Hosts;

/** The trade hosts in Site Health and the admin notice (spec 2026-10-20, §1.1). */
class HostHealthTest extends WP_UnitTestCase {

	use TradeHosts;

	/** @var array<string,array> host-and-path => canned response */
	private array $responses = array();

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		remove_all_filters( 'pre_http_request' );
		delete_option( HostPages::OPTION );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_tests_and_the_debug_section_are_registered() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );

		$this->assertArrayHasKey( 'beltoft_commerce_trade_hosts', $tests['direct'] );
		$this->assertArrayHasKey( 'beltoft_commerce_trade_hosts_reachable', $tests['async'] );
		$this->assertArrayHasKey( 'beltoft-commerce-hosts', apply_filters( 'debug_information', array() ) );
	}

	public function test_a_single_trade_host_is_good() {
		$this->assertSame( 'good', HostHealth::direct_test()['status'] );
	}

	public function test_a_bad_map_is_critical_and_named() {
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => 'b2b.other.example', 'en' => self::$en_trade ) );

		$result = HostHealth::direct_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'BELTOFT_B2B_HOST', $result['description'] );
	}

	public function test_the_debug_section_lists_each_host_its_language_and_login() {
		$this->use_trade_hosts();

		$fields = HostHealth::debug_information( array() )['beltoft-commerce-hosts']['fields'];
		$values = implode( "\n", array_map( static fn( $f ) => $f['label'] . ' ' . $f['value'], $fields ) );

		$this->assertStringContainsString( self::$en_trade, $values );
		$this->assertStringContainsString( '/sign-in/', $values );
		$this->assertStringContainsString( Hosts::b2b(), $values );
		$this->assertStringContainsString( '/entrar/', $values );
	}

	/** A HostPages page with no copy in a trade host's language: that host has no such page. */
	public function test_a_root_page_without_a_copy_in_a_trade_hosts_language_is_reported() {
		$this->use_trade_hosts();
		$loja = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-loja' ) );
		update_option( HostPages::OPTION, array( 'loja' => $loja ) );

		$problems = implode( "\n", HostHealth::content_problems() );
		$this->assertStringContainsString( 'loja', $problems );
		$this->assertStringContainsString( 'en', $problems );

		$copy = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'b2b-shop' ) );
		$this->translation( $loja, $copy, 'en' );
		$this->assertStringNotContainsString( '"loja"', implode( "\n", HostHealth::content_problems() ) );
	}

	/** A trade path that names no page in its language. */
	public function test_a_trade_path_without_its_page_is_reported() {
		$this->use_trade_hosts();
		$entrar = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'entrar' ) );
		$sign   = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'sign-in' ) );
		$this->translation( $entrar, $sign, 'en' );

		$problems = implode( "\n", HostHealth::content_problems() );

		$this->assertStringNotContainsString( '/sign-in/', $problems );
		$this->assertStringContainsString( '/apply/', $problems );
	}

	private function respond( string $url, int $status, array $headers = array() ): void {
		$this->responses[ $url ] = array(
			'headers'  => $headers,
			'body'     => '',
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_the_reachability_check_reads_each_trade_hosts_robots_and_gate() {
		$this->use_trade_hosts();
		add_filter( 'pre_http_request', fn( $pre, $args, $url ) => $this->responses[ $url ] ?? new WP_Error( 'unexpected', $url ), 10, 3 );

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$this->respond( 'https://' . $host . '/robots.txt', 200, array( 'x-robots-tag' => 'noindex, nofollow' ) );
		}
		$this->respond( 'https://' . Hosts::b2b() . '/', 302, array( 'location' => 'https://' . Hosts::b2b() . '/entrar/' ) );
		$this->respond( 'https://' . self::$en_trade . '/', 302, array( 'location' => 'https://' . self::$en_trade . '/sign-in/' ) );

		$this->assertSame( 'good', HostHealth::async_test()['status'] );

		// The English trade host sending visitors to the Portuguese login is a fault.
		$this->respond( 'https://' . self::$en_trade . '/', 302, array( 'location' => 'https://' . self::$en_trade . '/entrar/' ) );
		$result = HostHealth::async_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( self::$en_trade, $result['description'] );
	}

	public function test_the_admin_notice_prints_the_problems() {
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => 'b2b.other.example' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Plugin::host_notices();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'BELTOFT_B2B_HOST', $html );

		$this->forget_trade_hosts();
		ob_start();
		Plugin::host_notices();
		$this->assertSame( '', ob_get_clean() );
	}
}
