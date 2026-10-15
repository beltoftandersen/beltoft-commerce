<?php

namespace BeltoftCommerce\Bricks;

use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Which header and footer the B2B host renders, and where it renders none.
 *
 * TWO JOBS, ONE SEAM. Bricks resolves one header and one footer per request by
 * scoring template conditions, and those conditions cannot see the hostname -
 * §2.3 keeps host out of application logic, and Bricks has no host condition
 * for templates anyway. `bricks/active_templates` runs after that resolution,
 * which makes it the one place both decisions can be made.
 *
 * WHY NOT GIVE THE B2B TEMPLATES "ENTIRE WEBSITE". That is what the Bricks
 * documentation suggests for a header, and it would be wrong here: the retail
 * header already holds that condition, both would score 2, and Bricks resolves
 * a same-score tie by query order. The B2B templates therefore carry NO
 * conditions - they can never win by themselves - and are selected here
 * explicitly. Deterministic in both directions: retail keeps its own header
 * because it outscores a conditionless template, B2B gets its own because this
 * filter says so.
 *
 * RESOLVED BY SLUG, NOT ID, because production replays these as new posts with
 * different ids (see docs/MIGRATION-CHANGELOG.md).
 */
class HostChrome {

	private const HEADER_SLUG = 'b2b-cabecalho';
	private const FOOTER_SLUG = 'b2b-rodape';

	public static function register(): void {
		add_filter( 'bricks/active_templates', array( __CLASS__, 'choose' ), 10, 3 );
	}

	/**
	 * @param array  $active_templates Resolved template ids keyed by part.
	 * @param int    $post_id          The post being rendered.
	 * @param string $content_type     Bricks content type for this request.
	 * @return array
	 */
	public static function choose( $active_templates, $post_id = 0, $content_type = '' ) {
		if ( ! is_array( $active_templates ) ) {
			return $active_templates;
		}

		/*
		 * THE HOST CHECK COMES FIRST, and that order is load-bearing. Both
		 * stores are one WordPress install, so /entrar/ and /registo/ are
		 * reachable on the retail host too. Stripping chrome before checking
		 * the host left retail visitors on those URLs with no header and no
		 * footer - and that is retail guest output, which Stage 1 acceptance
		 * treats as byte-frozen.
		 */
		if ( ! Hosts::is_b2b_host() ) {
			return $active_templates;
		}

		/*
		 * The login and registration pages get no chrome at all. The header's
		 * navigation points at the catalog, and on the B2B host every one of
		 * those links is what LoginGate bounces back to this page - a menu
		 * where each item returns you to where you already are.
		 */
		if ( self::is_gate_page() ) {
			/*
			 * 0, not unset(): Bricks reads both keys unguarded in four places
			 * and treats only a value above 0 as a template, so removing them
			 * logged undefined-index warnings on every login page view.
			 */
			$active_templates['header'] = 0;
			$active_templates['footer'] = 0;

			return $active_templates;
		}

		$header = self::template_id( self::HEADER_SLUG );
		$footer = self::template_id( self::FOOTER_SLUG );

		if ( $header ) {
			$active_templates['header'] = $header;
		}

		if ( $footer ) {
			$active_templates['footer'] = $footer;
		}

		return $active_templates;
	}

	private static function is_gate_page(): bool {
		if ( ! is_page() ) {
			return false;
		}

		$slugs = array_filter( array(
			trim( LoginGate::LOGIN_PATH, '/' ),
			trim( LoginGate::REGISTER_PATH, '/' ),
		) );

		return ! empty( $slugs ) && is_page( $slugs );
	}

	/**
	 * Resolved once per request: this filter runs for the header and again for
	 * the footer, and a template lookup is a database query either way.
	 */
	private static function template_id( string $slug ): int {
		static $cache = array();

		if ( isset( $cache[ $slug ] ) ) {
			return $cache[ $slug ];
		}

		$template = get_page_by_path( $slug, OBJECT, 'bricks_template' );

		$cache[ $slug ] = $template instanceof \WP_Post ? (int) $template->ID : 0;

		return $cache[ $slug ];
	}
}
