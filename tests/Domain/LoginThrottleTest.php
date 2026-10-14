<?php

use BeltoftCommerce\Domain\LoginThrottle;
use BeltoftCommerce\Support\Hosts;

class LoginThrottleTest extends WP_UnitTestCase {

	/** Reported refusals, captured instead of written to syslog. */
	private array $reported = array();

	/** A fresh address per test, so counters cannot leak between them. */
	private static int $octet = 10;

	public function setUp(): void {
		parent::setUp();

		$_SERVER['HTTP_HOST']   = Hosts::b2b();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( ++self::$octet );

		$this->reported = array();

		// Capturing the report keeps the suite out of the system log.
		add_filter( 'beltoft_commerce_login_syslog', array( $this, 'capture' ), 10, 2 );

		$this->resetReportedFlag();
	}

	public function tearDown(): void {
		remove_filter( 'beltoft_commerce_login_syslog', array( $this, 'capture' ), 10 );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$this->resetReportedFlag();
		parent::tearDown();
	}

	public function capture( $report, $limit = '' ) {
		$this->reported[] = $limit;

		return false;
	}

	/** report() writes at most one line per request; each test is a request. */
	private function resetReportedFlag(): void {
		$flag = new ReflectionProperty( LoginThrottle::class, 'reported' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
	}

	private function fail_times( int $times, string $username ): void {
		for ( $i = 0; $i < $times; $i++ ) {
			LoginThrottle::record_failure( $username );
		}
	}

	public function test_a_few_wrong_passwords_do_not_block_anyone() {
		$this->fail_times( 5, 'parceiro@example.com' );

		$user = new WP_User( 0 );

		$this->assertSame( $user, LoginThrottle::maybe_block( $user, 'parceiro@example.com', 'segredo' ) );
		$this->assertSame( array(), $this->reported, 'Nothing to report while under the limit.' );
	}

	public function test_one_address_working_through_passwords_is_blocked() {
		// MAX_PER_IP is 12; the account limit is lower, so vary the account.
		for ( $i = 0; $i < 13; $i++ ) {
			LoginThrottle::record_failure( 'conta' . $i . '@example.com' );
		}

		$result = LoginThrottle::maybe_block( null, 'outra@example.com', 'segredo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'beltoft_login_throttled', $result->get_error_code() );
		$this->assertSame( array( 'ip' ), $this->reported );
	}

	public function test_one_account_tried_from_many_addresses_is_blocked() {
		$username = 'alvo@example.com';

		// Eight failures, each from a different address, so no IP limit is hit.
		for ( $i = 0; $i < 8; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( $i + 1 );
			LoginThrottle::record_failure( $username );
		}

		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$result                 = LoginThrottle::maybe_block( null, $username, 'segredo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'account' ), $this->reported, 'The account limit is the one that tripped.' );
	}

	public function test_a_refusal_is_reported_once_however_often_it_is_consulted() {
		$this->fail_times( 13, 'alvo@example.com' );

		LoginThrottle::maybe_block( null, 'alvo@example.com', 'segredo' );
		LoginThrottle::maybe_block( null, 'alvo@example.com', 'segredo' );
		LoginThrottle::maybe_block( null, 'alvo@example.com', 'segredo' );

		$this->assertCount( 1, $this->reported );
	}

	public function test_a_proxy_header_cannot_buy_a_fresh_counter() {
		/*
		 * The regression this locks down. ip_key() once preferred
		 * CF-Connecting-IP, which nothing on this host overwrites - so a
		 * client could send a new value per request and never be counted.
		 */
		$this->fail_times( 13, 'alvo@example.com' );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';

		$this->assertInstanceOf(
			WP_Error::class,
			LoginThrottle::maybe_block( null, 'alvo@example.com', 'segredo' )
		);
	}

	public function test_getting_it_right_clears_the_slate() {
		$username = 'parceiro@example.com';
		$this->fail_times( 13, $username );

		$this->assertInstanceOf( WP_Error::class, LoginThrottle::maybe_block( null, $username, 'segredo' ) );

		LoginThrottle::clear( $username, null );
		$this->resetReportedFlag();

		$user = new WP_User( 0 );
		$this->assertSame( $user, LoginThrottle::maybe_block( $user, $username, 'segredo' ) );
	}

	public function test_an_empty_password_is_not_an_attempt() {
		$this->fail_times( 13, 'alvo@example.com' );

		$user = new WP_User( 0 );

		$this->assertSame( $user, LoginThrottle::maybe_block( $user, 'alvo@example.com', '' ) );
	}

	public function test_retail_is_never_throttled() {
		$this->fail_times( 13, 'cliente@example.com' );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$user                 = new WP_User( 0 );

		$this->assertSame( $user, LoginThrottle::maybe_block( $user, 'cliente@example.com', 'segredo' ) );
		$this->assertSame( array(), $this->reported );
	}

	public function test_reporting_can_be_switched_off_without_losing_the_block() {
		remove_filter( 'beltoft_commerce_login_syslog', array( $this, 'capture' ), 10 );
		add_filter( 'beltoft_commerce_login_syslog', '__return_false' );

		$this->fail_times( 13, 'alvo@example.com' );

		$this->assertInstanceOf(
			WP_Error::class,
			LoginThrottle::maybe_block( null, 'alvo@example.com', 'segredo' )
		);

		remove_filter( 'beltoft_commerce_login_syslog', '__return_false' );
	}
}
