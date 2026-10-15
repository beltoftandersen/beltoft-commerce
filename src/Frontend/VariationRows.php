<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Each orderable variation is its own line on the B2B list.
 *
 * A wholesale buyer orders "four Original and two Humilde", not "an Amaciador
 * Sólido, and then a choice". A parent row with a picker underneath makes them
 * operate a control once per variation while reading a single price range that
 * describes none of the things they are buying.
 *
 * WHY THE RESULTS ARE EXPANDED AND NOT THE QUERY. The obvious move is to add
 * `product_variation` to the loop's post types. It breaks three things at once:
 * variations carry no `product_audience` term, so Enforcement\QueryFilter's
 * tax_query would drop every one of them; they carry no `product_cat` either,
 * so the category filter would empty the list; and Relevanssi indexes the
 * parent, so search would stop matching. All three are properties of the
 * PARENT, which is exactly the thing the query should keep selecting.
 *
 * So the query still selects products - audience, category, search and
 * pagination all behave as before - and this swaps each variable parent for its
 * purchasable variations in the result set afterwards, on `bricks/query/result`
 * (query.php:991). The loop then renders one row per variation using the same
 * elements, because a variation is a WP_Post like any other.
 *
 * CONSEQUENCE, ACCEPTED: a page of 24 products may render more than 24 rows.
 * Pagination counts parents, which is what keeps the page count stable and the
 * filter counts honest.
 */
class VariationRows {

	/** Runs late: other result filters should see the query's own selection. */
	private const PRIORITY = 50;

	public static function register(): void {
		add_filter( 'bricks/query/result', array( __CLASS__, 'expand' ), self::PRIORITY, 2 );

		/*
		 * NOTHING IS HOOKED TO 'bricks/query/result_count', and it is not an
		 * oversight. Correcting that count would make the results summary
		 * report rows rather than parent products, but the filter runs WHILE
		 * the query is being counted and any row total has to come from
		 * Query_Filters' object ids, which are not recorded until the query has
		 * finished. It answered 0 every time. The summary counts parents, which
		 * is wrong in the same way the filter counts were and is left alone
		 * deliberately - Bricks' number beats no number.
		 */
		add_filter( 'the_title', array( __CLASS__, 'variation_title' ), 10, 2 );
	}

	/**
	 * Whether this request is one where a product becomes several rows.
	 *
	 * PUBLIC, because anything that counts rows has to apply in exactly the
	 * places rows are expanded - no more and no less. Frontend\FilterCounts
	 * asks this rather than deciding for itself with an is_page() check, which
	 * is false during the filter's REST re-render: a page-scoped answer left
	 * the counts behaving differently on the first paint and after the first
	 * click, while the rows around them stayed expanded either way.
	 *
	 * Host only, deliberately. The expansion is not page-scoped, so neither is
	 * counting it.
	 */
	public static function expands_rows(): bool {
		return Hosts::is_b2b_host();
	}

	private static function applies(): bool {
		return self::expands_rows();
	}

	/**
	 * @param mixed $result The query result Bricks is about to loop.
	 * @return mixed
	 */
	public static function expand( $result, $query = null ) {
		if ( ! self::applies() || ! $result instanceof \WP_Query || empty( $result->posts ) ) {
			return $result;
		}

		if ( ! $query || 'post' !== ( $query->object_type ?? '' ) ) {
			return $result;
		}

		/*
		 * Relevanssi indexes a variation's SKU and GTIN against its PARENT
		 * (see Frontend\VariationCodes - that is what makes them findable at
		 * all). So searching one variation's code matches the parent, and
		 * expanding it would answer a scan of one barcode with every sibling.
		 * When the term names a specific variation, only that one is shown.
		 */
		$term = trim( (string) $result->get( 's' ) );

		$expanded = array();
		$changed  = false;

		foreach ( $result->posts as $post ) {
			if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
				$expanded[] = $post;
				continue;
			}

			$product = self::product( (int) $post->ID );

			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				$expanded[] = $post;
				continue;
			}

			$rows = self::variation_posts( $product, $term );

			/*
			 * A variable product with nothing this buyer may order keeps its
			 * own row. Dropping it would make the product vanish from the list
			 * with no explanation; leaving it shows the line and lets the
			 * usual purchasability rules explain themselves.
			 */
			if ( ! $rows ) {
				$expanded[] = $post;
				continue;
			}

			foreach ( $rows as $row ) {
				$expanded[] = $row;
			}

