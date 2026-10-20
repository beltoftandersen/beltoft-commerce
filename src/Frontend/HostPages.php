<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Language;
use BeltoftCommerce\Support\TradePath;

defined( 'ABSPATH' ) || exit;

/**
 * Trade pages served at root paths that retail also uses.
 *
 * ONE PATH, TWO PAGES. This install serves both hosts, and paths like
 * /termos-condicoes/, /sobre-nos/, /contacto/ and /loja/ are retail pages. The
 * trade site wants the same short paths for its own versions, which are
 * different posts. A path resolves to exactly one post, so on a trade request
 * the resolution is changed: the path is answered with the trade post's id
 * before WP_Query runs. Retail requests are never touched.
 *
 * The trade posts carry physical slugs prefixed b2b- (b2b-loja, b2b-envios),
 * which Domain\HostRoutes keeps off the retail host as a prefix. Those slugs
 * are never public: the permalink is filtered to the root path on the trade
 * host, and nothing links to them. There is deliberately NO forwarding from a
 * physical slug to its root path - they are internal, and a redirect would
 * only advertise them. Everything downstream follows the post id (the Bricks
 * document, its CSS file, is_page(), every gate in this plugin), so a page
 * keeps its identity whichever path it is reached by.
 *
 * MATCHED ON THE REQUEST PATH, NOT ON pagename. /loja/ is WooCommerce's shop
 * page on retail, and WooCommerce registers the shop slug as the product
 * post type's archive - so the rewrite rule that wins for /loja/ is the
 * archive's and the vars that arrive are post_type=product, never
 * pagename=loja. The path is the one thing every rule agrees on. The incoming
 * vars are replaced, not merged: post_type=product left beside page_id would
 * make WP_Query look for a product with that id. Pagination survives, because
 * the ordering list is paginated.
 *
 * THE PERMALINK IS FILTERED TOO, and this is not cosmetic: get_permalink() on
 * a trade post would otherwise return its physical b2b- slug, redirect_canonical()
 * would bounce the root request there. Answering the root path for the
 * permalink is what tells WordPress the root path IS canonical.
 *
 * The map lives in an option, as Account\B2BAccountPage and
 * Checkout\B2BCheckoutPage keep their ids, so nothing here hard-codes a post.
 *
 * IN EVERY LANGUAGE (spec 2026-10-20, §5). The map names the default
 * language's pages. A language's copy is the page's translation (its sibling
 * in the language plugin), and its root path is its own slug without the
 * trade prefix: b2b-tienda is /tienda/, under /es/ in a path mode and on the
 * language's trade host under separate domains. A copy whose slug lacks the
 * prefix is not served at a root path, and a language with no copy has no
 * page there - never the Portuguese one (Zerolang §5: no content fallback).
 *
 * CACHING: a full-page cache keyed on the path alone hands one host's page to
 * the other. Staging has none; anywhere that does must put the host in its
 * cache key first. Deliberately not solved here - it belongs to the cache.
 */
class HostPages {

	/** Option holding the map of root slug => trade post id. */
	public const OPTION = 'beltoft_commerce_b2b_root_pages';

	public static function register(): void {
		add_filter( 'request', array( __CLASS__, 'resolve' ) );
		add_filter( 'page_link', array( __CLASS__, 'permalink' ), 10, 2 );
		add_filter( 'preview_post_link', array( __CLASS__, 'preview_link' ), 10, 2 );
		add_filter( 'bricks/get_builder_edit_link', array( __CLASS__, 'builder_edit_link' ), 10, 2 );
	}

	/**
	 * The map, with anything unpublished or missing dropped.
	 *
	 * @return array<string, int>
	 */
	public static function map(): array {
		$out = array();

		foreach ( (array) get_option( self::OPTION, array() ) as $slug => $id ) {
			$slug = trim( (string) $slug, '/' );
			$id   = (int) $id;

			if ( '' !== $slug && $id && 'publish' === get_post_status( $id ) ) {
				$out[ $slug ] = $id;
			}
		}

		return $out;
	}

	/**
	 * The map in a language: root path => that language's copy, keyed by the
	 * copy's own root. The default language (or no language) is map().
	 *
	 * @return array<string, int>
	 */
	public static function map_in( string $code ): array {
		if ( Language::is_default( $code ) ) {
			return self::map();
		}

		$out = array();

		Language::prime( array_values( self::map() ) );

		foreach ( self::map() as $id ) {
			$copy = Language::sibling_in( $id, $code );
			$root = $copy && 'publish' === get_post_status( $copy ) ? self::copy_root( $copy ) : '';

			if ( '' !== $root ) {
				$out[ $root ] = $copy;
			}
		}

		return $out;
	}

