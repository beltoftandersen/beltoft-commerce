<?php

namespace BeltoftCommerce\Support;

use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Frontend\HostPages;

defined( 'ABSPATH' ) || exit;

/**
 * The trade hosts in Site Health (spec 2026-10-20, §1.1).
 *
 * - A direct test: what Hosts::problems() finds in the configuration, and
 *   what content_problems() finds in the pages (a trade path naming no page
 *   in its language, a root page with no copy in a trade host's language).
 * - An async test: each trade host's robots.txt answers 200 with a noindex
 *   header, and its home page sends a logged-out visitor to that host's own
 *   login page.
 * - A debug section: host, language, login path.
 */
class HostHealth {

	private const REST_NAMESPACE = 'beltoft-commerce/v1';
	private const REST_ROUTE     = '/site-health/trade-hosts';

	/** The paths each language must have a page for, when trade_paths names them. */
	private const PAGE_PATHS = array( 'login_path', 'register_path', 'account_path', 'quick_order_path' );

	public static function register(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * @param mixed $tests
	 * @return mixed
	 */
	public static function tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['beltoft_commerce_trade_hosts'] = array(
			'label' => __( 'Trade hosts', 'beltoft-commerce' ),
			'test'  => array( __CLASS__, 'direct_test' ),
		);

		if ( Hosts::is_b2b_configured() ) {
			$tests['async']['beltoft_commerce_trade_hosts_reachable'] = array(
				'label'             => __( 'Trade hosts answer', 'beltoft-commerce' ),
				'test'              => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
				'has_rest'          => true,
				'async_direct_test' => array( __CLASS__, 'async_test' ),
			);
		}

		return $tests;
	}

