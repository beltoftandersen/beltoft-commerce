<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

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

	/** The trade post for a root slug, or 0. */
	public static function id( string $slug ): int {
		return self::map()[ trim( $slug, '/' ) ] ?? 0;
	}

	/** The root slug for a trade post, or '' if it is not one. */
	public static function slug_for( int $post_id ): string {
		$slug = array_search( $post_id, self::map(), true );

		return false === $slug ? '' : (string) $slug;
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
	 * @param mixed $vars
	 * @return mixed
	 */
	public static function resolve( $vars ) {
		if ( ! is_array( $vars ) || ! Hosts::is_b2b_host() ) {
			return $vars;
		}

		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		if ( ! preg_match( '#^/([^/]+)/(.*)$#', $path, $m ) ) {
			return $vars;
		}

		$id = self::id( $m[1] );

		if ( ! $id ) {
			return $vars;
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
	 * A trade post's permalink is its root path on the trade host.
	 *
	 * @param mixed $link
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function permalink( $link, $post_id = 0 ) {
		if ( ! Hosts::is_b2b_host() ) {
			return $link;
		}

		$slug = self::slug_for( (int) $post_id );

		return '' === $slug ? $link : home_url( '/' . $slug . '/' );
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
