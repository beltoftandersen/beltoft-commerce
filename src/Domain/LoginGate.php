<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

// Loaded early by path (mu-plugin); see Support/Hosts.php for why Config is required here.
require_once dirname( __DIR__ ) . '/Support/Config.php';

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
	 *
	 * Config 'login_path'.
	 */
	public static function login_path(): string {
		return Config::path( 'login_path' );
	}

	/**
	 * The B2B application form.
	 *
	 * §2.3 says logged-out visitors to the B2B host get "the login and
	 * registration page and nothing else". Fourteen fields do not belong in the
	 * 360px login card, so registration is its own page - both reachable,
	 * nothing else is, which is what the rule is protecting.
	 *
	 * Config 'register_path'.
	 */
	public static function register_path(): string {
		return Config::path( 'register_path' );
	}

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
		// WooCommerce's form handlers, which act on any page they are sent to.
		'add-to-cart', 'woocommerce_checkout_place_order', 'apply_coupon', 'remove_coupon',
		'update_cart', 'order_again', 'pay_for_order', 'woocommerce_pay',
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

	/**
	 * What the request asks for: its query string and, for a POST, its body.
	 *
	 * WordPress reads public query vars from the body as well (WP::parse_request)
	 * and WooCommerce's checkout handler runs on any page a POST names it on,
	 * so a logged-out POST /entrar/ with pagename=... rendered that page and one
	 * with the checkout fields placed an order. Only the names are compared.
	 */
	private static function request_vars( string $request_uri ): array {
		$vars = self::query_of( $request_uri );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only key names are read, to refuse the request.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) && is_array( $_POST ) ) {
			$vars += array_fill_keys( array_map( 'strval', array_keys( $_POST ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return $vars;
	}

	private static function query_of( string $request_uri ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $query );

		return $query;
	}

	private static function selects_other_content( array $query ): bool {
		if ( array_intersect( array_keys( $query ), self::CONTENT_VARS ) ) {
			return true;
		}

		/*
		 * Once WordPress has registered its query vars, any public one counts:
		 * the fixed list missed bricks_template (any Bricks template, product
		 * templates with prices, rendered on /entrar/?bricks_template=...),
		 * template_tag, wc-api, the order-pay endpoint and attribute vars. The
		 * fixed list stays for the early gate, which runs before they exist.
		 */
		return (bool) array_intersect( array_keys( $query ), self::public_query_vars() );
	}

	/** @return string[] WordPress's public query vars, post type and taxonomy ones included; [] before init. */
	private static function public_query_vars(): array {
		if ( ! did_action( 'init' ) || ! isset( $GLOBALS['wp'] ) || ! $GLOBALS['wp'] instanceof \WP ) {
			return array();
		}

		$vars = (array) apply_filters( 'query_vars', $GLOBALS['wp']->public_query_vars );

		foreach ( get_post_types( array( 'publicly_queryable' => true ), 'objects' ) as $type ) {
			if ( $type->query_var ) {
				$vars[] = $type->query_var;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( $taxonomy->query_var ) {
				$vars[] = $taxonomy->query_var;
			}
		}

		return array_values( array_unique( array_map( 'strval', $vars ) ) );
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
		if ( in_array( untrailingslashit( $path ) . '/', array( self::login_path(), self::register_path() ), true ) ) {
			return true;
		}

		// Exact paths: as prefixes, /robots.txt/<anything> reached WordPress's
		// rewrite rules and rendered pages in trade chrome to a logged-out visitor.
		if ( in_array( $path, self::exact_public_paths(), true ) || in_array( untrailingslashit( $path ) . '/', self::exact_public_paths(), true ) ) {
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

		// WP-CLI is not a visitor: WooCommerce's CLI builds its commands from
		// internal REST requests, which this refused under --url=<trade host>.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
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
			/*
			 * LoginGate runs before HostRoutes, so it must let wp-admin through
			 * for HostRoutes to move interactive administration to the canonical
			 * retail host. The B2B frontend's admin-ajax.php and admin-post.php
			 * endpoints also match this prefix and deliberately remain local;
			 * their actions still enforce their own nonce and capability checks.
			 */
			'/wp-admin/',
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
		) );
	}

	/**
	 * Public paths matched exactly (a trailing slash either way): the login
	 * screen, robots.txt, the favicon, and WooCommerce's lost-password
	 * endpoint (Config 'lost_password_paths') - on the retail account page, on
	 * the trade one, and on the trade account's old address where reset links
	 * already mailed point. The reset link carries ?key= and ?login= on the
	 * same path.
	 *
	 * Paths, not a question to WooCommerce, because the gate runs before any
	 * plugin. CHANGING woocommerce_myaccount_lost_password_endpoint MEANS
	 * CHANGING THAT SETTING: get it wrong and the only symptom is a logged-out
	 * partner who cannot reset a password.
	 *
	 * @return string[]
	 */
	private static function exact_public_paths(): array {
		return array_merge( array( '/wp-login.php', '/robots.txt', '/favicon.ico' ), Config::paths( 'lost_password_paths' ) );
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
		$query       = self::request_vars( $request_uri );

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
		header( 'Location: https://' . Hosts::b2b() . self::login_path(), true, 302 );
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

		if ( in_array( $path, array( '/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php' ), true ) ) {
			self::guard_admin_endpoints( $path );

			return;
		}

		if ( 0 === strpos( $path, '/wp-json/' ) || 0 === strpos( $path, '/wp-admin/' ) ) {
			return;
		}

		self::maybe_redirect();
	}

	/**
	 * admin-ajax.php and admin-post.php, for logged-out visitors on the trade
	 * host: only what the login and application pages use.
	 *
	 * Both sit under /wp-admin/, which the gate lets through so HostRoutes can
	 * move administration to the retail host, and both stay on this host for
	 * the front end. That let a visitor with no login use WooCommerce's guest
	 * AJAX actions there - add to cart, checkout (guest checkout is on) - and
	 * its wp_loaded form handlers (?add-to-cart= on either URL). Running here,
	 * on wp_loaded 0, this comes before all of those.
	 */
	private static function guard_admin_endpoints( string $path ): void {
		if ( ! Hosts::is_b2b_host() || is_user_logged_in() ) {
			return;
		}

		$vars = self::request_vars( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared against a fixed list.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( self::admin_endpoint_allowed( $path, $action, $vars ) ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		exit;
	}

	/** Whether a logged-out request to admin-ajax/admin-post on the trade host may proceed. */
	public static function admin_endpoint_allowed( string $path, string $action, array $vars ): bool {
		return '/wp-admin/admin-ajax.php' === $path
			&& in_array( $action, self::public_ajax_actions(), true )
			&& ! self::selects_other_content( $vars );
	}

	/**
	 * The admin-ajax actions a logged-out visitor on the trade host may call:
	 * Bricks' form submission (login and application forms) and its nonce
	 * refreshes. Filterable for a site whose public pages need more.
	 *
	 * @return string[]
	 */
	public static function public_ajax_actions(): array {
		return (array) apply_filters( 'beltoft_commerce_b2b_public_ajax_actions', array(
			'bricks_form_submit',
			'bricks_regenerate_form_nonce',
			'bricks_regenerate_query_nonce',
		) );
	}

	public static function maybe_redirect(): void {
		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

		if ( ! self::should_gate( self::effective_path( $request_uri ), self::request_vars( $request_uri ) ) ) {
			return;
		}

		wp_safe_redirect( home_url( self::login_path() ), 302 );
		exit;
	}
}
