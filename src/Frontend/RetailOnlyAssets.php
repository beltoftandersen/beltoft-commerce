<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps retail-only shop furniture off the trade host.
 *
 * The wishlist is a consumer feature: a trade buyer reorders from a list they
 * already keep, and there is no wishlist button anywhere on the B2B pages. It
 * still loaded three stylesheets, its scripts, and printed its popup into the
 * footer of every B2B page.
 *
 * That last part is not only weight. The popup is `position:fixed` with
 * `visibility:hidden` — hidden to the eye but still laid out — and its width
 * computes to 420px. On a phone narrower than that (390 on an iPhone 14, 360 on
 * most Androids) it makes the whole page scroll sideways to show nothing.
 *
 * Scoped to the host rather than the pages, so a B2B page added later is
 * covered without anyone remembering this file exists.
 */
class RetailOnlyAssets {

	/** Handles the wishlist plugin registers for the frontend. */
	private const WISHLIST_ASSETS = array(
		'woosw-frontend',
		'woosw-icons',
		'notiny',
		'perfect-scrollbar',
	);

	public static function register(): void {
		// Late, so the plugin has registered and enqueued everything first.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_wishlist_assets' ), 99 );
		add_action( 'wp_loaded', array( __CLASS__, 'drop_wishlist_markup' ), 99 );
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}

	public static function drop_wishlist_assets(): void {
		if ( ! self::applies() ) {
			return;
		}

		foreach ( self::WISHLIST_ASSETS as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	/**
	 * The popup itself. Removed by matching the plugin's own callback shape —
	 * `[ $instance, 'wp_footer' ]` on `wp_footer` — because the instance is not
	 * exposed anywhere to pass to remove_action() directly.
	 */
	public static function drop_wishlist_markup(): void {
		if ( ! self::applies() || empty( $GLOBALS['wp_filter']['wp_footer'] ) ) {
			return;
		}

		$hook = $GLOBALS['wp_filter']['wp_footer'];

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				$fn = $callback['function'] ?? null;

				if ( ! is_array( $fn ) || ! is_object( $fn[0] ) || 'wp_footer' !== ( $fn[1] ?? '' ) ) {
					continue;
				}

				// The class is WPcleverWoosw, so match the plugin's prefix as well as
				// the English word - neither alone catches it.
				$class = get_class( $fn[0] );

				if ( false !== stripos( $class, 'woosw' ) || false !== stripos( $class, 'wishlist' ) ) {
					remove_action( 'wp_footer', $fn, $priority );
				}
			}
		}
	}
}
