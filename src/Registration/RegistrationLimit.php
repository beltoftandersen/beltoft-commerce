<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * How fast one address may use the B2B application form.
 *
 * The form had no limit. Each submission can create a b2b_pending user and
 * makes the store send its branded "received" email - with the shop name the
 * submitter typed - to any address given, so a script could turn it into a
 * mail relay, and could probe which emails already have an account. Two
 * counters per address:
 *
 * - every submission, valid or not: ATTEMPTS per ATTEMPT_WINDOW;
 * - applications actually created: APPLICATIONS per APPLICATION_WINDOW.
 *
 * Windows are fixed from the first event, not restarted by later ones, so a
 * slow trickle cannot hold an address locked out (see Domain\LoginThrottle).
 * The address is REMOTE_ADDR only, which nginx's real_ip module sets from the
 * trusted proxy and a client cannot choose. Stored hashed.
 */
class RegistrationLimit {

	private const ATTEMPTS       = 10;
	private const ATTEMPT_WINDOW = HOUR_IN_SECONDS;

	private const APPLICATIONS       = 3;
	private const APPLICATION_WINDOW = DAY_IN_SECONDS;

	private const ATTEMPT_PREFIX     = 'beltoft_reg_try_';
	private const APPLICATION_PREFIX = 'beltoft_reg_app_';

	public static function allows(): bool {
		return self::count( self::ATTEMPT_PREFIX ) < self::ATTEMPTS
			&& self::count( self::APPLICATION_PREFIX ) < self::APPLICATIONS;
	}

	public static function record_attempt(): void {
		self::bump( self::ATTEMPT_PREFIX, self::ATTEMPT_WINDOW );
	}

	public static function record_application(): void {
		self::bump( self::APPLICATION_PREFIX, self::APPLICATION_WINDOW );
	}

	private static function key( string $prefix ): string {
		return $prefix . substr( md5( 'blt-reg|' . (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 20 );
	}

	private static function count( string $prefix ): int {
		$stored = get_transient( self::key( $prefix ) );

		return is_array( $stored ) && (int) ( $stored['until'] ?? 0 ) > time() ? (int) ( $stored['count'] ?? 0 ) : 0;
	}

	private static function bump( string $prefix, int $window ): void {
		$key    = self::key( $prefix );
		$stored = get_transient( $key );
		$now    = time();

		$until = is_array( $stored ) && (int) ( $stored['until'] ?? 0 ) > $now
			? (int) $stored['until']
			: $now + $window;

		set_transient( $key, array(
			'count' => self::count( $prefix ) + 1,
			'until' => $until,
		), max( 1, $until - $now ) );
	}
}
