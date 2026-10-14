<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks' filter-search goes through Relevanssi, like the rest of the site.
 *
 * WHY THIS IS NEEDED. Relevanssi owns search here: it hooks `posts_pre_query`
 * at priority 99 and short-circuits WP_Query entirely. But it only takes over a
 * query that is a search AND the main query, unless the query opts in with
 * `relevanssi => true`. Bricks' filter-search builds its own WP_Query inside an
 * AJAX endpoint, which is neither, so it silently fell through to core's
 * search - title, excerpt and content only.
 *
 * The visible symptom was that searching a SKU returned nothing while the SKU
 * was sitting in the Relevanssi index. Nothing errored: two search engines were
 * running on the same site and the filter was using the weaker one.
 *
 * This is also why the SKU is not matched by a query clause of our own. Adding
 * a `_sku` join to `posts_clauses` does nothing at all, because Relevanssi has
 * already decided the result set before those clauses are used.
 */
class RelevanssiSearch {

	/**
	 * MUST run after 999, and that is not a style choice.
	 *
	 * Bricks Query Filters merge the filter's own values — including the
	 * search term — onto this same hook at priority 999, saying so in the
	 * source: "As long as using Query Filters, the filter's query var should
	 * be the last" (query-filters.php:1311-1380). At priority 10 this
	 * callback ran BEFORE the term existed, saw no `s`, and left the query
	 * alone; the filter then added `s` and core's title/content search
	 * answered it. Searching a SKU returned nothing, with no error anywhere
	 * and the SKU sitting in the Relevanssi index the whole time.
	 */
	private const PRIORITY = 1000;

	public static function register(): void {
		add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'use_relevanssi' ), self::PRIORITY, 1 );
	}

	/**
	 * @param array $query_vars Query vars Bricks is about to run.
	 * @return array
	 */
	public static function use_relevanssi( $query_vars ) {
		if ( ! is_array( $query_vars ) ) {
			return $query_vars;
		}

		if ( ! function_exists( 'relevanssi_do_query' ) ) {
			return $query_vars;
		}

		// Nothing to search: leave the query exactly as Bricks built it.
		if ( empty( $query_vars['s'] ) ) {
			return $query_vars;
		}

		$query_vars['relevanssi'] = true;

		return $query_vars;
	}
}