	/**
	 * The trade post for a map key (the default language's root slug), in a
	 * language - the request's by default. 0 when it is missing, unpublished
	 * or not translated into that language.
	 */
	public static function id( string $slug, ?string $code = null ): int {
		$id = self::map()[ trim( $slug, '/' ) ] ?? 0;

		if ( ! $id ) {
			return 0;
		}

		$code = $code ?? Language::current();

		if ( Language::is_default( $code ) ) {
			return $id;
		}

		$copy = Language::sibling_in( $id, $code );

		return $copy && 'publish' === get_post_status( $copy ) ? $copy : 0;
	}

	/** The root slug for a default-language trade post (a map entry), or ''. */
	public static function slug_for( int $post_id ): string {
		$slug = array_search( $post_id, self::map(), true );

		return false === $slug ? '' : (string) $slug;
	}

	/**
	 * The root path of a trade post in any language: its map key, or for a
	 * translated copy of a mapped page its slug without the trade prefix.
	 * '' when it is neither, or a copy's slug lacks the prefix.
	 */
	public static function root_for( int $post_id ): string {
		$slug = self::slug_for( $post_id );

		if ( '' !== $slug ) {
			return $slug;
		}

		// The slug first: page_link asks for every link on a page, and only a b2b- slug needs its translation group.
		$root = self::copy_root( $post_id );

		if ( '' === $root ) {
			return '';
		}

		$anchor = Language::default_sibling( $post_id );

		return $anchor !== $post_id && '' !== self::slug_for( $anchor ) ? $root : '';
	}

	/** A copy's root: its slug minus Config 'trade_page_prefix' (b2b-tienda: tienda), or ''. */
	private static function copy_root( int $post_id ): string {
		$prefix = trim( strtolower( (string) Config::get( 'trade_page_prefix' ) ), '/' );
		$name   = (string) get_post_field( 'post_name', $post_id );

		if ( '' === $prefix || 0 !== strpos( $name, $prefix ) || strlen( $name ) === strlen( $prefix ) ) {
			return '';
		}

		return substr( $name, strlen( $prefix ) );
	}

	/**
	 * On the trade host, a mapped root path means the trade post.
	 *
	 * The path may carry a tail, and only two kinds are accepted:
	 *
	 *   /loja/page/2/                        pagination, kept as `paged`
	 *   /finalizar/encomenda-recebida/123/   a WooCommerce endpoint
	 *
	 * The endpoint case is why this exists for the checkout at all: the
	 * thank-you and pay pages are endpoint sub-paths of the checkout page, and
	 * a resolver that only matched bare paths handed /finalizar/encomenda-
	 * recebida/... to retail's checkout post on the trade host. The endpoint
	 * var WooCommerce parsed from the tail is kept; only the vars that name
	 * the wrong post (pagename, name, post_type, page) are replaced.
	 *
	 * Any other tail is left alone, so /loja/produto-x/ stays the 404 it is
	 * on retail rather than quietly rendering the ordering page.
	 *
	 * In the request's language: the path's prefix in a path mode (/es/tienda/),
	 * the trade host's language under separate domains. A default-language
	 * root asked for in a language that has no copy of it is a 404.
	 *
	 * @param mixed $vars
	 * @return mixed
	 */
	public static function resolve( $vars ) {
		if ( ! is_array( $vars ) || ! Hosts::is_b2b_host() ) {
			return $vars;
		}

		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		list( $code, $rest ) = TradePath::split( $path );

		$code = '' !== $code ? $code : Language::current();

		if ( ! preg_match( '#^/([^/]+)/(.*)$#', $rest, $m ) ) {
			return $vars;
		}

		$id = self::map_in( $code )[ $m[1] ] ?? 0;

		if ( ! $id ) {
			// Any other tail (a product under /loja/) is not a root page's: the language plugin answers it.
			if ( ! self::accepted_tail( $m[2] ) ) {
				return $vars;
			}

			// A default-language root in another language: that language's copy, at its own root.
			$target = self::language_root_target( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );

			if ( '' !== $target ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}

			// No copy in this language: not the default language's page, and not whatever else WordPress finds there.
			return ! Language::is_default( $code ) && 0 !== ( self::map()[ $m[1] ] ?? 0 ) ? array( 'error' => '404' ) : $vars;
		}

		$tail  = $m[2];
		$paged = 0;

		if ( '' !== $tail ) {
			if ( preg_match( '#^page/(\d+)/?$#', $tail, $pm ) ) {
				$paged = (int) $pm[1];
			} elseif ( ! self::tail_is_endpoint( $tail ) ) {
				return $vars;
			}
		}

		unset( $vars['pagename'], $vars['name'], $vars['post_type'], $vars['page'] );

		$vars['page_id'] = $id;

		if ( $paged ) {
			$vars['paged'] = $paged;
		}

		return $vars;
	}

