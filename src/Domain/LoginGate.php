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

	/**
	 * Query variables that make WordPress serve something other than the path.
	 *
	 * The allowlist is of paths, and WordPress answers query strings too:
	 * /entrar/?pagename=b2b-loja rendered the trade ordering page to a logged-out
	 * visitor, and /entrar/?wc-ajax=checkout ran a checkout. An allowed path
	 * carrying any of these is treated as what it really asks for. The login and
	 * reset flows' own parameters (redirect_to, loggedout, key, login) are not
	 * here and keep working.
	 *
	 * A fixed list because the early gate runs before WordPress has registered
	 * its query variables.
	 */
	private const CONTENT_VARS = array(
		'p', 'page_id', 'pagename', 'name', 'attachment', 'attachment_id', 'subpost', 'subpost_id',
		'preview', 'post_type', 'static', 'pb', 'embed',
		's', 'cat', 'category_name', 'tag', 'tag_id', 'taxonomy', 'term', 'author', 'author_name',
		'feed', 'm', 'year', 'monthnum', 'day', 'w', 'sitemap', 'post_format',
		'product', 'product_cat', 'product_tag', 'product_brand',
		'rest_route', 'wc-ajax',
	);

	/**
	 * The scanner answers for its own endpoints (see the allowlist). With the
	 * trailing slash: without it the exemption also covered /beltoft-scanner/v10
	 * and the bare namespace, whose route index lists every endpoint.
	 */
	private const OWN_REST_PREFIX = '/beltoft-scanner/v1/';

	public static function register(): void {
		/*
		 * On wp_loaded, before anything answers the request. WooCommerce runs
		 * ?wc-ajax= on template_redirect priority 0 and its form handlers
		 * (add to cart, coupons, checkout) on wp_loaded, so a gate at
		 * template_redirect 1 came too late for both - and the early gate
		 * only looks at a cookie's name, so any wordpress_logged_in_* cookie
		 * reached them logged out. template_redirect stays as the backstop.
		 */
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_redirect_loaded' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		// Before StoreApiGuard and QueryResultCache, which also answer here.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 5, 3 );
	}

	/**
	 * The path a request really asks for.
	 *
	 * ?rest_route= is the REST API reached through any page's URL, so
	 * /entrar/?rest_route=/wc/store/v1/products is judged as the
	 * /wp-json/wc/store/v1/products it is, not as the login page.
	 */
	public static function effective_path( string $request_uri ): string {
		$query = self::query_of( $request_uri );

		if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) ) {
			return '/wp-json/' . ltrim( $query['rest_route'], '/' );
		}

		return wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/';
	}

	private static function query_of( string $request_uri ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $query );

		return $query;
	}

	private static function selects_other_content( array $query ): bool {
		return (bool) array_intersect( array_keys( $query ), self::CONTENT_VARS );
	}

	private static function allowlisted( string $path, array $query ): bool {
		if ( self::selects_other_content( $query ) ) {
			return false;
		}

		/*
		 * The login and application pages themselves, not what lies under
		 * them: as prefixes they let /entrar/anything/ (and /entrar/../loja/
		 * sent unnormalised) render a trade 404 with the trade header to a
		 * logged-out visitor.
		 */
		if ( in_array( untrailingslashit( $path ) . '/', array( self::LOGIN_PATH, self::REGISTER_PATH ), true ) ) {
			return true;
		}

		foreach ( self::allowlist() as $allowed ) {
			if ( 0 === strpos( $path, $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * REST on the trade host needs a real login.
	 *
	 * REST requests never reach the page gate, and the early gate only looks for
	 * a cookie's name - so any cookie called wordpress_logged_in_* opened the
	 * whole API. Here the login cookie is validated. It is checked directly
	 * rather than through is_user_logged_in(): WordPress treats a REST request
	 * without a nonce as anonymous, and a signed-in partner's page making such a
	 * call must keep working.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function guard_rest( $result, $server = null, $request = null ) {
		if ( null !== $result || ! Hosts::is_b2b_host() || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( 0 === strpos( $request->get_route(), self::OWN_REST_PREFIX ) ) {
			return $result;
		}

		if ( is_user_logged_in() || wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return $result;
		}

		return new \WP_Error(
			'beltoft_b2b_login_required',
			__( 'Sign in to continue.', 'beltoft-commerce' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Paths a logged-out visitor must still reach for login and account
	 * recovery to work at all.
	 */
	private static function allowlist(): array {
		// The login and application pages are matched exactly, in allowlisted().
		return (array) apply_filters( 'beltoft_commerce_b2b_public_paths', array(
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
			'/wp-json/beltoft-scanner/v1/',
			'/robots.txt',
			'/favicon.ico',
		) );
	}

	public static function should_gate( string $path, array $query = array() ): bool {
		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return false;
		}

		return ! self::allowlisted( $path, $query );
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
	public static function should_gate_early( string $path, bool $has_auth_cookie, array $query = array() ): bool {
		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		if ( $has_auth_cookie ) {
			return false;
		}

		return ! self::allowlisted( $path, $query );
	}

	public static function maybe_redirect_early(): void {
		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path        = self::effective_path( $request_uri );
		$query       = self::query_of( $request_uri );

		$has_auth_cookie = false;

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
				$has_auth_cookie = true;
				break;
			}
		}

		if ( ! self::should_gate_early( $path, $has_auth_cookie, $query ) ) {
			return;
		}

		// wp_safe_redirect() is pluggable and not available this early.
		header( 'Location: https://' . Hosts::b2b() . self::LOGIN_PATH, true, 302 );
		header( 'Cache-Control: no-store, private' );
		exit;
	}

	/**
	 * The gate on wp_loaded, for front-end requests only: cron and the admin
	 * endpoints have their own checks, and REST answers 401 itself
	 * (guard_rest) rather than redirecting an API client to a login page.
	 *
	 * Not wp_doing_ajax(): WooCommerce defines DOING_AJAX for ?wc-ajax= on
	 * init, which would wave through exactly the requests this is for.
	 */
	public static function maybe_redirect_loaded(): void {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$path = self::effective_path( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );

		if ( 0 === strpos( $path, '/wp-json/' ) || 0 === strpos( $path, '/wp-admin/' ) ) {
			return;
		}

		self::maybe_redirect();
	}

	public static function maybe_redirect(): void {
		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

		if ( ! self::should_gate( self::effective_path( $request_uri ), self::query_of( $request_uri ) ) ) {
			return;
		}

		wp_safe_redirect( home_url( self::LOGIN_PATH ), 302 );
		exit;
	}
}
