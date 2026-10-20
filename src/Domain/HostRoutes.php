<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Language;
use BeltoftCommerce\Support\TradePath;

defined( 'ABSPATH' ) || exit;

// Loaded early by path (mu-plugin); see Support/Hosts.php for why Config is required here.
require_once dirname( __DIR__ ) . '/Support/Config.php';
require_once dirname( __DIR__ ) . '/Support/Hosts.php';
require_once dirname( __DIR__ ) . '/Support/TradePath.php';

/**
 * The B2B-only pages exist on the B2B host and nowhere else.
 *
 * WHY THIS EXISTS. Both stores are one WordPress install, so every page is
 * reachable on both hostnames. `/encomendar/` was answering 200 on the public
 * retail domain with twenty-four product rows at retail prices - a second,
 * half-working shop page on the live store, competing with `/loja/` for the
 * same catalog. `/entrar/` and `/registo/` were likewise serving a B2B login
 * and an application form wearing retail chrome.
 *
 * WHY SO EARLY. This runs from the mu-plugin, before any plugin loads, for the
 * same reason LoginGate's early gate does - and the reason is performance, not
 * only correctness. Redirecting here costs a header and an exit. Doing it on
 * `template_redirect` instead would mean WordPress has already bootstrapped
 * WooCommerce, run the product query, and started the Bricks render before
 * throwing all of it away. It also keeps these paths out of the retail page
 * cache, where they would otherwise occupy entries for pages nobody should be
 * served.
 *
 * A REDIRECT RATHER THAN A 404, because the paths are real - they are simply on
 * the other hostname, and a person who reached one has a destination.
 */
class HostRoutes {