	public static function register_route(): void {
		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
			'methods'             => 'GET',
			'callback'            => static fn() => rest_ensure_response( self::async_test() ),
			'permission_callback' => static fn() => current_user_can( 'view_site_health_checks' ),
		) );
	}

	/** @return array The Site Health result. */
	public static function direct_test(): array {
		$config  = Hosts::problems();
		$content = self::content_problems();

		if ( ! $config && ! $content ) {
			return self::result(
				'good',
				__( 'The trade hosts are configured correctly', 'beltoft-commerce' ),
				__( 'Every trade host is a host of its own, in a language the site has, with its pages in place.', 'beltoft-commerce' ),
				'beltoft_commerce_trade_hosts'
			);
		}

		return self::result(
			$config ? 'critical' : 'recommended',
			__( 'The trade hosts need attention', 'beltoft-commerce' ),
			self::list_html( array_merge( $config, $content ) ),
			'beltoft_commerce_trade_hosts'
		);
	}

	/**
	 * What is missing in the pages: a trade_paths entry naming no published
	 * page in its language (or a page that is not the translation of the
	 * default language's), and a HostPages root page with no copy whose slug
	 * carries the trade prefix in a trade host's language.
	 *
	 * @return string[]
	 */
	public static function content_problems(): array {
		$problems = array();
		$paths    = Config::get( 'trade_paths' );

		foreach ( is_array( $paths ) ? $paths : array() as $code => $entries ) {
			$code = (string) $code;

			foreach ( self::PAGE_PATHS as $key ) {
				if ( ! is_array( $entries ) || ! array_key_exists( $key, $entries ) ) {
					continue;
				}

				$path = Config::path( $key, $code );
				$page = self::page_at( $path, $code );

				if ( ! $page ) {
					/* translators: 1: a path, 2: language code */
					$problems[] = sprintf( __( 'No published page at %1$s in "%2$s" (trade_paths).', 'beltoft-commerce' ), $path, $code );
					continue;
				}

				$default = self::page_at( Config::path( $key ), Language::default_code() );

				if ( $default && Language::default_sibling( $page ) !== $default ) {
					/* translators: 1: a path, 2: language code, 3: the default language's path */
					$problems[] = sprintf( __( 'The page at %1$s in "%2$s" is not the translation of %3$s.', 'beltoft-commerce' ), $path, $code, Config::path( $key ) );
				}
			}
		}

		$default = Language::default_code();

		foreach ( array_keys( Hosts::b2b_hosts() ) as $code ) {
			if ( $code === $default ) {
				continue;
			}

			foreach ( array_keys( HostPages::map() ) as $root ) {
				if ( ! HostPages::id( (string) $root, $code ) ) {
					/* translators: 1: a root page's key, 2: language code, 3: the slug prefix, e.g. b2b- */
					$problems[] = sprintf( __( 'The trade page "%1$s" has no published "%2$s" copy whose slug starts with %3$s.', 'beltoft-commerce' ), $root, $code, trim( (string) Config::get( 'trade_page_prefix' ), '/' ) );
				}
			}
		}

		return $problems;
	}

	/** A published page in a language whose slug is a path's last segment, or 0. */
	private static function page_at( string $path, string $code ): int {
		$slug = basename( untrailingslashit( $path ) );

		if ( '' === $slug ) {
			return 0;
		}

		$ids = get_posts( Language::every_language( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'name'        => $slug,
			'numberposts' => -1,
			'fields'      => 'ids',
		) ) );

		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( '' === $code || Language::of_post( $id ) === $code ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Each trade host, logged out: robots.txt is 200 with X-Robots-Tag noindex,
	 * and / is a 302 to that host's own login page.
	 *
	 * @return array The Site Health result.
	 */
	public static function async_test(): array {
		$problems = array();

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$code  = Hosts::b2b_language( $host );
			$login = 'https://' . $host . LoginGate::login_path( $code );

			$robots = wp_remote_get( 'https://' . $host . '/robots.txt', array( 'redirection' => 0, 'timeout' => 10 ) );

			if ( is_wp_error( $robots ) || 200 !== (int) wp_remote_retrieve_response_code( $robots ) || false === stripos( (string) wp_remote_retrieve_header( $robots, 'x-robots-tag' ), 'noindex' ) ) {
				/* translators: %s: host */
				$problems[] = sprintf( __( '%s/robots.txt does not answer 200 with an X-Robots-Tag: noindex header.', 'beltoft-commerce' ), $host );
			}

			$home = wp_remote_get( 'https://' . $host . '/', array( 'redirection' => 0, 'timeout' => 10, 'cookies' => array() ) );

			if ( is_wp_error( $home ) || 302 !== (int) wp_remote_retrieve_response_code( $home ) || (string) wp_remote_retrieve_header( $home, 'location' ) !== $login ) {
				/* translators: 1: host, 2: a URL */
				$problems[] = sprintf( __( '%1$s does not send a logged-out visitor to %2$s.', 'beltoft-commerce' ), $host, $login );
			}
		}

		if ( ! $problems ) {
			return self::result( 'good', __( 'Every trade host answers', 'beltoft-commerce' ), __( 'Each trade host keeps itself out of search engines and sends logged-out visitors to its own login page.', 'beltoft-commerce' ), 'beltoft_commerce_trade_hosts_reachable' );
		}

		return self::result( 'critical', __( 'A trade host does not answer as it should', 'beltoft-commerce' ), self::list_html( $problems ), 'beltoft_commerce_trade_hosts_reachable' );
	}

	/**
	 * @param mixed $info
	 * @return mixed
	 */
	public static function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$fields = array();

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$code = Hosts::b2b_language( $host );

			$fields[ $host ] = array(
				'label' => $host,
				'value' => sprintf( '%s %s', '' !== $code ? $code : '-', TradePath::prefix( $code ) . LoginGate::login_path( $code ) ),
			);
		}

		$fields['trade_fallback_language'] = array(
			'label' => 'trade_fallback_language',
			'value' => (string) Config::get( 'trade_fallback_language' ),
		);

		$info['beltoft-commerce-hosts'] = array(
			'label'  => __( 'Beltoft Commerce trade hosts', 'beltoft-commerce' ),
			'fields' => $fields,
		);

		return $info;
	}

	/** @param string[] $items */
	private static function list_html( array $items ): string {
		return '<ul>' . implode( '', array_map( static fn( $item ) => '<li>' . esc_html( $item ) . '</li>', $items ) ) . '</ul>';
	}

	private static function result( string $status, string $label, string $description, string $test ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Trade', 'beltoft-commerce' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}
}
