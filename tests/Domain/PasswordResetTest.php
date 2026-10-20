<?php

use BeltoftCommerce\Domain\PasswordReset;
use BeltoftCommerce\Support\Hosts;

class PasswordResetTest extends WP_UnitTestCase {

	use TradeHosts;

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		parent::tearDown();
	}

	public function test_it_records_the_host_the_reset_was_requested_from() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$user                 = get_userdata( $this->factory->user->create() );

		PasswordReset::remember_host( $user->user_login );

		$this->assertSame( Hosts::b2b(), PasswordReset::host_for_user( $user->ID ) );
	}

	/**
	 * User meta, not a transient: the reset email may be rendered by a
	 * queued job after the request has ended.
	 */
	public function test_the_host_survives_the_request_ending() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$user                 = get_userdata( $this->factory->user->create() );
		PasswordReset::remember_host( $user->user_login );

		unset( $_SERVER['HTTP_HOST'] );

		$this->assertSame( Hosts::b2b(), PasswordReset::host_for_user( $user->ID ) );
	}

	public function test_it_defaults_to_retail_for_a_user_with_no_recorded_host() {
		$user = $this->factory->user->create();

		$this->assertSame( Hosts::retail(), PasswordReset::host_for_user( $user ) );
	}

	public function test_the_reset_message_links_to_the_initiating_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$user                 = get_userdata( $this->factory->user->create() );
		PasswordReset::remember_host( $user->user_login );

		$message = PasswordReset::filter_message(
			'Reset here: https://' . Hosts::retail() . '/wp-login.php?action=rp&key=abc',
			'abc',
			$user->user_login,
			(array) $user
		);

		$this->assertStringContainsString( Hosts::b2b(), $message );
	}

	public function test_a_reset_returns_to_the_trade_host_it_was_asked_on() {
		$this->use_trade_hosts();
		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$user                 = get_userdata( $this->factory->user->create() );

		PasswordReset::remember_host( $user->user_login );
		unset( $_SERVER['HTTP_HOST'] );

		$this->assertSame( self::$en_trade, PasswordReset::host_for_user( $user->ID ) );
		$this->assertStringContainsString( '://' . self::$en_trade . '/', PasswordReset::filter_message( 'https://' . Hosts::retail() . '/x', 'k', $user->user_login, (array) $user ) );

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertSame( 'https://' . self::$en_trade . '/account/', PasswordReset::filter_redirect( 'https://' . Hosts::retail() . '/account/' ) );
	}
}
