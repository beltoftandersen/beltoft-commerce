<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * What this plugin asks of a language plugin, in one place.
 *
 * Zerolang keeps each translation of a page as its own post, linked in a
 * translation group. A translated copy of a trade page (the Spanish login
 * page, slug acceder) shares nothing with the Portuguese one (entrar) but that
 * link, so anything that decides "is this a trade page" by slug or path misses
 * every copy. The answer is to ask about the default-language page instead -
 * the anchor - which is the one the settings and the paths name.
 *
 * Optional: without Zerolang every page is its own anchor and the plugin
 * behaves exactly as before. Every call is guarded with class_exists() and
 * method_exists(), and every answer goes through a
 * beltoft_commerce_language_* filter - for another language plugin, and so
 * the tests need no language plugin at all.
 *
 * Late only: the early host handler cannot load this (no autoloader), and
 * decides from the configuration instead (Support\TradePath).
 */
class Language {

	/**
	 * The language of this request. Without a language plugin: the trade
	 * host's language from BELTOFT_B2B_HOSTS, else ''.
	 */
	public static function current(): string {
		$code = class_exists( '\Zerolang\Request' ) && method_exists( '\Zerolang\Request', 'current' )
			? (string) \Zerolang\Request::current()
			: Hosts::b2b_language();

		return (string) apply_filters( 'beltoft_commerce_language_current', $code );
	}

	/** The default language. Without a language plugin: the default trade host's language, else ''. */
	public static function default_code(): string {
		$code = class_exists( '\Zerolang\Languages' ) && method_exists( '\Zerolang\Languages', 'default_code' )
			? (string) \Zerolang\Languages::default_code()
			: Hosts::default_language();

		return (string) apply_filters( 'beltoft_commerce_language_default', $code );
	}

	/** Whether a code is the default language (an empty code is). */
	public static function is_default( string $code ): bool {
		return '' === $code || $code === self::default_code() || '' === self::default_code();
	}

	/** @return string[] Every configured language; [] without a language plugin. */
	public static function codes(): array {
		$codes = class_exists( '\Zerolang\Languages' ) && method_exists( '\Zerolang\Languages', 'codes' )
			? (array) \Zerolang\Languages::codes()
			: array();

		return array_values( array_map( 'strval', (array) apply_filters( 'beltoft_commerce_language_codes', $codes ) ) );
	}

	/** The language a post is in. Without a language plugin: the default. */
	public static function of_post( int $post_id ): string {
		$code = '';

		if ( $post_id && class_exists( '\Zerolang\Map' ) && method_exists( '\Zerolang\Map', 'language_of' ) ) {
			$code = (string) \Zerolang\Map::language_of( 'post', $post_id );
		}

		$code = '' !== $code ? $code : self::default_code();

		return (string) apply_filters( 'beltoft_commerce_language_of_post', $code, $post_id );
	}

	/**
	 * A post's translation group, as code => post id, the post itself
	 * included. Without a language plugin: the post alone.
	 *
	 * @return array<string,int>
	 */
	public static function siblings( int $post_id ): array {
		$siblings = array();

		if ( $post_id && class_exists( '\Zerolang\Map' ) && method_exists( '\Zerolang\Map', 'siblings' ) ) {
			$siblings = (array) \Zerolang\Map::siblings( 'post', $post_id );
		}

		$siblings = (array) apply_filters( 'beltoft_commerce_language_siblings', $siblings, $post_id );

		return array_map( 'intval', $siblings );
	}

	/** A post's translation in a language, or 0; the post itself in its own language. */
	public static function sibling_in( int $post_id, string $code ): int {
		if ( ! $post_id ) {
			return 0;
		}

		if ( self::is_default( $code ) && self::default_sibling( $post_id ) === $post_id ) {
			return $post_id;
		}

		if ( self::of_post( $post_id ) === $code ) {
			return $post_id;
		}

		return (int) ( self::siblings( $post_id )[ $code ] ?? 0 );
	}

	/**
	 * A URL on this site moved into a language: its prefix in a path mode,
	 * its host under separate domains (Zerolang's Permalinks::prefix()).
	 */
	public static function url_in( string $url, string $code ): string {
		$out = $url;

		if ( '' !== $code && class_exists( '\Zerolang\Permalinks' ) && method_exists( '\Zerolang\Permalinks', 'prefix' ) ) {
			$out = (string) \Zerolang\Permalinks::prefix( $url, $code );
		}

		return (string) apply_filters( 'beltoft_commerce_language_url_in', $out, $url, $code );
	}

