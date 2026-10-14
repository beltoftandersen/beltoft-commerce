<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Ships the ordering page without its rows, then fetches them.
 *
 * The page is about 420ms of shell - header, filters, cart, footer - and 370ms
 * of product rows. Rendering them together means nothing is on screen until
 * both are done. Fetched separately, and with Frontend\QueryResultCache serving
 * the rows in ~136ms instead of ~590ms, the partner sees the page at about
 * 0.5s and the list about 0.15s later, against 0.87s for everything today.
 *
 * WHY THERE IS A COOKIE. On its own this trades a slow page for a blank one
 * whenever JavaScript does not run - and JavaScript not running is not
 * hypothetical here: until today a retail menu script threw on every trade page
 * before anything else executed. So the page only ships without rows to a
 * browser that has already proved, on a previous visit, that it can fetch and
 * inject them. The first visit is rendered the ordinary way. If the fetch ever
 * fails the script clears the cookie, so the very next load is server-rendered
 * again. The failure mode is one slow page, not an empty catalogue.
 *
 * It applies only where it helps: the trade ordering page, first page, no
 * filters or search in the URL. A filtered or paged request is already going
 * through the AJAX path the cache covers.
 *
 * DISABLED, AND NOT REGISTERED IN Plugin.php.
 *
 * It starves the filters. Bricks' filter elements decide which options to show
 * from the results of the query they target - a term with nothing behind it is
 * not offered - so handing that query no posts empties the filters along with
 * the list. Measured on the ordering page: 67 checkbox options and 24 select
 * options server-rendered, against 0 and 7 with two-phase active. The page
 * arrived faster and could no longer be filtered, which on an ordering list of
 * this size is the worse page.
 *
 * This is not a priority or an element-id problem, so restricting the emptying
 * to the loop element does not fix it: the filters are reading the loop's own
 * result set, which is exactly the thing being emptied. Any real version of
 * this has to render the filters from a query of their own - a second,
 * unfiltered count query - before the rows can be withheld. Left in place
 * because that is a viable route and the measurements above are the reason to
 * take it, not a reason to abandon it.
 */
class TwoPhaseList {

	/** Set by the script once it has successfully injected rows. */
	public const COOKIE = 'blt_rows_js';

