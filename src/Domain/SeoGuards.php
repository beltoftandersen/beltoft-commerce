<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the B2B host out of every search index, and points canonical tags
 * on shared content at the retail domain. See architecture §2.3.
 *
 * Unlike a public staging site, `Disallow: /` is correct here: the B2B host
 * is fully authenticated and serves logged-out visitors nothing but a login
 * page, so there is no content a crawler needs to reach in order to see a
 * noindex directive.
 */
class SeoGuards {

	public static function register(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'replace_default_robots' ), 9, 2 );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), PHP_INT_MAX, 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_noindex' ), 1 );
		add_action( 'send_headers', array( __CLASS__, 'send_noindex_header' ) );
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'disable_sitemaps_on_b2b' ) );
	}

	/**
	 * Robots rules are generated here rather than kept in a physical
	 * robots.txt, because a file in the web root is served by nginx before
	 * WordPress runs and cannot vary by host. Both hosts share one document
	 * root, so a file would give the B2B host the retail rules.
	 *
	 * The retail rules are stated explicitly rather than derived from
	 * `blog_public`: this staging site is deliberately crawlable so that
	 * crawlers can read the `X-Robots-Tag: noindex` header nginx sends. A
	 * `Disallow: /` would stop them reading it, and Google can index a URL
	 * it cannot crawl.
	 */
	public static function filter_robots_txt( $output, $public ) {
		if ( ! Hosts::is_b2b_host() ) {
			return $output;
		}

		// PHP_INT_MAX: Slim SEO hooks robots_txt at 9999 and appends a
		// `Sitemap:` directive. The B2B host must advertise nothing at all,
		// so this runs last and discards whatever was added.
		return "User-agent: *\nDisallow: /\n";
	}

	/**
	 * The retail host's own rules, previously held in a physical robots.txt.
	 */
	public static function retail_robots_rules(): string {
		return implode( "\n", array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Disallow: /*add-to-cart=*',
			'Disallow: /*?add-to-cart=*',
			'Disallow: /*add_to_wishlist=*',
			'Disallow: /wishlist/WOOSW/',
			'Allow: /wp-admin/admin-ajax.php',
		) ) . "\n";
	}

	public static function replace_default_robots( $output, $public ) {
		if ( Hosts::is_b2b_host() ) {
			return $output;
		}

		return self::retail_robots_rules();
	}

	public static function filter_canonical( $url ) {
		if ( ! Hosts::is_b2b_host() || ! is_string( $url ) ) {
			return $url;
		}

		return str_replace( '://' . Hosts::b2b(), '://' . Hosts::retail(), $url );
	}

	public static function print_noindex(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
	}

	public static function send_noindex_header(): void {
		if ( ! Hosts::is_b2b_host() || headers_sent() ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	public static function disable_sitemaps_on_b2b( $enabled ) {
		return Hosts::is_b2b_host() ? false : $enabled;
	}
}