	/**
	 * The retail host of a language: its domain under separate domains
	 * (Zerolang's own table, never a host set), else the site address.
	 */
	public static function retail_host_for( string $code ): string {
		$host = '';

		if ( '' !== $code && class_exists( '\Zerolang\Languages' ) && method_exists( '\Zerolang\Languages', 'host_for' ) ) {
			// '' asks for the site's own table, not the active host set (Zerolang 0.4).
			$host = (string) \Zerolang\Languages::host_for( $code, '' );
		}

		$host = '' !== $host ? $host : Hosts::retail();

		return (string) apply_filters( 'beltoft_commerce_language_retail_host', $host, $code );
	}

	/** Whether a host is one the language plugin serves a language on (its own table). */
	public static function is_language_domain( string $host ): bool {
		$is = false;

		if ( '' !== $host && class_exists( '\Zerolang\Languages' ) && method_exists( '\Zerolang\Languages', 'code_for_host' ) ) {
			$is = '' !== (string) \Zerolang\Languages::code_for_host( $host, '' );
		}

		return (bool) apply_filters( 'beltoft_commerce_language_is_domain', $is, $host );
	}

	/** Whether the language plugin puts each language on its own host. */
	public static function separate_domains(): bool {
		$in_host = class_exists( '\Zerolang\Url_Mode' ) && method_exists( '\Zerolang\Url_Mode', 'in_host' ) && \Zerolang\Url_Mode::in_host();

		return (bool) apply_filters( 'beltoft_commerce_language_separate_domains', $in_host );
	}

	/**
	 * A path mode's language prefixes, as URL segment => code; [] under
	 * separate domains and without a language plugin.
	 *
	 * @return array<string,string>
	 */
	public static function path_prefixes(): array {
		$prefixes = array();

		if ( ! self::separate_domains() && class_exists( '\Zerolang\Languages' ) && method_exists( '\Zerolang\Languages', 'prefixed_codes' ) && method_exists( '\Zerolang\Languages', 'slug' ) ) {
			foreach ( (array) \Zerolang\Languages::prefixed_codes() as $code ) {
				$prefixes[ (string) \Zerolang\Languages::slug( $code ) ] = (string) $code;
			}
		}

		return (array) apply_filters( 'beltoft_commerce_language_path_prefixes', $prefixes );
	}

	/** The language an order was placed in (Zerolang records _zerolang_lang), else ''. */
	public static function of_order( \WC_Order $order ): string {
		return (string) apply_filters( 'beltoft_commerce_language_of_order', (string) $order->get_meta( '_zerolang_lang' ), $order );
	}

	/**
	 * The default-language page a post is a translation of, or the post itself.
	 *
	 * Filterable (beltoft_commerce_default_language_post), for another
	 * language plugin and for tests.
	 */
	public static function default_sibling( int $post_id ): int {
		$anchor = $post_id;

		if ( $post_id && class_exists( '\Zerolang\Map' ) && class_exists( '\Zerolang\Languages' ) ) {
			$siblings = (array) \Zerolang\Map::siblings( 'post', $post_id );
			$default  = (string) \Zerolang\Languages::default_code();

			if ( '' !== $default && ! empty( $siblings[ $default ] ) ) {
				$anchor = (int) $siblings[ $default ];
			}
		}

		return (int) apply_filters( 'beltoft_commerce_default_language_post', $anchor, $post_id );
	}

	/**
	 * Load the translation groups of many posts at once, before asking
	 * default_sibling() about each (Zerolang otherwise asks per post).
	 *
	 * @param int[] $post_ids
	 */
	public static function prime( array $post_ids ): void {
		if ( $post_ids && class_exists( '\Zerolang\Map' ) && method_exists( '\Zerolang\Map', 'prime' ) ) {
			\Zerolang\Map::prime( 'post', array_map( 'intval', $post_ids ) );
		}
	}

	/**
	 * Query arguments that ask for every language at once (Zerolang §9a).
	 * A language plugin narrows get_posts() to the request's language
	 * otherwise; the extra query var means nothing to WordPress itself.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function every_language( array $args ): array {
		$args['zerolang_all_languages'] = true;

		return $args;
	}
}
