<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the codebase that knows a hostname.
 *
 * Host controls presentation and URL generation. It never controls access
 * and never controls price — see the architecture document, §2.3.
 */
class Hosts {

	public static function retail(): string {
		return (string) apply_filters( 'beltoft_commerce_retail_host', 'staging.mindthetrash.pt' );
	}

	public static function b2b(): string {
		return (string) apply_filters( 'beltoft_commerce_b2b_host', 'b2b.staging.mindthetrash.pt' );
	}

	/**
	 * Normalises away port and case before comparing. HTTP_HOST carries the
	 * port on non-standard ports, and a raw comparison would silently fall
	 * through to retail behaviour on the B2B host.
	 */
	public static function is_b2b_host( ?string $host = null ): bool {
		$host = $host ?? ( $_SERVER['HTTP_HOST'] ?? '' );
		$host = strtolower( (string) $host );
		$host = explode( ':', $host )[0];

		return $host === strtolower( self::b2b() );
	}
}
