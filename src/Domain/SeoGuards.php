<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the B2B host out of every search index, and points canonical tags
 * on shared content at the retail domain. See architecture §2.3.
 *
 * The B2B host is kept out of the index by noindex - an X-Robots-Tag header
 * and a robots meta tag on every response - not by robots.txt. robots.txt
 * belongs to Slim SEO on both hosts: it used to be replaced here with
 * `Disallow: /` after Slim SEO had run, which threw away its rules and its
 * Sitemap line, and a crawler that may not fetch a page cannot read the
 * noindex on it either.
 */
class SeoGuards {

	public static function register(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'replace_default_robots' ), 9, 2 );
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
	/**
	 * The retail host's own rules (Config 'retail_robots'), or '' when the site
	 * leaves robots.txt alone.
	 */
	public static function retail_robots_rules(): string {
		$lines = Config::get( 'retail_robots' );

		return is_array( $lines ) && $lines ? implode( "\n", array_map( 'strval', $lines ) ) . "\n" : '';
	}

	public static function replace_default_robots( $output, $public ) {
		// The retail host's rules, on the retail host only - not on every
		// host that is merely not the trade one.
		if ( ! Hosts::is_retail_host() || '' === self::retail_robots_rules() ) {
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
