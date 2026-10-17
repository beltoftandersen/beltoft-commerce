<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/*
 * Config by path, not the autoloader: this class is loaded by the early host
 * handler (mu-plugin) before the autoloader exists, and an installed copy of
 * that handler from an older version does not list Config. Without this, an
 * update fataled every request before the plugin could replace the handler.
 */
require_once __DIR__ . '/Config.php';

/**
 * The only place in the codebase that knows a hostname.
 *
 * Host controls presentation and URL generation. It never controls access
 * and never controls price — see the architecture document, §2.3.
 *
 * SET PER ENVIRONMENT IN wp-config.php:
 *
 *     define( 'BELTOFT_B2B_HOST', 'b2b.example.com' );
 *     define( 'BELTOFT_RETAIL_HOST', 'example.com' );   // optional
 *
 * A constant, not only a filter, because the mu-plugin asks for these before
 * any plugin, theme or snippet has loaded - a filter added there comes too
 * late for the early login gate, the early host redirects and the page-cache
 * opt-out, which then compared against another environment's hostname. The
 * beltoft_commerce_*_host filters still override for code that runs later.
 *
 * NO BUILT-IN TRADE HOST. It used to default to staging's, so an environment
 * without the setting treated staging's hostname as its own trade site and
 * redirected the trade pages there. Unset, there is no trade host: nothing is
 * gated, nothing redirects, and administrators get a notice. The retail host
 * falls back to the host of the stored home URL, which is what it is.
 */
class Hosts {

	public static function retail(): string {
		$host = defined( 'BELTOFT_RETAIL_HOST' ) ? (string) BELTOFT_RETAIL_HOST : self::stored_home_host();

		return self::normalise( (string) apply_filters( 'beltoft_commerce_retail_host', $host ) );
	}

	public static function b2b(): string {
		$host = defined( 'BELTOFT_B2B_HOST' ) ? (string) BELTOFT_B2B_HOST : '';

		return self::normalise( (string) apply_filters( 'beltoft_commerce_b2b_host', $host ) );
	}

	public static function is_b2b_configured(): bool {
		return '' !== self::b2b();
	}

	/**
	 * Normalises away port, case and a trailing dot before comparing.
	 * HTTP_HOST carries the port on non-standard ports, and a raw comparison
	 * would silently fall through to retail behaviour on the B2B host.
	 */
	public static function is_b2b_host( ?string $host = null ): bool {
		$host = self::normalise( (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );

		return '' !== $host && self::is_b2b_configured() && $host === self::b2b();
	}

	/**
	 * Whether this request is on the retail host itself - not merely "not the
	 * trade host". The install can answer on other hosts too, and
	 * retail-only output such as robots.txt rules does not belong there.
	 */
	public static function is_retail_host( ?string $host = null ): bool {
		$host = self::normalise( (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );

		return '' !== $host && $host === self::retail();
	}

	/**
	 * The home URL as stored, read from the autoloaded options rather than
	 * through get_option(), whose option_home filters rewrite it to the
	 * requesting host (this plugin's UrlFilter, and other plugins' on theirs).
	 * WP_HOME, when defined, is what WordPress itself would answer.
	 */
	public static function stored_home(): string {
		if ( defined( 'WP_HOME' ) ) {
			return (string) WP_HOME;
		}

		$options = function_exists( 'wp_load_alloptions' ) ? wp_load_alloptions() : array();

		return isset( $options['home'] ) ? (string) $options['home'] : '';
	}

	private static function stored_home_host(): string {
		$host = wp_parse_url( self::stored_home(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	private static function normalise( string $host ): string {
		// A fully qualified name may end in a dot ("b2b.example.com.").
		return rtrim( explode( ':', strtolower( trim( $host ) ) )[0], '.' );
	}
}
