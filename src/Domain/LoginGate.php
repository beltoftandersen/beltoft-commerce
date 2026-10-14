<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Logged-out visitors to the B2B host get the login page and nothing else.
 *
 * Serving the retail catalog on both hosts would publish the store twice and
 * create a duplicate-content problem. It also keeps the B2B host fully
 * authenticated, which matches the caching model — it is uncacheable anyway.
 * See architecture §2.3.
 */
class LoginGate {

	/**
	 * The Bricks-rendered login page.
	 *
	 * Logged-out B2B visitors land here rather than on wp-login.php so the
	 * wall is designed and editable in the builder. WordPress still owns
	 * authentication: the Bricks form's login action calls wp_signon(), so
	 * every authenticate filter - including the administrator-location block
	 * in beltoft-hardening - still applies.
	 */
	public const LOGIN_PATH = '/entrar/';

	/**
	 * The B2B application form.
	 *
	 * §2.3 says logged-out visitors to the B2B host get "the login and
	 * registration page and nothing else". Fourteen fields do not belong in the
	 * 360px login card, so registration is its own page - both reachable,
	 * nothing else is, which is what the rule is protecting.
	 */
	public const REGISTER_PATH = '/registo/';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Paths a logged-out visitor must still reach for login and account
	 * recovery to work at all.
	 */
	private static function allowlist(): array {
		return (array) apply_filters( 'beltoft_commerce_b2b_public_paths', array(
			self::LOGIN_PATH,
			self::REGISTER_PATH,
			'/wp-login.php',
			/*
			 * LoginGate runs before HostRoutes, so it must let wp-admin through
			 * for HostRoutes to move interactive administration to the canonical
			 * retail host. The B2B frontend's admin-ajax.php and admin-post.php
			 * endpoints also match this prefix and deliberately remain local;
			 * their actions still enforce their own nonce and capability checks.
			 */
			'/wp-admin/',
			/*
			 * WooCommerce's real lost-password URL.
			 *
			 * The endpoint slug is 'recuperar-palavra-passe' since the account
			 * endpoints were put into Portuguese; the option that holds it is
			 * woocommerce_myaccount_lost_password_endpoint. A literal for the
			 * reason given at the top of this list - the gate runs before any
			 * plugin, so it cannot ask WooCommerce - which means CHANGING THAT
			 * OPTION MEANS CHANGING THESE TWO LINES. Get it wrong and the only
			 * symptom is a logged-out partner who cannot reset a password:
			 * nothing errors, the gate simply bounces them to the login page.
			 *
			 * The reset link mailed to the customer carries ?key= and ?login=
			 * on this same path, so one prefix covers both steps.
			 */
			'/a-minha-conta/recuperar-palavra-passe/',
			/*
			 * The same endpoint under the trade account page. B2B requests resolve
			 * the account page to Account\B2BAccountPage::PATH, so the reset link
			 * mailed to a partner lands here and not on the retail path above.
			 */
			'/conta/recuperar-palavra-passe/',
			/*
			 * The account area's first home, kept because reset links already in
			 * partners' inboxes point at it: the gate has to let them reach the
			 * 301 that Account\B2BAccountPage puts on the old path, or the forward
			 * never happens and they land back on the login page.
			 */
			'/conta-b2b/recuperar-palavra-passe/',
			/*
			 * The scanner's own REST namespace, and only that one.
			 *
			 * The gate answers a logged-out request with a 302 to the login
			 * page, which is right for a page and wrong for an API: the phone
			 * asks for JSON, is handed an HTML login form, and the scanner -
			 * which is written to catch 401 and 403, hold the unsent scans and
			 * offer to sign in again - sees a 200 full of HTML instead and
			 * reports a generic failure, losing the scans it was supposed to
			 * keep. Exempting this prefix lets the route answer with the status
			 * it means. Nothing is opened up: every route in it has its own
			 * permission_callback, which refuses anyone who is not an approved
			 * partner. The rest of /wp-json/ stays behind the gate, because WP
			 * core's own routes would otherwise list this catalogue to anyone.
			 */
			'/wp-json/b2b/v1/',
			'/robots.txt',
			'/favicon.ico',
		) );
	}

	public static function should_gate( string $path ): bool {
		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return false;
		}

		foreach ( self::allowlist() as $allowed ) {
			if ( 0 === strpos( $path, $allowed ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Early gate, called from the mu-plugin before any plugin loads.
	 *
	 * This exists because page-cache plugins serve a cached file during
	 * their own load, long before template_redirect. WP Fastest Cache keys
	 * its disk cache by path and not by host, so without this the B2B host
	 * serves the retail catalog straight from cache and PHP never reaches
	 * the authoritative gate below.
	 *
	 * Login state is inferred from the auth cookie because pluggable
	 * functions are not loaded this early. That is deliberately permissive:
	 * a stale or forged cookie only buys the visitor a trip to the real
	 * gate in maybe_redirect(), which re-checks properly.
	 */
	public static function should_gate_early( string $path, bool $has_auth_cookie ): bool {
		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		if ( $has_auth_cookie ) {
			return false;
		}

		foreach ( self::allowlist() as $allowed ) {
			if ( 0 === strpos( $path, $allowed ) ) {
				return false;
			}
		}

		return true;
	}

	public static function maybe_redirect_early(): void {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/';

		$has_auth_cookie = false;

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
				$has_auth_cookie = true;
				break;
			}
		}

		if ( ! self::should_gate_early( $path, $has_auth_cookie ) ) {
			return;
		}

		// wp_safe_redirect() is pluggable and not available this early.
		header( 'Location: https://' . Hosts::b2b() . self::LOGIN_PATH, true, 302 );
		header( 'Cache-Control: no-store, private' );
		exit;
	}

	public static function maybe_redirect(): void {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/';

		if ( ! self::should_gate( $path ) ) {
			return;
		}

		wp_safe_redirect( home_url( self::LOGIN_PATH ), 302 );
		exit;
	}
}
