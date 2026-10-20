<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Language;

defined( 'ABSPATH' ) || exit;

/**
 * The trade pages stay off the retail host's REST API and oEmbed.
 *
 * HostRoutes moves anyone who opens a trade page on retail to the trade host,
 * but WordPress also describes every published page through /wp/v2/pages
 * (list, ?slug=, by id) and the oEmbed endpoint - title, slug, author - to
 * anyone. The pages are the ones HostRoutes already claims for the trade
 * host. Editors still see them, so the builder and the block editor work.
 */
class TradePages {

	/** @var int[]|null */
	private static ?array $ids = null;

	public static function register(): void {
		add_filter( 'rest_page_query', array( __CLASS__, 'exclude_from_query' ) );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'hide_single' ), 10, 3 );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'hide_oembed' ) );
		// Search and the sitemaps list the same pages, slugs and all.
		add_filter( 'rest_post_search_query', array( __CLASS__, 'exclude_from_query' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_from_sitemap_query' ) );
		add_filter( 'slim_seo_sitemap_post_ignore', array( __CLASS__, 'ignore_in_sitemap' ), 10, 2 );
		// No hreflang on a trade host: its alternates are other hosts' pages (Zerolang 0.4 filter).
		add_filter( 'zerolang_hreflang_links', array( __CLASS__, 'hreflang_links' ) );
		add_action( 'save_post_page', array( __CLASS__, 'forget' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
	}

	/** A page saved or removed may change which pages are trade pages. */
	public static function forget(): void {
		self::$ids = null;
	}

	private static function applies(): bool {
		return ! Hosts::is_b2b_host() && ! current_user_can( 'edit_pages' );
	}

	/**
	 * Published pages that belong to the trade host, in every language. @return int[]
	 *
	 * Every language, because a language plugin (Zerolang) narrows get_posts()
	 * to the request's: the retail sitemap, built in Portuguese, listed every
	 * Spanish trade page (/es/acceder/, /es/b2b-tienda/ ...).
	 */
	public static function ids(): array {
		if ( null !== self::$ids ) {
			return self::$ids;
		}

		$pages = array_map( 'intval', get_posts( Language::every_language( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) ) );

		Language::prime( $pages );

		return self::$ids = array_values( array_filter( $pages, array( __CLASS__, 'is_trade_page' ) ) );
	}

	/**
	 * Whether a page belongs to the trade host - by identity, not by its own
	 * address.
	 *
	 * A translated copy has its own slug (acceder for entrar, cuenta-de-socio
	 * for conta), so it is judged by its default-language page, the anchor:
	 * a trade page when the anchor is mapped by HostPages, is the configured
	 * trade account or checkout page, or sits on a trade-only path
	 * (HostRoutes::b2b_only_paths()). The page's own path still counts, for a
	 * copy that has a b2b- slug but no anchor.
	 */
	public static function is_trade_page( int $post_id ): bool {
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return false;
		}

		$anchor = Language::default_sibling( $post_id );

		if ( in_array( $anchor, self::identities(), true ) ) {
			return true;
		}

		foreach ( array_unique( array( $anchor, $post_id ) ) as $id ) {
			if ( HostRoutes::is_b2b_only_path( '/' . trim( (string) get_page_uri( $id ), '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/** The trade pages named by id in the settings, whatever their slugs. @return int[] */
	private static function identities(): array {
		$ids = class_exists( '\\BeltoftCommerce\\Frontend\\HostPages' ) ? array_values( \BeltoftCommerce\Frontend\HostPages::map() ) : array();

		if ( class_exists( '\\BeltoftCommerce\\Checkout\\B2BCheckoutPage' ) ) {
			$ids[] = \BeltoftCommerce\Checkout\B2BCheckoutPage::configured_id();
		}

		if ( class_exists( '\\BeltoftCommerce\\Account\\B2BAccountPage' ) ) {
			$ids[] = \BeltoftCommerce\Account\B2BAccountPage::configured_id();
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/** @param array $args */
	public static function exclude_from_query( $args ) {
		if ( ! is_array( $args ) || ! self::applies() || ! self::ids() ) {
			return $args;
		}

		// include= becomes post__in, which makes WordPress ignore post__not_in.
		if ( ! empty( $args['post__in'] ) ) {
			$kept             = array_values( array_diff( array_map( 'intval', (array) $args['post__in'] ), self::ids() ) );
			$args['post__in'] = $kept ? $kept : array( 0 );

			return $args;
		}

		$args['post__not_in'] = array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), self::ids() );

		return $args;
	}

	/**
	 * /wp/v2/pages/<id>, judged on the page the route resolved. Not through
	 * rest_prepare_page: get_item() cannot take an error from there.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function hide_single( $response, $handler = array(), $request = null ) {
		$callback   = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		$controller = is_array( $callback ) ? ( $callback[0] ?? null ) : null;

		if ( ! $response instanceof \WP_REST_Response || ! $controller instanceof \WP_REST_Posts_Controller || 'get_item' !== ( $callback[1] ?? '' ) ) {
			return $response;
		}

		$data = $response->get_data();
		$id   = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;

		if ( ! $id || 'page' !== get_post_type( $id ) || ! self::applies() || ! in_array( $id, self::ids(), true ) ) {
			return $response;
		}

		return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message.
	}

	/**
	 * A sitemap is for crawlers whoever builds it; trade pages are never in it
	 * (each listed URL redirected to the trade host's login page).
	 *
	 * @param mixed $args
	 * @return mixed
	 */
	public static function exclude_from_sitemap_query( $args ) {
		if ( ! is_array( $args ) || ! self::ids() ) {
			return $args;
		}

		$args['post__not_in'] = array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), self::ids() );

		return $args;
	}

	/**
	 * @param mixed $ignore
	 * @param mixed $post
	 * @return mixed
	 */
	public static function ignore_in_sitemap( $ignore, $post = null ) {
		return $ignore || ( $post instanceof \WP_Post && in_array( (int) $post->ID, self::ids(), true ) );
	}

	/**
	 * Hreflang alternates, none on a trade host. Every trade host is noindex,
	 * and an alternate there names another trade host or a retail page - a
	 * crawler following one would only be sent to a login page.
	 *
	 * @param mixed $links
	 * @return mixed
	 */
	public static function hreflang_links( $links ) {
		return Hosts::is_b2b_host() ? array() : $links;
	}

	/** @param mixed $post_id */
	public static function hide_oembed( $post_id ) {
		return self::applies() && in_array( (int) $post_id, self::ids(), true ) ? 0 : $post_id;
	}
}
