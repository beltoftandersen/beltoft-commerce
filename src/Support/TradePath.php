<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Hosts.php';

/**
 * Which language a trade path is in, and the path without its language.
 *
 * The early gate and the early redirects run before any language plugin, so
 * they cannot ask it. They read the same configuration the language plugin
 * is fed instead (spec 2026-10-20, §2):
 *
 * - under separate domains the host names the language (Hosts::b2b_language(),
 *   from BELTOFT_B2B_HOSTS - the map Zerolang's trade host set is built from),
 *   and the path is left as it is;
 * - in a path mode (staging: /en/, /de/, /es/) a first segment listed in
 *   Config 'path_language_prefixes' names the language and is stripped. With
 *   none configured, a language plugin that has loaded supplies its own
 *   (Language::path_prefixes()), so the late gate and HostPages still see the
 *   language; the early handler then only sees unprefixed paths.
 *
 * Loaded by path from the early handler: nothing here may need the
 * autoloader before plugins have loaded.
 */
class TradePath {

	/**
	 * @param string      $path A request path.
	 * @param string|null $host The request host, by default this request's.
	 * @return array{0:string,1:string} The language code ('' when the path and
	 *                                  host name none) and the rest of the path.
	 */
	public static function split( string $path, ?string $host = null ): array {
		$path = '' === $path ? '/' : $path;
		$code = Hosts::b2b_hosts() ? ( Hosts::b2b_language( $host ) ?: Hosts::default_language() ) : '';

		$prefixes = self::prefixes();

		if ( $prefixes && preg_match( '#^/([^/]+)(/.*)?$#', $path, $m ) ) {
			$segment = strtolower( rawurldecode( $m[1] ) );

			if ( isset( $prefixes[ $segment ] ) ) {
				return array( (string) $prefixes[ $segment ], isset( $m[2] ) && '' !== $m[2] ? $m[2] : '/' );
			}
		}

		return array( $code, $path );
	}

	/** The path prefix of a language ('/es'), or '' when its paths carry none. */
	public static function prefix( string $code ): string {
		$segment = '' === $code ? false : array_search( $code, self::prefixes(), true );

		return false === $segment ? '' : '/' . $segment;
	}

	/** @return array<string,string> URL segment => language code. */
	public static function prefixes(): array {
		$configured = Config::get( 'path_language_prefixes' );

		if ( is_array( $configured ) && $configured ) {
			return self::clean( $configured );
		}

		// Late only: the autoloader and the language plugin exist after plugins load.
		if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) && class_exists( __NAMESPACE__ . '\\Language' ) ) {
			return self::clean( Language::path_prefixes() );
		}

		return array();
	}

	/** @return array<string,string> */
	private static function clean( array $prefixes ): array {
		$out = array();

		foreach ( $prefixes as $segment => $code ) {
			$segment = strtolower( trim( (string) $segment, '/ ' ) );

			if ( '' !== $segment && '' !== (string) $code ) {
				$out[ $segment ] = strtolower( (string) $code );
			}
		}

		return $out;
	}
}