			$changed = true;
		}


		if ( ! $changed ) {
			return $result;
		}

		$result->posts      = $expanded;
		$result->post_count = count( $expanded );

		/*
		 * Keep the reported total honest when it can be.
		 *
		 * Bricks prints "Resultados: 1 - 7 de 7" from found_posts, which counts
		 * POSTS, while the list shows ROWS - and a variable product is one post
		 * and several rows. A category of four simple products and three
		 * variable ones with five variations between them renders nine lines
		 * under a heading that says seven.
		 *
		 * Expansion happens a page at a time, so the expanded total across
		 * every page is not knowable here; working it out would mean running
		 * the whole query again unpaginated, on the page this plugin spends
		 * most of its effort keeping fast. When the results fit on one page
		 * there is nothing to extrapolate - what was expanded IS the total - so
		 * the count is corrected then and left alone otherwise.
		 *
		 * KNOWN LIMIT: past the first page the heading still counts products,
		 * so it can under-report. Wrong in the same direction as before rather
		 * than a number invented to look consistent.
		 */
		if ( isset( $result->max_num_pages ) && $result->max_num_pages <= 1 ) {
			$result->found_posts = count( $expanded );
		}

		return $result;
	}

	/**
	 * @return \WP_Post[]
	 */
	private static function variation_posts( \WC_Product $product, string $term = '' ): array {
		$out     = array();
		$matched = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = self::product( (int) $variation_id );

			// Same fail-closed rule as everywhere else: a variation priced for
			// retail and not for this buyer's group is not offered to them.
			if ( ! $variation instanceof \WC_Product_Variation || ! $variation->is_purchasable() ) {
				continue;
			}

			$post = get_post( $variation_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$out[] = $post;

			if ( '' !== $term && self::identifies( $variation, $term ) ) {
				$matched[] = $post;
			}
		}

		return $matched ?: $out;
	}

	/**
	 * Whether this search term IS this variation's code, rather than merely
	 * appearing somewhere in it. A buyer typing or scanning a full SKU or
	 * barcode means that exact line; a partial word is still a normal search
	 * and should keep showing the whole family.
	 */
	private static function identifies( \WC_Product_Variation $variation, string $term ): bool {
		$needle = strtolower( $term );

		foreach ( array( $variation->get_sku(), \BeltoftCommerce\Checkout\ItemCodes::ean( $variation, false ) ) as $code ) {
			$code = strtolower( trim( (string) $code ) );

			if ( '' !== $code && $code === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * "Parent - Attributes", because a variation's own post_title is either the
	 * parent's name verbatim or empty depending on how it was created, and
	 * neither tells a buyer which of four lines they are looking at.
	 *
	 * @param string $title
	 * @param int    $post_id
	 * @return string
	 */
	public static function variation_title( $title, $post_id = 0 ) {
		if ( ! self::applies() || ! $post_id || 'product_variation' !== get_post_type( $post_id ) ) {
			return $title;
		}

		/*
		 * Memoised, because `the_title` is not called once per row.
		 *
		 * Bricks asks for a title for the heading, again for the link it wraps
		 * it in, again for the image alt, and so on. Each call used to build a
		 * WC_Product_Variation and then its WC_Product_Variable parent from
		 * scratch - and wc_get_product() has no per-request memory, so every
		 * one of those re-read all of the product's data. Rendering 39 rows
		 * constructed 2,523 product objects, one parent 369 times over, for
		 * about 268,000 object-cache lookups and 1.1 seconds. A variation's
		 * title cannot change within a request, so it is worked out once.
		 */
		if ( isset( self::$titles[ $post_id ] ) ) {
			return self::$titles[ $post_id ];
		}

		$variation = self::product( $post_id );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $title;
		}

		$parent = self::product( $variation->get_parent_id() );

		if ( ! $parent ) {
			return $title;
		}

		$attributes = wp_strip_all_tags( (string) wc_get_formatted_variation( $variation, true, false ) );

		self::$titles[ $post_id ] = '' !== $attributes
			? $parent->get_name() . ' - ' . $attributes
			: $parent->get_name();

		return self::$titles[ $post_id ];
	}

	/** Computed titles, by variation id, for this request only. */
	private static array $titles = array();

	/** Product objects, by id, for this request only. */
	private static array $products = array();

	/**
	 * wc_get_product() with a memory.
	 *
	 * WooCommerce builds a new object on every call and re-reads its data, so
	 * asking for the same parent ninety times in one request costs ninety
	 * loads. The objects here are only read from.
	 *
	 * @return \WC_Product|null
	 */
	private static function product( int $id ) {
		if ( ! $id ) {
			return null;
		}

		if ( ! array_key_exists( $id, self::$products ) ) {
			$product = wc_get_product( $id );
			self::$products[ $id ] = $product instanceof \WC_Product ? $product : null;
		}

		return self::$products[ $id ];
	}
}
