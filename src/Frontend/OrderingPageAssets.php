<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Stops the trade ordering page loading things it cannot use.
 *
 * The page was pulling 57 stylesheets and scripts, a good many of which exist
 * for a consumer shop and have nothing to do with a partner working through a
 * price list. Each one is a request, a parse and, for the social scripts, a
 * third party watching a page they have no business seeing.
 *
 * Everything removed here was checked against what the page and its quick view
 * actually do, not guessed:
 *
 * - Super Socializer: sharing buttons and social login, including the Facebook
 *   SDK. There is no share button on a trade order list, and loading Facebook's
 *   SDK on a page behind a login is a privacy problem as much as a slow one.
 * - Follow-Up Emails: account and subscription screens, which are not here.
 * - Loyalty Rewards: points are a retail scheme; Account\MenuItems already
 *   takes the endpoint off the trade account for the same reason.
 * - WPC Composite Products and its ddslick dropdown: the trade catalogue is 189
 *   simple and 42 variable products. There is not one composite product in it.
 * - Subscriptions All Products: trade does not sell subscriptions.
 * - PhotoSwipe: WooCommerce's lightbox. The theme declares support for gallery
 *   zoom and slider but NOT lightbox, so these two scripts and two stylesheets
 *   load on every request and can never run.
 *
 * WHAT IS DELIBERATELY KEPT. wc-flexslider, wc-zoom and wc-single-product stay:
 * the quick view renders a real product gallery and 59 of 60 trade products
 * have more than one image, so the slider and zoom are used the moment somebody
 * opens one.
 *
 * Scoped to the trade host. Retail keeps every one of these.
 */
class OrderingPageAssets {

	/** Handles with no possible use on this page, style or script. */
	private const DROP = array(
		// Social sharing and login, Facebook SDK included.
		'the_champ_fb_commenting',
		'the_champ_fb_sdk',
		'the_champ_ss_general_scripts',
		'the_champ_frontend_css',
		// Follow-up emails account UI.
		'fue-account-subscriptions',
		'fue-front-script',
		'follow-up-emails',
		// Retail loyalty scheme.
		'blrw-frontend',
		'blrw-pro-frontend',
		'blrw-pro-blocks-frontend',
		// Composite products - none exist in the trade catalogue.
		'wooco-frontend',
		'wooco-blocks',
		'ddslick',
		// Subscriptions - trade does not sell them.
		'wcsatt-css',
		// Lightbox the theme does not enable.
		'wc-photoswipe',
		'wc-photoswipe-ui-default',
		'photoswipe',
		'photoswipe-default-skin',
	);

	/**
	 * Dropped on every trade page, not just the ordering one.
	 *
	 * custom-menu-scripts.js drives the retail mega menu. The trade header is a
	 * different template without any of its markup, so the script's first line
	 * of work - document.querySelector('.mm__toggle-close').addEventListener -
	 * finds null and throws, on every single trade page view, before anything
	 * else in the file runs. It has been doing so unnoticed. Nothing on trade
	 * needs it; the error goes away because the script does.
	 */
	private const HOST_DROP = array(
		'custom-menu-scripts',
	);

	public static function register(): void {
		// Late, so everything has registered and enqueued before any of it goes.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_host_wide' ), 100 );

		/*
		 * And again immediately before anything is printed.
		 *
		 * Some of these are not enqueued during wp_enqueue_scripts at all -
		 * WooCommerce's gallery handles arrive later - so a single pass at
		 * priority 100 removed them and they came straight back. These hooks
		 * are the last moment at which the queue can still be changed.
		 */
		foreach ( array( 'wp_print_scripts', 'wp_print_styles', 'wp_print_footer_scripts' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'drop' ), 0 );
		}
	}

	public static function drop_host_wide(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		foreach ( self::HOST_DROP as $handle ) {
			wp_dequeue_script( $handle );
			wp_dequeue_style( $handle );
		}
	}

	public static function drop(): void {
		if ( ! self::applies() ) {
			return;
		}

		foreach ( self::DROP as $handle ) {
			wp_dequeue_style( $handle );
			wp_dequeue_script( $handle );
		}

		self::detach_photoswipe();
	}

	/**
	 * PhotoSwipe is a dependency of wc-single-product, so dequeuing it alone
	 * achieves nothing - WordPress pulls a dependency back in whatever the
	 * queue says.
	 *
	 * wc-single-product has to stay: it is what starts the gallery the quick
	 * view renders. But it only reaches for PhotoSwipe when the theme declares
	 * wc-product-gallery-lightbox, and this one declares zoom and slider and
	 * not lightbox. So the dependency is removed rather than the script, and
	 * the check below means this corrects itself the day somebody does turn the
	 * lightbox on.
	 */
	private static function detach_photoswipe(): void {
		if ( current_theme_supports( 'wc-product-gallery-lightbox' ) ) {
			return;
		}

		$scripts = wp_scripts();

		if ( empty( $scripts->registered['wc-single-product'] ) ) {
			return;
		}

		$deps = (array) $scripts->registered['wc-single-product']->deps;

		$scripts->registered['wc-single-product']->deps = array_values(
			array_diff( $deps, array( 'wc-photoswipe', 'wc-photoswipe-ui-default' ) )
		);

		wp_dequeue_script( 'wc-photoswipe' );
		wp_dequeue_script( 'wc-photoswipe-ui-default' );
		wp_dequeue_style( 'photoswipe' );
		wp_dequeue_style( 'photoswipe-default-skin' );
	}

	/**
	 * The trade ordering and quick-order pages only.
	 *
	 * Not the whole B2B host: the account pages and the checkout are ordinary
	 * WooCommerce screens where some of these - the follow-up-emails account
	 * scripts especially - may legitimately belong.
	 */
	private static function applies(): bool {
		if ( ! Hosts::is_b2b_host() || ! function_exists( 'is_page' ) ) {
			return false;
		}

		if ( OrderingPage::is_ordering_page() ) {
			return true;
		}

		foreach ( \BeltoftCommerce\QuickOrder\Page::paths() as $path ) {
			if ( is_page( trim( $path, '/' ) ) ) {
				return true;
			}
		}

		return false;
	}
}
