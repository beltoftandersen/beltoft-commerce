<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

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
	 * Paths that belong to the B2B host.
	 *
	 * Derived from LoginGate where it already owns the value, so the gate's
	 * allowlist and this list cannot drift apart and start disagreeing about
	 * which host a page lives on.
	 */
	public static function b2b_only_paths(): array {
		return (array) apply_filters( 'beltoft_commerce_b2b_only_paths', array(
			LoginGate::LOGIN_PATH,
			LoginGate::REGISTER_PATH,
			/*
			 * The trade account area. A literal, like the rest: this list is read
			 * before Account\B2BAccountPage exists.
			 */
			'/conta/',
			/*
			 * A literal, not QuickOrder\Page::PATH. This class is loaded by the
			 * mu-plugin before any plugin, so QuickOrder\Page does not exist
			 * yet and referencing it would fatal on every request. Page::PATH
			 * carries the same value and a comment pointing back here.
			 */
			'/encomenda-rapida/',
			// Also a literal, for the same reason as the line above.
			'/finalizar-b2b/',
			// Likewise. Account\B2BAccountPage::PATH carries the same value.
			'/conta-b2b/',
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
			'/b2b-',
		) );
	}

	public static function should_redirect( string $path, bool $is_bricks_builder = false ): bool {
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
		if ( $is_bricks_builder ) {
			return false;
		}

		/*
		 * WordPress resolves pages case-insensitively and after URL-decoding,
		 * so /B2B-loja/ and /%62%32b-loja/ rendered the trade page here - and,
		 * as a 200 on the retail host, went into its page cache.
		 */
		$path = strtolower( rawurldecode( $path ) );

		foreach ( self::b2b_only_paths() as $b2b_path ) {
			if ( 0 === strpos( $path, $b2b_path ) ) {
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

		$path = '/' . trim( (string) get_page_uri( $post ), '/' ) . '/';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, never stored.
		$is_bricks_builder = isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'];

		return self::should_redirect( $path, $is_bricks_builder ) ? 'https://' . Hosts::b2b() . $path : '';
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
		$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$admin_target = self::admin_redirect_target( $request_uri, $_SERVER['REQUEST_METHOD'] ?? 'GET' );

		if ( '' !== $admin_target ) {
			// wp_safe_redirect() is not loaded this early.
			header( 'Location: ' . $admin_target, true, 302 );
			exit;
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/';
		$is_bricks_builder = isset( $_GET['bricks'] ) && is_string( $_GET['bricks'] ) && 'run' === wp_unslash( $_GET['bricks'] );

		if ( ! self::should_redirect( $path, $is_bricks_builder ) ) {
			return;
		}

		/*
		 * The query string goes along: /entrar/?redirect_to= and the account
		 * links that carry a key lost theirs here and landed on the bare page.
		 */
		$query = wp_parse_url( $request_uri, PHP_URL_QUERY );

		// wp_safe_redirect() is pluggable and not loaded this early, and the
		// target is a different host, which it would reject in any case.
		header( 'Location: https://' . Hosts::b2b() . $path . ( is_string( $query ) && '' !== $query ? '?' . $query : '' ), true, 302 );
		exit;
	}
}