	/**
	 * Where a default-language root asked for in another language goes: the
	 * same page's copy in the request's language, at its own root, tail and
	 * query kept (/es/loja/ to /es/tienda/; /loja/ on the English trade host
	 * to the English copy's root). '' when the language is the default, the
	 * path is not a default root, or the language has no copy (then it is a
	 * 404, see resolve()). Pure, for tests.
	 */
	public static function language_root_target( string $request_uri ): string {
		list( $code, $rest ) = TradePath::split( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );

		$code = '' !== $code ? $code : Language::current();

		if ( Language::is_default( $code ) || ! preg_match( '#^/([^/]+)/(.*)$#', $rest, $m ) || ! self::accepted_tail( $m[2] ) || isset( self::map_in( $code )[ $m[1] ] ) || ! isset( self::map()[ $m[1] ] ) ) {
			return '';
		}

		$copy = self::id( $m[1], $code );
		$root = $copy ? self::root_for( $copy ) : '';

		if ( '' === $root ) {
			return '';
		}

		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		return home_url( TradePath::prefix( $code ) . '/' . $root . '/' . $m[2] ) . ( '' !== $query ? '?' . $query : '' );
	}

	/** The tails a root page carries: none, page/N, or a WooCommerce endpoint. */
	private static function accepted_tail( string $tail ): bool {
		return '' === $tail || (bool) preg_match( '#^page/\d+/?$#', $tail ) || self::tail_is_endpoint( $tail );
	}

	/** Whether a path tail starts with a registered WooCommerce endpoint slug. */
	private static function tail_is_endpoint( string $tail ): bool {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->query ) ) {
			return false;
		}

		$first = explode( '/', $tail, 2 )[0];

		foreach ( WC()->query->get_query_vars() as $slug ) {
			if ( '' !== $slug && $slug === $first ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A trade post's permalink is its root path on the trade host, in the
	 * post's language: /es/tienda/ in a path mode, the language's trade host
	 * under separate domains (the language plugin moves it there).
	 *
	 * @param mixed $link
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function permalink( $link, $post_id = 0 ) {
		if ( ! Hosts::is_b2b_host() ) {
			return $link;
		}

		$root = self::root_for( (int) $post_id );

		if ( '' === $root ) {
			return $link;
		}

		return Language::url_in( home_url( '/' . $root . '/' ), Language::of_post( (int) $post_id ) );
	}

	/**
	 * Keep mapped trade-page previews on the canonical admin host.
	 *
	 * A root query URL avoids the physical b2b-* path that HostRoutes protects.
	 * WordPress retains preview access for the logged-in editor and redirects an
	 * unauthenticated request to the ordinary public permalink.
	 *
	 * @param mixed $url
	 * @param mixed $post
	 * @return mixed
	 */
	public static function preview_link( $url, $post ) {
		$post = get_post( $post );

		if ( ! is_string( $url ) || ! $post || '' === self::slug_for( (int) $post->ID ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		$args  = array();

		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $args );
		}

		unset( $args['page_id'], $args['p'] );
		$args = array( 'page_id' => (int) $post->ID ) + $args;
		$url  = add_query_arg( $args, 'https://' . Hosts::retail() . '/' );

		return $url . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Keep mapped trade-page editing on the canonical retail/admin host.
	 *
	 * Public permalinks belong on the B2B host, but WordPress authentication
	 * cookies are host-only. Opening the builder there can replace the active
	 * administrator with a partner session. Bricks supplies the builder query;
	 * preserve it while restoring the post's physical slug on the admin host.
	 *
	 * @param mixed $url
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function builder_edit_link( $url, $post_id = 0 ) {
		if ( ! is_string( $url ) || '' === self::slug_for( (int) $post_id ) ) {
			return $url;
		}

		$physical_slug = (string) get_post_field( 'post_name', (int) $post_id );

		if ( '' === $physical_slug ) {
			return $url;
		}

		$parts    = wp_parse_url( $url );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return 'https://' . Hosts::retail() . '/' . $physical_slug . '/' . $query . $fragment;
	}
}
