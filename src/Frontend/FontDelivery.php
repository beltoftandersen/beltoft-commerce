<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Stop the trade pages reflowing when the webfont finally lands.
 *
 * THE SYMPTOM was the product list and the inline cart "jumping" shortly after
 * load. It is not a layout bug: every element that moved was text, and all of
 * it got wider at the same instant. Measured on /encomendar/, the four Onest
 * weights finished downloading at ~2.3s with font-display:swap and no preload,
 * so the whole page rendered in the fallback face and then re-laid-out when
 * the real one arrived. Header links, the cart button, the filter counts and
 * every product row shifted together.
 *
 * TWO THINGS MADE IT SLOW. The fonts are referenced on the retail host, which
 * is cross-origin to a B2B page, so the browser had to open a second
 * connection before the first byte. And Bricks emits one @font-face per
 * weight, each with its own URL - but the files behind those URLs are
 * byte-identical copies of a single variable font, so the page fetched the
 * same 32KB four times over four requests.
 *
 * THE FIX is to point every Onest face at one URL, root-relative so it is
 * same-origin on whichever host is rendering, and to preload that one file in
 * the head. Four cross-origin requests become one same-origin request that
 * starts immediately instead of after the stylesheet is parsed.
 *
 * WHY THE IDENTITY CHECK. Collapsing nine weights onto one file is only
 * lossless while those files really are the same bytes. If someone later
 * uploads genuine weights, silently serving Regular for all of them would be
 * a rendering bug that nobody would connect to a performance change made
 * months earlier. So the hashes are compared before anything is rewritten, and
 * if they ever differ this class does nothing at all. The comparison touches
 * the disk, so its result is cached.
 *
 * Retail is untouched: this answers only on the B2B host. The same duplication
 * exists there and is worth fixing, but that is a change to retail output and
 * belongs with a parity run rather than riding along here.
 */
class FontDelivery {

	/** Cached result of the byte-identity check. */
	private const TRANSIENT = 'beltoft_commerce_font_delivery';

	/** Bricks' option holding the generated @font-face rules. */
	private const RULES_OPTION = 'bricks_font_face_rules';

	public static function register(): void {
		// Late, so Bricks has already attached its inline @font-face CSS.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'consolidate' ), 9999 );
		// Early in the head, so the fetch starts before the stylesheet is parsed.
		add_action( 'wp_head', array( __CLASS__, 'preload' ), 2 );
	}

	public static function preload(): void {
		$url = self::canonical_url();

		if ( '' === $url ) {
			return;
		}

		/*
		 * crossorigin is required even for a same-origin font: fonts are always
		 * fetched in anonymous CORS mode, and a preload without it is treated as
		 * a different request, downloading the file a second time.
		 */
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( $url )
		);
	}

	public static function consolidate(): void {
		$url = self::canonical_url();

		if ( '' === $url ) {
			return;
		}

		$styles = wp_styles();
		$handle = 'bricks-frontend';

		if ( ! isset( $styles->registered[ $handle ] ) ) {
			return;
		}

		$after = $styles->registered[ $handle ]->extra['after'] ?? array();

		if ( ! is_array( $after ) ) {
			return;
		}

		foreach ( $after as $i => $css ) {
			if ( ! is_string( $css ) || false === strpos( $css, '@font-face' ) ) {
				continue;
			}

			$after[ $i ] = self::rewrite( $css, $url );
		}

		$styles->registered[ $handle ]->extra['after'] = $after;
	}

	/**
	 * Point every Onest weight at the one file, leaving other families alone.
	 */
	private static function rewrite( string $css, string $url ): string {
		return (string) preg_replace(
			'#url\((["\']?)[^)"\']*/onest-\d{3}-normal\.woff2\1\)#i',
			'url(' . $url . ')',
			$css
		);
	}

	/**
	 * The single URL every Onest face should use, or '' if consolidating would
	 * not be lossless.
	 */
	private static function canonical_url(): string {
		if ( ! self::applies() ) {
			return '';
		}

		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return (string) ( $cached['url'] ?? '' );
		}

		$url = self::compute_canonical_url();

		set_transient( self::TRANSIENT, array( 'url' => $url ), DAY_IN_SECONDS );

		return $url;
	}

	private static function compute_canonical_url(): string {
		$rules = get_option( self::RULES_OPTION, '' );

		if ( ! is_string( $rules ) || '' === $rules ) {
			return '';
		}

		if ( ! preg_match_all( '#url\(["\']?([^)"\']*/onest-\d{3}-normal\.woff2)["\']?\)#i', $rules, $m ) ) {
			return '';
		}

		$urls = array_values( array_unique( $m[1] ) );

		if ( count( $urls ) < 2 ) {
			// Nothing to collapse; a single face is already one request.
			return '';
		}

		$uploads = wp_get_upload_dir();
		$hashes  = array();

		foreach ( $urls as $candidate ) {
			$path = self::local_path( $candidate, $uploads );

			if ( '' === $path || ! is_readable( $path ) ) {
				return '';
			}

			$hash = md5_file( $path );

			if ( false === $hash ) {
				return '';
			}

			$hashes[] = $hash;
		}

		if ( count( array_unique( $hashes ) ) > 1 ) {
			// Real, distinct weights. Leave them alone.
			return '';
		}

		return self::root_relative( $urls[0], $uploads );
	}

	private static function local_path( string $url, array $uploads ): string {
		$base = trailingslashit( $uploads['baseurl'] );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$rel  = wp_parse_url( $base, PHP_URL_PATH );

		if ( ! $path || ! $rel || 0 !== strpos( $path, $rel ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . ltrim( substr( $path, strlen( $rel ) ), '/' );
	}

	/**
	 * Absolute-on-retail becomes root-relative, so the font is same-origin on
	 * whichever host renders the page and no second connection is opened.
	 */
	private static function root_relative( string $url, array $uploads ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return $path ? $path : $url;
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}
}
