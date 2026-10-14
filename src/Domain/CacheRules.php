<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the B2B host uncacheable, explicitly.
 *
 * Today no B2B response reaches a cache anyway: logged-out requests are
 * 302s from the login gate and logged-in requests are skipped by the page
 * cache. But that is an emergent property of two other mechanisms, not a
 * guarantee — if a future change makes any B2B response cacheable, the
 * host-blind disk cache would start serving it and the leak returns
 * silently. Architecture §2.9 requires the B2B host never to be cached, so
 * it is stated rather than inferred.
 *
 * A standards-based header is used because it is the one thing every cache
 * honours — WP Fastest Cache's own exclusions are URL-based and its entry
 * point is private, so there is no clean host-level hook into it, and a
 * future CDN or reverse proxy would not know about it either.
 *
 * The retail host is never given a directive: its guest bucket is the whole
 * performance story.
 */
class CacheRules {

	public static function register(): void {
		add_action( 'send_headers', array( __CLASS__, 'send' ), 1 );
	}

	public static function cache_control(): ?string {
		if ( ! Hosts::is_b2b_host() ) {
			return null;
		}

		return 'no-store, no-cache, must-revalidate, private';
	}

	public static function send(): void {
		$value = self::cache_control();

		if ( null === $value || headers_sent() ) {
			return;
		}

		header( 'Cache-Control: ' . $value, true );
		header( 'Pragma: no-cache', true );
	}
}
