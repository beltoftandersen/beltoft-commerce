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

/*
 * And TradePath, for the same reason: LoginGate and HostRoutes use it, and an
 * installed copy of the handler from before it existed does not list it.
 */
require_once __DIR__ . '/TradePath.php';

/**
 * The only place in the codebase that knows a hostname.
 *
 * Host controls presentation and URL generation. It never controls access.
 * Since 2026-10-20 it limits price: a shop-front request on any host other
 * than the trade host is priced at retail, whoever is signed in
 * (Pricing\PricingContext; architecture document §2.3, amended).
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
 * ONE TRADE HOST PER LANGUAGE (optional, spec 2026-10-20):
 *
 *     define( 'BELTOFT_B2B_HOST', 'b2b.example.pt' );            // the default language's
 *     define( 'BELTOFT_B2B_HOSTS', array(
 *         'pt' => 'b2b.example.pt',                              // must equal BELTOFT_B2B_HOST
 *         'en' => 'b2b.example.com',
 *     ) );
 *
 * Every host in the map is a trade host: gated, never cached, never indexed,
 * priced for the partner's group. Each serves one language; a language with
 * no host of its own uses Config 'trade_fallback_language''s. Without the map
 * there is one trade host and everything is as it was.
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
	 * The trade host of each language, as language code => host:
	 * BELTOFT_B2B_HOSTS, then the beltoft_commerce_b2b_hosts filter.
	 *
	 * Empty in single-host mode, and whenever BELTOFT_B2B_HOST is not set
	 * (no trade site at all). A host that is not a clean hostname, or is the
	 * retail host, is dropped here and reported by problems(): treating the
	 * retail host as a trade host would gate the whole shop.
	 *
	 * @return array<string,string>
	 */
	public static function b2b_hosts(): array {
		if ( ! self::is_b2b_configured() ) {
			return array();
		}

		$raw = self::raw_b2b_hosts();

		if ( ! $raw ) {
			return array();
		}

		$out    = array();
		$retail = self::retail();

		foreach ( $raw as $code => $host ) {
			$code = strtolower( trim( (string) $code ) );
			$host = self::normalise( (string) $host );

			if ( '' === $code || ! self::is_clean_host( $host ) || $host === $retail || in_array( $host, $out, true ) ) {
				continue;
			}

			$out[ $code ] = $host;
		}

		return $out;
	}

	/** @return array<mixed,mixed> The map as configured, unchecked. */
	private static function raw_b2b_hosts(): array {
		$map = defined( 'BELTOFT_B2B_HOSTS' ) && is_array( BELTOFT_B2B_HOSTS ) ? BELTOFT_B2B_HOSTS : array();

		// Asked on every price filter (PricingContext): single-host mode stops here.
		if ( ! $map && ! has_filter( 'beltoft_commerce_b2b_hosts' ) ) {
			return array();
		}

		return (array) apply_filters( 'beltoft_commerce_b2b_hosts', $map );
	}

	/** @return string[] Every trade host: BELTOFT_B2B_HOST and the map's. */
	public static function all_b2b_hosts(): array {
		if ( ! self::is_b2b_configured() ) {
			return array();
		}

		return array_values( array_unique( array_merge( array( self::b2b() ), array_values( self::b2b_hosts() ) ) ) );
	}

	/**
	 * Whether a host (the request's by default) is a trade host - any of them.
	 *
	 * Normalises away port, case and a trailing dot before comparing.
	 * HTTP_HOST carries the port on non-standard ports, and a raw comparison
	 * would silently fall through to retail behaviour on the B2B host.
	 */
	public static function is_b2b_host( ?string $host = null ): bool {
		$raw = (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) );

		if ( self::host_filtered() ) {
			$host = self::normalise( $raw );

			return '' !== $host && isset( self::build_trade_host_set()[ $host ] );
		}

		$key = self::memo_key();

		if ( ! isset( self::$answers[ $key ][ $raw ] ) ) {
			$set  = self::$trade_host_sets[ $key ] ??= self::build_trade_host_set();
			$host = self::normalise( $raw );

			self::$answers[ $key ][ $raw ] = '' !== $host && isset( $set[ $host ] );
		}

		return self::$answers[ $key ][ $raw ];
	}

	/*
	 * The trade hosts, and is_b2b_host()'s answers, worked out once per
	 * configuration.
	 *
	 * is_b2b_host() is asked several hundred times a page: every price filter
	 * (PricingContext) and every home/siteurl read (UrlFilter), on every host.
	 * Rebuilding and validating the host map each time was most of this
	 * plugin's own cost on a retail page.
	 *
	 * Only while no host filter is attached: the constants cannot change in a
	 * request, and the stored home address (which decides the retail host,
	 * and so which map entries are dropped, when BELTOFT_RETAIL_HOST is not
	 * set) is part of the key. A filter may answer differently on every call,
	 * so with one attached nothing is kept.
	 */

	/** @var array<string,array<string,true>> memo key => trade host => true */
	private static array $trade_host_sets = array();

	/** @var array<string,array<string,bool>> memo key => host as asked => answer */
	private static array $answers = array();

	/**
	 * Whether a host filter has a callback. WordPress drops a hook's entry
	 * from $wp_filter when its last callback is removed, so isset() is
	 * has_filter() without the call - this runs on every is_b2b_host().
	 */
	private static function host_filtered(): bool {
		$filters = $GLOBALS['wp_filter'] ?? array();

		return isset( $filters['beltoft_commerce_b2b_host'] ) || isset( $filters['beltoft_commerce_b2b_hosts'] ) || isset( $filters['beltoft_commerce_retail_host'] );
	}

	private static function memo_key(): string {
		return defined( 'BELTOFT_RETAIL_HOST' ) || ! defined( 'BELTOFT_B2B_HOSTS' ) ? '' : self::stored_home();
	}

	/** @return array<string,true> Every trade host, as host => true. */
	private static function build_trade_host_set(): array {
		return array_fill_keys( self::all_b2b_hosts(), true );
	}

	/** This request's trade host, normalised, or '' when it is not on one. */
	public static function current_b2b(): string {
		$host = self::normalise( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );

		return self::is_b2b_host( $host ) ? $host : '';
	}

	/**
	 * The language a trade host serves (the request's by default), from the
	 * map; '' in single-host mode and for any host that is not in the map.
	 */
	public static function b2b_language( ?string $host = null ): string {
		$host = self::normalise( (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$code = '' === $host ? false : array_search( $host, self::b2b_hosts(), true );

		return false === $code ? '' : (string) $code;
	}

	/** The language of BELTOFT_B2B_HOST in the map, or '' without one. */
	public static function default_language(): string {
		return self::b2b_language( self::b2b() );
	}

	/**
	 * The trade host for a language: its own, else the fallback language's
	 * (Config 'trade_fallback_language'), else BELTOFT_B2B_HOST.
	 */
	public static function b2b_for( string $code ): string {
		$map      = self::b2b_hosts();
		$fallback = (string) Config::get( 'trade_fallback_language' );

		return $map[ strtolower( $code ) ] ?? $map[ $fallback ] ?? self::b2b();
	}

	/**
	 * Whether a host (the request's by default) is a retail host: not a trade
	 * host, and either the site address or a domain a language plugin serves
	 * a language on (mindthetrash.com, .de). Late only: the language plugin
	 * is asked through Support\Language, which the early handler cannot load.
	 */
	public static function is_retail_side( ?string $host = null ): bool {
		$host = self::normalise( (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );

		if ( '' === $host || self::is_b2b_host( $host ) ) {
			return false;
		}

		return self::is_retail_host( $host ) || ( class_exists( __NAMESPACE__ . '\\Language' ) && Language::is_language_domain( $host ) );
	}

	/**
	 * The zerolang_host_sets callback: the trade hosts as Zerolang's "trade"
	 * host set, so a link to another language on a trade host goes to that
	 * language's trade host and never to a retail domain. Registered by the
	 * early handler only when a map is configured; Zerolang reads it only
	 * under separate domains.
	 *
	 * @param mixed  $sets         set name => array( code => host )
	 * @param mixed  $default_code The language plugin's default language.
	 * @param mixed  $home_host    The site address's host.
	 * @return mixed
	 */
	public static function zerolang_sets( $sets, $default_code = '', $home_host = '' ) {
		$map = self::b2b_hosts();

		if ( ! $map || ! is_array( $sets ) ) {
			return $sets;
		}

		$sets['trade'] = $map + ( '' !== (string) $default_code ? array( (string) $default_code => self::b2b() ) : array() );

		return $sets;
	}

	/**
	 * What is wrong with the host configuration, for the admin notice and
	 * Site Health. Empty when all is well, and always in single-host mode
	 * with a clean setup. Late only (it asks the language plugin).
	 *
	 * The checks that read pages (trade_paths against the translated pages'
	 * slugs, a HostPages page without a trade copy in a host's language) are
	 * in Support\HostHealth, which runs them for Site Health only.
	 *
	 * @return string[]
	 */
	public static function problems(): array {
		$problems = array();

		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN && self::is_b2b_configured() ) {
			/* translators: %s: the COOKIE_DOMAIN value */
			$problems[] = sprintf( __( 'COOKIE_DOMAIN is set (%s). Every trade host must keep host-only cookies: remove it from wp-config.php.', 'beltoft-commerce' ), (string) COOKIE_DOMAIN );
		}

		$raw = self::raw_b2b_hosts();

		if ( ! $raw ) {
			return $problems;
		}

		if ( ! self::is_b2b_configured() ) {
			$problems[] = __( 'BELTOFT_B2B_HOSTS is set but BELTOFT_B2B_HOST is not. Set BELTOFT_B2B_HOST to the default language\'s trade host.', 'beltoft-commerce' );

			return $problems;
		}

		$language = class_exists( __NAMESPACE__ . '\\Language' );
		$codes    = $language ? Language::codes() : array();
		$default  = $language && $codes ? Language::default_code() : '';
		$retail   = self::retail();
		$seen     = array();

		foreach ( $raw as $code => $host ) {
			$code  = strtolower( trim( (string) $code ) );
			$clean = self::normalise( (string) $host );

			if ( false !== strpos( (string) $host, ':' ) || ! self::is_clean_host( $clean ) ) {
				/* translators: 1: language code, 2: host */
				$problems[] = sprintf( __( 'The trade host for "%1$s" (%2$s) is not a hostname: no IP address, port or underscore.', 'beltoft-commerce' ), $code, (string) $host );
				continue;
			}

			if ( isset( $seen[ $clean ] ) ) {
				/* translators: %s: host */
				$problems[] = sprintf( __( 'The trade host %s is listed twice in BELTOFT_B2B_HOSTS.', 'beltoft-commerce' ), $clean );
			}

			$seen[ $clean ] = true;

			if ( $clean === $retail || ( $language && Language::is_language_domain( $clean ) ) ) {
				/* translators: %s: host */
				$problems[] = sprintf( __( 'The trade host %s is also a retail host. A trade host needs a hostname of its own.', 'beltoft-commerce' ), $clean );
			}

			if ( $codes && ! in_array( $code, $codes, true ) ) {
				/* translators: %s: language code */
				$problems[] = sprintf( __( 'BELTOFT_B2B_HOSTS names the language "%s", which the language plugin does not have.', 'beltoft-commerce' ), $code );
			}

			if ( $clean === self::b2b() && '' !== $default && $code !== $default ) {
				/* translators: 1: language code, 2: the default language's code */
				$problems[] = sprintf( __( 'BELTOFT_B2B_HOST is mapped to "%1$s", but it must be the trade host of the default language (%2$s).', 'beltoft-commerce' ), $code, $default );
			}
		}

		if ( ! in_array( self::b2b(), array_values( self::b2b_hosts() ), true ) ) {
			/* translators: %s: host */
			$problems[] = sprintf( __( 'BELTOFT_B2B_HOSTS does not list BELTOFT_B2B_HOST (%s) as the default language\'s trade host.', 'beltoft-commerce' ), self::b2b() );
		}

		$fallback = (string) Config::get( 'trade_fallback_language' );

		if ( '' !== $fallback && ! isset( self::b2b_hosts()[ $fallback ] ) ) {
			/* translators: %s: language code */
			$problems[] = sprintf( __( 'trade_fallback_language is "%s", which has no trade host in BELTOFT_B2B_HOSTS.', 'beltoft-commerce' ), $fallback );
		}

		if ( $codes && count( self::b2b_hosts() ) > 1 && ! Language::separate_domains() ) {
			$problems[] = __( 'There are several trade hosts but the language plugin uses path prefixes: every trade host serves every language under its prefix.', 'beltoft-commerce' );
		}

		if ( $codes && ! Language::separate_domains() ) {
			$configured = (array) Config::get( 'path_language_prefixes' );
			$derived    = Language::path_prefixes();
			ksort( $configured );
			ksort( $derived );

			if ( $configured && $configured !== $derived ) {
				$problems[] = __( 'path_language_prefixes does not match the language plugin\'s URL prefixes.', 'beltoft-commerce' );
			}
		}

		return array_values( array_unique( $problems ) );
	}

	/** A name a request can carry: no IP address, port, underscore or path. */
	private static function is_clean_host( string $host ): bool {
		return '' !== $host
			&& false === filter_var( $host, FILTER_VALIDATE_IP )
			&& (bool) preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host );
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

	public static function normalise( string $host ): string {
		// A fully qualified name may end in a dot ("b2b.example.com.").
		return rtrim( explode( ':', strtolower( trim( $host ) ) )[0], '.' );
	}
}
