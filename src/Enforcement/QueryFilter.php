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

		// The admin must see the whole catalog to manage it.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::is_product_query( $query ) ) {
			return;
		}

		/*
		 * An explicit opt-out for internal queries that must see the whole
		 * catalog regardless of who is asking — the missing-price scan runs
		 * from cron with no user, which resolves to retail context, and
		 * would otherwise have this filter hide exactly the B2B-only
		 * products it exists to find.
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

	/**
	 * Whether any B2B-only product is PUBLISHED.
	 *
	 * The retail clause exists to hide B2B-only products from retail
	 * listings. A B2B-only product that is `private` is already absent from
	 * every published listing, so the clause would hide nothing — while
	 * still adding a taxonomy join that changes result ordering for
	 * products with equal sort values.
	 *
	 * That is not hypothetical: with nine private B2B-only products and
	 * none published, the join silently reordered two retail category
	 * pages. §2.7 requires retail queries to be untouched, so the clause is
	 * added only when it actually has something to exclude. It starts
	 * applying by itself the moment a B2B-only product is published.
	 *
	 * Deliberately not memoised in a static: publish status changes within
	 * a request, and a stale answer either reorders retail pages or leaks a
	 * B2B-only product into them.
	 */
	private static function published_b2b_only_products_exist(): bool {
		$ids = get_posts( array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'beltoft_bypass_audience' => true,
			'tax_query'              => array(
				array(
					'taxonomy' => Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array( Taxonomy::B2B ),
				),
			),
		) );

		return ! empty( $ids );
	}

	private static function is_product_query( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return self::is_product_taxonomy_query( $query );
		}

		$post_type = is_array( $post_type ) ? $post_type : array( $post_type );

		return in_array( 'product', $post_type, true );
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