	/**
	 * Return the canonical URL for an interactive admin request on the B2B host.
	 *
	 * Frontend AJAX and form handlers deliberately stay on the requesting host:
	 * WooCommerce and custom B2B forms use them without entering wp-admin.
	 */
	public static function admin_redirect_target( string $request_uri, string $method = 'GET' ): string {
		if ( ! Hosts::is_b2b_host() || ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			return '';
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		if ( in_array( $path, array( '/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php' ), true ) ) {
			return '';
		}

		$is_admin = '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' );
		$is_login = '/wp-login.php' === $path;

		if ( ! $is_admin && ! $is_login ) {
			return '';
		}

		$query = wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( $is_login && is_string( $query ) ) {
			$args = array();
			parse_str( $query, $args );

			// Logout must clear the host-only B2B session cookie on this host.
			if ( isset( $args['action'] ) && 'logout' === $args['action'] ) {
				return '';
			}
		}

		return 'https://' . Hosts::retail() . $path . ( is_string( $query ) && '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Paths that belong to the B2B host, in a language (Config 'trade_paths')
	 * when a code is given.
	 *
	 * Derived from LoginGate where it already owns the value, so the gate's
	 * allowlist and this list cannot drift apart and start disagreeing about
	 * which host a page lives on.
	 */
	public static function b2b_only_paths( string $code = '' ): array {
		/*
		 * From Config, not from the page classes: this list is read by the
		 * mu-plugin before any plugin class exists.
		 */
		return (array) apply_filters( 'beltoft_commerce_b2b_only_paths', array_merge( array(
			LoginGate::login_path( $code ),
			LoginGate::register_path( $code ),
			// The trade account area.
			Config::path( 'account_path', $code ),
			Config::path( 'quick_order_path', $code ),
		), Config::paths( 'checkout_legacy_paths', $code ), Config::paths( 'account_legacy_paths', $code ), Config::paths( 'extra_trade_only_paths', $code ), array(
			/*
			 * The trade posts' PHYSICAL slugs, matched as a prefix.
			 *
			 * Frontend\HostPages serves them at root paths on the trade host
			 * (/loja/, /termos-condicoes/ ...); their physical slugs are b2b-loja,
			 * b2b-termos-condicoes and so on, and are never linked. This line is
			 * what keeps a physical slug off the retail host - without it,
			 * /b2b-loja/ would render the trade ordering page, with trade prices,
			 * to anyone on the consumer site who guessed it.
			 */
			strtolower( (string) Config::get( 'trade_page_prefix' ) ),
		) ), $code );
	}

	public static function should_redirect( string $path, bool $is_bricks_builder = false, string $code = '' ): bool {
		return self::may_redirect( $is_bricks_builder ) && self::is_b2b_only_path( $path, $code );
	}

	/** Whether this request may be sent to the trade host at all, whatever it asked for. */
	private static function may_redirect( bool $is_bricks_builder ): bool {
		// No trade host configured: these pages have nowhere to go.
		if ( Hosts::is_b2b_host() || ! Hosts::is_b2b_configured() ) {
			return false;
		}

		/*
		 * Bricks must open these posts on the canonical retail host. WordPress
		 * login cookies are host-only, so sending the editor to the B2B host
		 * swaps the administrator session for that host's partner session and
		 * Bricks rejects it with error_role_manager. Bricks validates both login
		 * state and edit_post itself before it starts the builder.
		 */
		return ! $is_bricks_builder;
	}

	/** Whether a path (without its language prefix) belongs to the trade host, whichever host is asking. */
	public static function is_b2b_only_path( string $path, string $code = '' ): bool {
		/*
		 * WordPress resolves pages case-insensitively and after URL-decoding,
		 * so /B2B-loja/ and /%62%32b-loja/ rendered the trade page here - and,
		 * as a 200 on the retail host, went into its page cache.
		 */
		$path = strtolower( rawurldecode( $path ) );

		foreach ( self::b2b_only_paths( $code ) as $b2b_path ) {
			// An empty entry (trade_page_prefix set to '') would match every path.
			if ( '' !== (string) $b2b_path && 0 === strpos( $path, (string) $b2b_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Where a trade page that WordPress resolved on the retail host belongs.
	 *
	 * The path check above sees only the URL, and a URL is not the only way to
	 * name a page: ?pagename=b2b-loja and ?page_id= reach it from any path. Once
	 * WordPress has decided what it is serving, that decides - whatever the
	 * request looked like.
	 *
	 * @return string The trade-host URL, or '' when the page belongs here.
	 */
	public static function resolved_redirect_target(): string {
		if ( Hosts::is_b2b_host() || ! is_singular() ) {
			return '';
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		/*
		 * An editor's preview stays here. HostPages::preview_link() keeps it on
		 * the retail host on purpose - the admin session lives there - and
		 * sending it to the trade host landed the editor on its login page.
		 */
		if ( is_preview() && current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		// Pages only: a Bricks template or other post type with a b2b- slug is
		// not a trade page, and its slug is no address on the trade host.
		if ( 'page' !== $post->post_type ) {
			return '';
		}

		$path = '/' . trim( (string) get_page_uri( $post ), '/' ) . '/';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, never stored.
		$is_bricks_builder = isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'];

		/*
		 * By identity, not by path: a translated copy of a trade page has its
		 * own slug (/es/acceder/ for /entrar/), which no path list names, and
		 * answered 200 here. TradePages judges it by its default-language page.
		 */
		$is_trade_page = class_exists( '\\BeltoftCommerce\\Domain\\TradePages' )
			? TradePages::is_trade_page( (int) $post->ID )
			: self::is_b2b_only_path( $path );

		if ( ! $is_trade_page || ! self::may_redirect( $is_bricks_builder ) ) {
			return '';
		}

		/*
		 * To the trade host of the page's language (a language without one:
		 * the fallback's), where the trade host serves it: a page mapped by
		 * HostPages - or a translated copy of one - lives at its root path
		 * (/loja/, /es/tienda/), and its physical slug (/b2b-loja/) is never
		 * to be linked.
		 */
		$code = Language::of_post( (int) $post->ID );
		$host = Hosts::b2b_for( $code );
		$root = class_exists( '\\BeltoftCommerce\\Frontend\\HostPages' ) ? \BeltoftCommerce\Frontend\HostPages::root_for( (int) $post->ID ) : '';

		if ( '' !== $root ) {
			return 'https://' . $host . TradePath::prefix( $code ) . '/' . trim( $root, '/' ) . '/';
		}

		/*
		 * Otherwise at its own address, language prefix and all: in path mode
		 * the Spanish copy is /es/acceder/, and sending it to /acceder/
		 * dropped the language. The permalink is the address the language
		 * plugin gives it.
		 */
		$link = wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );

		return 'https://' . $host . ( is_string( $link ) && '' !== trim( $link, '/' ) ? '/' . trim( $link, '/' ) . '/' : $path );
	}

	/** Sends a trade page resolved on the retail host to the trade host. */
	public static function maybe_redirect_resolved(): void {
		$target = self::resolved_redirect_target();

		if ( '' === $target ) {
			return;
		}

		// A different host, which wp_safe_redirect() would refuse.
		wp_redirect( $target, 302 );
		exit;
	}
	/**
	 * Called from the mu-plugin, before WordPress has loaded a plugin.
	 */
	public static function maybe_redirect_early(): void {
		$request_uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$admin_target = self::admin_redirect_target( $request_uri, $_SERVER['REQUEST_METHOD'] ?? 'GET' );

		if ( '' !== $admin_target ) {
			// wp_safe_redirect() is not loaded this early.
			header( 'Location: ' . $admin_target, true, 302 );
			exit;
		}

		$target = self::early_target( $request_uri, null, self::is_bricks_builder() );

		if ( '' === $target ) {
			return;
		}

		// wp_safe_redirect() is pluggable and not loaded this early, and the
		// target is a different host, which it would reject in any case.
		header( 'Location: ' . $target, true, 302 );
		exit;
	}

	/**
	 * Where the early handler sends a trade-only path asked for on a retail
	 * host, or ''. Pure, for tests.
	 *
	 * Once trade hosts are per language, only on the site address: there the
	 * language is the default one (or a configured path prefix's), and its
	 * trade host is known before any plugin loads. A language domain's
	 * language is not known until the language plugin has run, so those
	 * requests wait for maybe_redirect_on_language() at plugins_loaded:2.
	 *
	 * The query string goes along: /entrar/?redirect_to= and the account
	 * links that carry a key lost theirs here and landed on the bare page.
	 */
	public static function early_target( string $request_uri, ?string $host = null, bool $is_bricks_builder = false ): string {
		$host = Hosts::normalise( (string) ( $host ?? ( $_SERVER['HTTP_HOST'] ?? '' ) ) );

		if ( Hosts::is_b2b_host( $host ) || ! self::may_redirect( $is_bricks_builder ) ) {
			return '';
		}

		if ( Hosts::b2b_hosts() && ! Hosts::is_retail_host( $host ) ) {
			return '';
		}

		$path = (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/' );

		list( $code, $rest ) = TradePath::split( $path, $host );

		if ( ! self::is_b2b_only_path( $rest, $code ) ) {
			return '';
		}

		return 'https://' . Hosts::b2b_for( $code ) . $path . self::query_of( $request_uri );
	}

	/**
	 * Trade-only paths on a retail host, once the language plugin has decided
	 * the request's language (Zerolang: plugins_loaded:1 in a path mode,
	 * while its file loads under separate domains). Registered by the early
	 * handler at plugins_loaded:2, not under WP-CLI.
	 *
	 * Acts on what the early handler leaves alone: every retail host but the
	 * site address once trade hosts are per language, and language-prefixed
	 * paths, whose prefix the early handler may not know. Cheap: a header
	 * and an exit, before WordPress queries or renders anything.
	 */
	public static function maybe_redirect_on_language(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$host        = Hosts::normalise( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );

		if ( '' === $host || Hosts::is_b2b_host( $host ) ) {
			return;
		}

		$path = (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/' );

		// Single trade host and no language prefix: the early handler has answered already.
		if ( ! Hosts::b2b_hosts() && TradePath::split( $path, $host )[1] === $path ) {
			return;
		}

		$code   = class_exists( '\\BeltoftCommerce\\Support\\Language' ) ? Language::current() : '';
		$target = self::language_target( $request_uri, $host, $code, self::is_bricks_builder() );

		if ( '' === $target ) {
			return;
		}

		header( 'Location: ' . $target, true, 302 );
		header( 'Cache-Control: no-store, private' );
		exit;
	}

	/**
	 * Where a trade-only path asked for on a retail host in a language goes:
	 * that language's trade host (a language without one: the fallback's),
	 * path and query unchanged, or ''. Pure, for tests.
	 *
	 * A path prefix names the language when there is one; otherwise the
	 * language plugin's answer for the request. The paths of the target
	 * trade host's own language count too, so /sign-in/ on the German
	 * domain reaches the English portal it belongs to - and so do the
	 * default language's, so a link to /entrar/ in a retail header works on
	 * every language domain (the trade host's gate then shows its own login).
	 */
	public static function language_target( string $request_uri, string $host, string $code, bool $is_bricks_builder = false ): string {
		if ( Hosts::is_b2b_host( $host ) || ! self::may_redirect( $is_bricks_builder ) ) {
			return '';
		}

		$path = (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/' );

		list( $split_code, $rest ) = TradePath::split( $path, $host );

		if ( $rest !== $path || '' === $code ) {
			$code = $split_code;
		}

		$target = Hosts::b2b_for( $code );

		$matches = false;

		foreach ( array_unique( array( $code, Hosts::b2b_language( $target ), '' ) ) as $paths_of ) {
			$matches = $matches || self::is_b2b_only_path( $rest, $paths_of );
		}

		if ( ! $matches ) {
			return '';
		}

		return 'https://' . $target . $path . self::query_of( $request_uri );
	}

	private static function query_of( string $request_uri ): string {
		$query = wp_parse_url( $request_uri, PHP_URL_QUERY );

		return is_string( $query ) && '' !== $query ? '?' . $query : '';
	}

	private static function is_bricks_builder(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, never stored.
		return isset( $_GET['bricks'] ) && is_string( $_GET['bricks'] ) && 'run' === wp_unslash( $_GET['bricks'] );
	}
}
