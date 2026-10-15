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

	public static function register(): void {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter' ), 20 );
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

		/*
		 * An explicit opt-out for internal queries that must see the whole
		 * catalog regardless of who is asking - a job run from cron has no
		 * user, resolves to retail context, and would otherwise have this
		 * filter hide the B2B-only products.
		 *
		 * Only ever set this on a query whose results are not rendered to a
		 * visitor.
		 */
		if ( $query->get( 'beltoft_bypass_audience' ) ) {
			return;
		}

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

		$tax_query = (array) $query->get( 'tax_query' );

		$tax_query[] = $restricted
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

		$query->set( 'tax_query', $tax_query );
	}

	/** See CatalogAudience::published_b2b_only_exist(). */
	private static function published_b2b_only_products_exist(): bool {
		return CatalogAudience::published_b2b_only_exist();
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
		if ( '' !== (string) $query->get( 'post_parent' ) ) {
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

		return (bool) array_intersect( array( 'product', 'product_variation' ), $post_type );
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
