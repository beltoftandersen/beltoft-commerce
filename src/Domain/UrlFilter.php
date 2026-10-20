<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the canonical home/siteurl per request so generated URLs stay on
 * the requested host.
 *
 * WordPress builds every generated URL from one canonical home. Without this,
 * links, redirects, pagination, admin-ajax, the Store API and checkout all
 * throw the user onto the retail domain mid-session. See architecture §2.3.
 *
 * Registered from an mu-plugin so it runs before WooCommerce builds any URL.
 */
class UrlFilter {

	/**
	 * Set while queued work is running, which has no host of its own.
	 */
	private static bool $suspended = false;

	public static function register(): void {
		add_filter( 'option_home', array( __CLASS__, 'filter' ) );
		add_filter( 'option_siteurl', array( __CLASS__, 'filter' ) );
		add_filter( 'admin_url', array( __CLASS__, 'admin_url' ) );
		add_filter( 'login_url', array( __CLASS__, 'login_url' ) );

		/*
		 * A QUEUED JOB BELONGS TO NO HOST, even when it has one.
		 *
		 * The rule below - no HTTP_HOST means cron or a queued job, keep the
		 * stored value - was right and incomplete. Action Scheduler has two
		 * runners. The cron one has no host and lands there correctly. The
		 * async one dispatches a loopback to admin_url( 'admin-ajax.php' ),
		 * and during a request on the trade host that URL IS the trade host -
		 * so the loopback arrives with HTTP_HOST set, this filter believes it,
		 * and every job in that batch renders trade URLs.
		 *
		 * Which batch that is has nothing to do with the jobs in it. The
		 * runner drains the whole queue, so a trade sale spawning the runner
		 * decided the URLs in a retail customer's order email - nine links
		 * pointing at a host that answers them with a trade login page. And it
		 * was only ever a coin flip: the same email sent a minute later, by
		 * cron, came out correct. WooCommerce's own webhook source header went
		 * the same way, and the Odoo endpoint rejected every delivery that
		 * carried the trade host - 18 of them, 400 each, against 73 accepted.
		 *
		 * So the suspension covers the whole batch rather than any one job.
		 * Live requests on the trade host are untouched: the trade catalogue,
		 * cart, checkout and Store API still build trade URLs, which is the
		 * entire reason this class exists.
		 */
		add_action( 'action_scheduler_before_process_queue', array( __CLASS__, 'suspend' ) );
		add_action( 'action_scheduler_after_process_queue', array( __CLASS__, 'resume' ) );
	}

	public static function suspend(): void {
		self::$suspended = true;
	}

	public static function resume(): void {
		self::$suspended = false;
	}

	/**
	 * The stored home URL, whatever host this request happens to be on.
	 *
	 * Read past every option_home filter, not only this one: it used to
	 * suspend just its own, and on any other host another plugin's filter
	 * still rewrote the value to that host - which consumers of this (the
	 * webhook source header, cache keys) took for the retail address.
	 */
	public static function canonical(): string {
		return trailingslashit( Hosts::stored_home() );
	}

	public static function filter( $value ) {
		if ( self::$suspended ) {
			return $value;
		}

		// No HTTP_HOST means cron, WP-CLI or a queued job. Those have no
		// request host and must keep the stored value.
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return $value;
		}

		if ( ! Hosts::is_b2b_host() ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$parts = wp_parse_url( $value );

		if ( empty( $parts['host'] ) ) {
			return $value;
		}

		// The trade host this request is on: each language has its own.
		return str_replace( '://' . $parts['host'], '://' . Hosts::current_b2b(), $value );
	}

	/**
	 * Generate interactive administration links on the canonical retail host.
	 *
	 * The B2B frontend posts forms and AJAX to its own host so its host-only
	 * customer cookie remains available. Those two endpoints are not admin UI.
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function admin_url( $url ) {
		if ( ! Hosts::is_b2b_host() || ! is_string( $url ) ) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( in_array( $path, array( '/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php' ), true ) ) {
			return $url;
		}

		return self::on_canonical_host( $url );
	}

	/**
	 * Core login is for administrators; partner login remains at /entrar/.
	 *
	 * wp_logout_url() has its own filter and is deliberately not changed: it
	 * must clear the host-only customer cookie on the B2B host.
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function login_url( $url ) {
		return Hosts::is_b2b_host() && is_string( $url ) ? self::on_canonical_host( $url ) : $url;
	}

	/** Replace only the origin, preserving the complete path, query and fragment. */
	private static function on_canonical_host( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return $url;
		}

		$path     = $parts['path'] ?? '/';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return 'https://' . Hosts::retail() . $path . $query . $fragment;
	}
}
