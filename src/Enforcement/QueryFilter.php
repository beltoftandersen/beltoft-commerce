<?php

namespace BeltoftCommerce\Enforcement;

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Catalog\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Restricts product queries to the audience the current session may see.
 *
 * Hooked at pre_get_posts with a post-type test, NOT at
 * woocommerce_product_query_tax_query. That WooCommerce filter applies only
 * to the main shop and archive query; a Bricks query loop is its own
 * WP_Query and would bypass it entirely, showing retail-only products in
 * any builder-driven grid, slider or related-products block to a B2B
 * customer. Architecture §2.11.
 *
 * Guest and retail sessions still get a clause — retail must not see
 * B2B-only products either — but the clause is only added when there is
 * something to hide, so the common guest path stays cheap.
 */
class QueryFilter {

	/** Depth of without_audience() calls in progress. */
	private static int $bypass = 0;

	public static function register(): void {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter' ), 20 );
		add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'clean_bricks_query_vars' ), PHP_INT_MAX );
	}

	/**
	 * Run $callback with the audience filter off, for internal queries that
	 * must see the whole catalogue whoever is asking - a job run from cron has
	 * no user and reads as retail. Never for a query whose results reach a
	 * visitor.
	 *
	 * A flag in code, not a query var: it used to be
	 * 'beltoft_bypass_audience' => true, which Bricks copied from the
	 * browser's originalQueryVars into the trade list's query - one request
	 * field switched the catalogue rules off.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function without_audience( callable $callback ) {
		self::$bypass++;

		try {
			return $callback();
		} finally {
			self::$bypass--;
		}
	}

	/**
	 * Bricks builds a loop's query from client-supplied query vars
	 * (originalQueryVars, queryVars on load more). Those may not ask for
	 * suppressed filters, which the id exclusion below reads as an internal
	 * lookup and leaves alone.
	 *
	 * @param mixed $vars
	 * @return mixed
	 */
	public static function clean_bricks_query_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}

		unset( $vars['suppress_filters'], $vars['beltoft_bypass_audience'], $vars['perm'], $vars['post_password'], $vars['has_password'] );

		/*
		 * Published posts only, for anyone who may not read private ones.
		 * Bricks lets the browser's query vars override its own
		 * post_status = publish, and WP_Query returns private and draft posts
		 * for an explicit post_status without asking who is reading: an
		 * anonymous visitor listed the private B2B-only products, with
		 * prices, by sending post_status = private.
		 */
		if ( ! current_user_can( 'read_private_products' ) ) {
			$vars['post_status'] = 'publish';
		}

		return $vars;
	}

	public static function filter( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		// The admin must see the whole catalog to manage it, and so must staff
		// working through admin AJAX or the REST API (CatalogAudience::serves_staff()).
		if ( ( is_admin() && ! wp_doing_ajax() ) || CatalogAudience::serves_staff() ) {
			return;
		}

		if ( ! self::is_product_query( $query ) ) {
			return;
		}

		// Internal queries that must see everything: without_audience().
		if ( self::$bypass > 0 ) {
			return;
		}

		self::only_variations_of_public_products( $query );

		$restricted = CatalogAudience::restricts_current_request();

		// §2.7 requires guest and retail queries to be untouched so the
		// cached guest bucket is unchanged. A retail session only needs a
		// clause when there is something to hide, so when no B2B-only
		// product exists the guest query is left exactly as it was rather
		// than carrying a join that excludes nothing.
		if ( ! $restricted && ! self::published_b2b_only_products_exist() ) {
			return;
		}

		/*
		 * Variations, and product queries mixed with other post types, carry
		 * no audience term of their own: a tax_query would let every variation
		 * of a B2B-only product through to retail (the Store API's
		 * ?type=variation and ?sku= lookups), and on a trade session drop
		 * every variation and every non-product result. Those are filtered by
		 * id instead - products outside the audience and their variations -
		 * which also holds for get_posts(), whose suppress_filters would skip
		 * a posts_where clause.
		 */
		if ( ! self::is_products_only( $query ) ) {
			self::exclude_by_id( $query, $restricted );

			return;
		}

		/*
		 * Nested, never appended: a tax_query with relation OR (core REST's
		 * ?tax_relation=OR, a Bricks loop set to OR) turned an appended
		 * audience clause into one alternative among others.
		 */
		$existing = array_filter( (array) $query->get( 'tax_query' ), static fn( $clause, $key ) => 'relation' === $key || is_array( $clause ), ARRAY_FILTER_USE_BOTH );

		$audience = $restricted
			// A B2B session sees only b2b and both. Expressed as IN rather
			// than NOT IN because untagged products are retail-only and
			// must be excluded.
			? array(
				'taxonomy' => Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => Taxonomy::b2b_visible_slugs(),
				'operator' => 'IN',
			)
			: array(
				'taxonomy' => Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => Taxonomy::retail_hidden_slugs(),
				'operator' => 'NOT IN',
			);

		$clauses = array_diff_key( $existing, array( 'relation' => true ) );

		$query->set( 'tax_query', $clauses ? array( 'relation' => 'AND', $existing, $audience ) : array( $audience ) );
	}

	/** See CatalogAudience::published_b2b_only_exist(). */
	private static function published_b2b_only_products_exist(): bool {
		return CatalogAudience::published_b2b_only_exist();
	}

	/**
	 * A variation listed on its own is shown only if its product is public.
	 *
	 * WooCommerce keeps a variation 'publish' when its product is private or a
	 * draft, so forcing post_status = publish still listed them - names, prices
	 * and stock of products nobody may see - through a Bricks loop or the
	 * Store API asking for product_variation. For visitors who may not read
	 * private products, on listing queries (internal get_posts() lookups and a
	 * product's own children are left alone, as in exclude_by_id()).
	 */
	private static function only_variations_of_public_products( \WP_Query $query ): void {
		if ( current_user_can( 'read_private_products' ) || $query->get( 'suppress_filters' ) || '' !== (string) $query->get( 'post_parent' ) ) {
			return;
		}

		if ( ! in_array( 'product_variation', (array) $query->get( 'post_type' ), true ) ) {
			return;
		}

		$hidden = CatalogAudience::non_public_product_ids();

		if ( ! $hidden ) {
			return;
		}

		$in = array_map( 'intval', (array) $query->get( 'post_parent__in' ) );

		// post_parent__in makes WordPress ignore post_parent__not_in.
		if ( $in ) {
			$kept = array_values( array_diff( $in, $hidden ) );
			$query->set( 'post_parent__in', $kept ? $kept : array( 0 ) );

			return;
		}

		$query->set( 'post_parent__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post_parent__not_in' ) ), $hidden ) ) ) );
	}

	/**
	 * Leave out the products this session may not see, and their variations,
	 * by id.
	 *
	 * The ids cover the variations as well as the products, and are applied
	 * to whichever id narrowing the query already has: WordPress ignores
	 * post__not_in when p or post__in is set, and post_parent__not_in when
	 * post_parent__in is, so the Store API's include=, on_sale= and parent=
	 * used to walk straight past an exclusion list.
	 *
	 * Not for:
	 * - a lookup of one product's own variations (post_parent): WooCommerce
	 *   reads a product's children that way and caches the answer in a
	 *   transient every session shares, so a filtered answer would follow the
	 *   product to everyone. Whether that product may be seen is decided on
	 *   the product itself;
	 * - a variation-only get_posts() (suppress_filters): that is a plugin's
	 *   own lookup (subscriptions linked to a product, stock purges), not a
	 *   listing, and those were never filtered. Mixed product + variation
	 *   lookups are, as their product half always was.
	 */
	private static function exclude_by_id( \WP_Query $query, bool $trade ): void {
		// Internal lookups only (suppress_filters): Bricks strips that flag
		// from client query vars (clean_bricks_query_vars()).
		if ( '' !== (string) $query->get( 'post_parent' ) && $query->get( 'suppress_filters' ) ) {
			return;
		}

		$types = array_values( array_unique( (array) $query->get( 'post_type' ) ) );

		if ( array( 'product_variation' ) === $types && $query->get( 'suppress_filters' ) ) {
			return;
		}

		$hidden = CatalogAudience::hidden_ids( $trade );

		if ( ! $hidden ) {
			return;
		}

		$p = (int) $query->get( 'p' );

		if ( $p ) {
			if ( in_array( $p, $hidden, true ) ) {
				$query->set( 'p', 0 );
				$query->set( 'post__in', array( 0 ) );
			}

			return;
		}

		$in = array_map( 'intval', (array) $query->get( 'post__in' ) );

		if ( $in ) {
			$kept = array_values( array_diff( $in, $hidden ) );
			$query->set( 'post__in', $kept ? $kept : array( 0 ) );

			return;
		}

		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
	}

	private static function is_product_query( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return self::is_product_taxonomy_query( $query );
		}

		$post_type = is_array( $post_type ) ? $post_type : array( $post_type );

		// 'any' includes products: a client-supplied post_type => any must
		// not step around the filter.
		return (bool) array_intersect( array( 'product', 'product_variation', 'any' ), $post_type );
	}

	/** Whether every row the query can return is a product (not a variation, not another type). */
	private static function is_products_only( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return true; // A product-only taxonomy archive, see is_product_query().
		}

		return array( 'product' ) === array_values( array_unique( (array) $post_type ) );
	}

	/**
	 * A category, tag, brand or attribute archive.
	 *
	 * These reach pre_get_posts with post_type still empty - WordPress only
	 * fills it in inside get_posts() - so the post-type test alone let every
	 * product archive through unfiltered: retail-only products on a B2B
	 * customer's category pages, and B2B-only ones on retail's.
	 *
	 * Only taxonomies registered for products and nothing else count. Every
	 * result of such a query is a product, so the audience clause is safe;
	 * on a taxonomy shared with posts or pages it would hide all of those,
	 * because they carry no audience term. Search with no post_type is not
	 * caught here for the same reason - it mixes post types - and goes
	 * through SearchIndex instead.
	 */
	private static function is_product_taxonomy_query( \WP_Query $query ): bool {
		$product_only = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( array( 'product' ) === array_values( (array) $taxonomy->object_type ) ) {
				$product_only[ $taxonomy->name ] = $taxonomy->query_var;
			}
		}

		foreach ( $product_only as $name => $query_var ) {
			if ( $query_var && '' !== (string) $query->get( $query_var ) ) {
				return true;
			}
		}

		if ( isset( $product_only[ (string) $query->get( 'taxonomy' ) ] ) ) {
			return true;
		}

		foreach ( (array) $query->get( 'tax_query' ) as $clause ) {
			if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && isset( $product_only[ $clause['taxonomy'] ] ) ) {
				return true;
			}
		}

		return false;
	}
}
