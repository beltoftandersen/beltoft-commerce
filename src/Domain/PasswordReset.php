<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A password reset started on the B2B host returns the user there.
 *
 * The initiating host is stored in user meta rather than a transient or the
 * session, because the reset email may be rendered by a queued job long
 * after the request has ended. See architecture §2.3.
 */
class PasswordReset {

	private const META_KEY = '_beltoft_reset_host';

	public static function register(): void {
		add_action( 'retrieve_password', array( __CLASS__, 'remember_host' ) );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'filter_message' ), 10, 4 );
		add_filter( 'lostpassword_redirect', array( __CLASS__, 'filter_redirect' ) );
	}

	public static function remember_host( $user_login ): void {
		$user = get_user_by( 'login', $user_login );

		if ( ! $user ) {
			return;
		}

		// The trade host it was asked on - each language has its own.
		$host = '' !== Hosts::current_b2b() ? Hosts::current_b2b() : Hosts::retail();

		update_user_meta( $user->ID, self::META_KEY, $host );
	}

	public static function host_for_user( int $user_id ): string {
		$host = get_user_meta( $user_id, self::META_KEY, true );

		return $host ? (string) $host : Hosts::retail();
	}

	public static function filter_message( $message, $key, $user_login, $user_data ) {
		$user = get_user_by( 'login', $user_login );

		if ( ! $user || ! is_string( $message ) ) {
			return $message;
		}

		$host = self::host_for_user( $user->ID );

		if ( Hosts::retail() === $host ) {
			return $message;
		}

		return str_replace( '://' . Hosts::retail(), '://' . $host, $message );
	}

	public static function filter_redirect( $redirect ) {
		if ( ! Hosts::is_b2b_host() || ! is_string( $redirect ) || '' === $redirect ) {
			return $redirect;
		}

		return str_replace( '://' . Hosts::retail(), '://' . Hosts::current_b2b(), $redirect );
	}
}