	public static function register(): void {
		add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'empty_first_paint' ), 1000, 3 );
		add_action( 'wp_head', array( __CLASS__, 'prefetch' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'script' ), 100 );

		/*
		 * The probe has to run when two-phase is NOT active, or the cookie it
		 * sets could never be set: the first version gated the whole script
		 * behind the cookie it was supposed to write, so the feature could
		 * never turn itself on and both visits rendered server-side. Found by
		 * measuring rather than by reading.
		 */
		add_action( 'wp_footer', array( __CLASS__, 'probe' ), 100 );
	}

	/**
	 * Hand the loop no posts, so the page renders without rows.
	 *
	 * Priority 1000 to sit alongside the other query filters on this hook,
	 * which merge at that priority for the same reason.
	 *
	 * @param array $query_vars
	 * @param array $settings
	 * @param mixed $element_id
	 * @return array
	 */
	public static function empty_first_paint( $query_vars, $settings = array(), $element_id = null ) {
		if ( ! self::applies() || ! is_array( $query_vars ) ) {
			return $query_vars;
		}

		if ( 'product' !== ( $query_vars['post_type'] ?? '' ) && ! in_array( 'product', (array) ( $query_vars['post_type'] ?? array() ), true ) ) {
			return $query_vars;
		}

		self::$element_id = (string) $element_id;

		// Nothing to render, but the element and its wrapper still exist for
		// the script to fill.
		$query_vars['post__in']       = array( 0 );
		$query_vars['posts_per_page'] = 1;

		return $query_vars;
	}

	private static string $element_id = '';

	/**
	 * The loop's element id, read from the page rather than waiting for the
	 * query filter to run - wp_head happens before any of the page renders.
	 */
	private static function resolve_element_id(): string {
		if ( '' !== self::$element_id ) {
			return self::$element_id;
		}

		foreach ( (array) get_post_meta( get_queried_object_id(), '_bricks_page_content_2', true ) as $element ) {
			$settings = (array) ( $element['settings'] ?? array() );

			if ( empty( $settings['hasLoop'] ) ) {
				continue;
			}

			if ( in_array( 'product', (array) ( $settings['query']['post_type'] ?? array() ), true ) ) {
				self::$element_id = (string) $element['id'];
				break;
			}
		}

		return self::$element_id;
	}

	/**
	 * Records that this browser can do the second half.
	 *
	 * Only on the page that would use it, and only when two-phase is not
	 * already running - once it is, the injector maintains the cookie itself.
	 */
	public static function probe(): void {
		if ( ! self::on_ordering_page() || self::applies() ) {
			return;
		}

		?>
<script>
/*
 * Deferred, because bricksData is printed later in the footer than this used
 * to run: the probe tested for it, found nothing, and never wrote the cookie -
 * so two-phase could never switch itself on. Priority 100 puts this after
 * Bricks; DOMContentLoaded makes it not depend on that ordering at all.
 */
document.addEventListener('DOMContentLoaded', function () {
	try {
		var name = <?php echo wp_json_encode( self::COOKIE ); ?>;

		// Never overwrite a recorded refusal; only a browser with no opinion
		// yet gets opted in.
		if ( document.cookie.indexOf( name + '=' ) === -1
			&& window.fetch && window.bricksData && window.bricksData.restApiUrl ) {
			document.cookie = name + '=1; Max-Age=2592000; path=/; SameSite=Lax';
		}
	} catch ( e ) {}
});
</script>
		<?php
	}

	/** The trade ordering page, unfiltered, first page - the shared gate. */
	/**
	 * The URL of the ordering page being viewed.
	 *
	 * The list re-fetches itself against this, so it has to be the page the
	 * buyer is actually on. get_permalink() of the queried object gives that;
	 * the live ordering page is only the fallback for a request that somehow
	 * has no queried page.
	 */
	private static function current_page_url(): string {
		$id  = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$url = $id ? get_permalink( $id ) : '';

		return is_string( $url ) && '' !== $url
			? $url
			: home_url( QuickOrderPagePathShim::ordering_path() );
	}

	private static function on_ordering_page(): bool {
		if ( ! Hosts::is_b2b_host() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! QuickOrderPagePathShim::is_ordering_page() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return empty( $_GET ) && (int) get_query_var( 'paged' ) <= 1;
	}

	/**
	 * Starts fetching the rows in the head, before anything else loads.
	 *
	 * The first version waited for DOMContentLoaded to fire the request, which
	 * is about 380ms in - so the rows landed at 1001ms against 855ms for simply
	 * rendering them server-side, and two-phase made the page worse at its one
	 * job. Nothing about the request needs the DOM; only the injection does. So
	 * it goes out here, roughly as soon as the HTML starts arriving, and the
	 * footer script waits on the promise rather than starting the work.
	 *
	 * The values Bricks would normally supply are printed here directly,
	 * because bricksData itself is not defined until the footer.
	 */
	public static function prefetch(): void {
		if ( ! self::applies() ) {
			return;
		}

		$payload = array(
			'postId'            => get_queried_object_id(),
			'queryElementId'    => '',
			'filters'           => (object) array(),
			'selectedFilters'   => array(),
			'pageFilters'       => array(),
			// The page being viewed, not the live ordering page: on the working
			// page at /loja/ a hard-coded base would send every
			// filter and page change back to the page this copy exists to leave
			// alone.
			'baseUrl'           => self::current_page_url(),
			'mainQueryId'       => '',
			'infinitePage'      => 1,
			'originalQueryVars' => wp_json_encode( array() ),
			'lang'              => '',
			'nonce'             => wp_create_nonce( 'bricks-nonce' ),
		);
		?>
<script>
window.beltoftRows = (function () {
	var payload = <?php echo wp_json_encode( $payload ); ?>;
	payload.queryElementId = <?php echo wp_json_encode( self::resolve_element_id() ); ?>;

	if ( ! window.fetch || ! payload.queryElementId ) { return null; }

	return fetch(<?php echo wp_json_encode( rest_url( 'bricks/v1/query_result' ) ); ?>, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
		body: JSON.stringify(payload)
	}).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
})();
</script>
		<?php
	}

	public static function script(): void {
		if ( ! self::applies() || '' === self::resolve_element_id() ) {
			return;
		}

		?>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var cookie = <?php echo wp_json_encode( self::COOKIE ); ?>;

	/*
	 * Records a refusal rather than clearing the cookie.
	 *
	 * Deleting it meant the probe simply set it again on the next
	 * server-rendered load, two-phase failed again, and the page reloaded -
	 * every other visit, forever, for anyone whose browser could not complete
	 * the fetch. A '0' is sticky: applies() wants '1', and the probe leaves an
	 * existing value alone.
	 */
	function giveUp() {
		document.cookie = cookie + '=0; Max-Age=2592000; path=/; SameSite=Lax';
	}

	var pending = window.beltoftRows;
	var target = document.querySelector('.js-ajax-grid');

	if ( ! pending || ! target ) { giveUp(); window.location.reload(); return; }

	pending.then(function (j) {
		if ( ! j || ! j.html ) { return Promise.reject('empty'); }

		target.insertAdjacentHTML('beforeend', j.html);
		document.cookie = cookie + '=1; Max-Age=2592000; path=/; SameSite=Lax';
		document.dispatchEvent(new CustomEvent('beltoft/rows-loaded'));
	}).catch(function () {
		// Nothing was injected; fall back to a server-rendered page rather than
		// leaving the partner looking at an empty catalogue.
		giveUp();
		window.location.reload();
	});
});
</script>
		<?php
	}

	/**
	 * Only where it helps, and only for a browser that has already proved it
	 * can do the second half.
	 */
	private static function applies(): bool {
		static $applies = null;

		if ( null !== $applies ) {
			return $applies;
		}

		$applies = false;

		if ( ! apply_filters( 'beltoft_commerce_two_phase_list', true ) ) {
			return $applies;
		}

		// '1' means proved; '0' means it was tried and failed. Anything else,
		// including absent, means render the ordinary way.
		if ( '1' !== ( $_COOKIE[ self::COOKIE ] ?? '' ) ) {
			return $applies;
		}

		if ( ! self::on_ordering_page() ) {
			return $applies;
		}

		$applies = true;

		return $applies;
	}
}
