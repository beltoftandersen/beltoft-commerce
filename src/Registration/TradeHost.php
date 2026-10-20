<?php

namespace BeltoftCommerce\Registration;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A partner's own trade host, remembered on the account.
 *
 * With one trade host per language (BELTOFT_B2B_HOSTS), what is sent to a
 * partner from nowhere in particular - an application email from wp-admin,
 * a back-in-stock link from a stock sync, an order staff made for them - has
 * to name one of them. It names the host the partner applied on or last
 * signed in on, in that host's language (spec 2026-10-20, §3.2). Cookies are
 * host-only, so that is also the host where they are signed in.
 */
class TradeHost {

	public const META_KEY = '_beltoft_trade_host';

	public static function register(): void {
		// Before the application email at 10, which reads it.
		add_action( 'beltoft_commerce_b2b_applied', array( __CLASS__, 'record' ), 1 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/** Records the trade host this request is on, if it is on one. */
	public static function record( $user_id ): void {
		$host = Hosts::current_b2b();

		if ( '' !== $host && (int) $user_id > 0 && get_user_meta( (int) $user_id, self::META_KEY, true ) !== $host ) {
			update_user_meta( (int) $user_id, self::META_KEY, $host );
		}
	}

	/**
	 * @param mixed $user_login
	 * @param mixed $user
	 */
	public static function on_login( $user_login, $user = null ): void {
		if ( $user instanceof \WP_User ) {
			self::record( $user->ID );
		}
	}

	/** The partner's trade host: the recorded one while it is still a trade host, else the default trade host. */
	public static function for_user( int $user_id ): string {
		$host = (string) get_user_meta( $user_id, self::META_KEY, true );

		return '' !== $host && Hosts::is_b2b_host( $host ) ? Hosts::normalise( $host ) : Hosts::b2b();
	}

	/** That host's language, or '' with one trade host or nothing recorded. */
	public static function language_for_user( int $user_id ): string {
		if ( '' === (string) get_user_meta( $user_id, self::META_KEY, true ) ) {
			return '';
		}

		return Hosts::b2b_language( self::for_user( $user_id ) );
	}
}
