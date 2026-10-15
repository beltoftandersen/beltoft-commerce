<?php

namespace BeltoftCommerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Which audience a product belongs to.
 *
 * The architecture document originally settled on a single `b2b-visible`
 * boolean, on the premise that B2B is a strict subset of retail with no
 * B2B-only SKUs. The catalog inventory disproved that premise: three
 * published B2B products do not exist in the retail catalog at all (two of
 * them mostruários — display stands, inherently trade-only). §2.7 states
 * that once such a SKU exists the boolean is no longer sufficient and this
 * becomes an audience taxonomy, so it is built that way from the start —
 * retrofitting would mean re-tagging 243 products.
 *
 * ABSENCE OF A TERM MEANS RETAIL ONLY. B2B is the restricted audience, so
 * tagging all 481 retail products to say "retail" would be noise. Only
 * products that B2B can see need a term.
 *
 * A taxonomy is used rather than post meta because term queries use the
 * relationship index; a meta_query on every archive would be a measurable
 * cost on large category pages.
 */
class Taxonomy {

	public const TAXONOMY = 'product_audience';

	public const RETAIL = 'retail';
	public const B2B    = 'b2b';
	public const BOTH   = 'both';

	/**
	 * The terms a product can actually carry.
	 *
	 * RETAIL is deliberately not among them. "Retail only" is what a product
	 * with NO term already means - §2.7, and the reason 481 retail products
	 * were never tagged in the first place - so offering it as a term gives the
	 * same fact two spellings and invites half the catalogue to be tagged with
	 * the default. It survives as a return value from audience_of(), which is
	 * where it belongs: an answer, not a choice.
	 */
	public static function audiences(): array {
		return array( self::B2B, self::BOTH );
	}

	public static function register(): void {
		register_taxonomy( self::TAXONOMY, array( 'product' ), array(
			'label'             => __( 'Audience', 'beltoft-commerce' ),
			'labels'            => array(
				'name'                       => __( 'Audience', 'beltoft-commerce' ),
				'singular_name'              => __( 'Audience', 'beltoft-commerce' ),
				'all_items'                  => __( 'All audiences', 'beltoft-commerce' ),
				'search_items'               => __( 'Search audiences', 'beltoft-commerce' ),
				'not_found'                  => __( 'No audiences found', 'beltoft-commerce' ),
				'add_new_item'               => __( 'Add audience', 'beltoft-commerce' ),
				'edit_item'                  => __( 'Edit audience', 'beltoft-commerce' ),
				'update_item'                => __( 'Update audience', 'beltoft-commerce' ),
				'parent_item'                => __( 'Parent audience', 'beltoft-commerce' ),
				'parent_item_colon'          => __( 'Parent audience:', 'beltoft-commerce' ),
				/*
				 * The empty cell in the products list, and the reason this
				 * labels array is worth having at all.
				 *
				 * WordPress falls back to the generic tag wording when a
				 * taxonomy does not supply this, so every untagged product read
				 * "No tags" in a column headed Audience - which says nothing
				 * about what the product actually does.
				 *
				 * An untagged product is not undecided: retail_hidden_slugs()
				 * hides only B2B-only products from retail, and
				 * b2b_visible_slugs() admits only B2B and BOTH - so no term at
				 * all behaves exactly like "Retail only". Saying so in the
				 * column is what stops someone adding a product, seeing a blank
				 * audience, and assuming trade can order it.
				 */
				'no_terms'                   => __( 'Retail only (default)', 'beltoft-commerce' ),
			),
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			/*
			 * TRUE so the editor draws a checkbox list, not a tag box.
			 *
			 * This is a three-value choice, and a flat taxonomy gives it a
			 * free-text field: anyone can type "B2B" or "b2b only" and create a
			 * term that is not one of audiences(). audience_of() then finds a
			 * slug it does not recognise and falls back to RETAIL, so the
			 * product quietly stops being orderable by trade while the editor
			 * shows an audience that looks deliberate. A checkbox list cannot
			 * express that mistake.
			 *
			 * Nothing else changes: terms are read and written by slug
			 * throughout, so the hierarchy this enables simply goes unused.
			 */
			'hierarchical'      => true,
			'rewrite'           => false,
			// The term is information about the B2B catalog. Never expose
			// it to anonymous REST callers.
			'show_in_rest'      => false,
		) );

		self::ensure_terms();

		add_action( 'admin_head-edit.php', array( __CLASS__, 'admin_column_width' ) );
	}

	/**
	 * Give the Audience column a width of its own on the products list.
	 *
	 * WordPress gives a taxonomy column no width at all and lets the table
	 * share out what is left. On this install there is nothing left: SEO,
	 * brands, wishlist and the rest bring the product list to seventeen
	 * columns, and Audience was computing to 0px - not hidden, just squeezed
	 * to nothing, so its heading wrapped to one letter per line and spilled
	 * down over the rows beneath it.
	 *
	 * Narrow enough not to take the space back off Name, wide enough for
	 * "Retail and B2B" to sit on two lines rather than eight.
	 */
	public static function admin_column_width(): void {
		if ( 'product' !== ( get_current_screen()->post_type ?? '' ) ) {
			return;
		}

		echo '<style>.column-taxonomy-' . esc_attr( self::TAXONOMY ) . '{width:118px}</style>';
	}

	public static function ensure_terms(): void {
		$labels = array(
			self::B2B  => __( 'B2B only', 'beltoft-commerce' ),
			self::BOTH => __( 'Retail and B2B', 'beltoft-commerce' ),
		);

		foreach ( $labels as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * A product with no term is retail-only.
	 */
	public static function audience_of( int $product_id ): string {
		/*
		 * A variation inherits its parent's audience.
		 *
		 * The taxonomy is registered on `product` only, so a variation never
		 * carries a term and the fallback below would call every one of them
		 * retail-only. That is not a cosmetic default: WooCommerce's AJAX
		 * add-to-cart passes the VARIATION id to
		 * `woocommerce_add_to_cart_validation` (class-wc-ajax.php:520), so
		 * without this a B2B customer is refused when adding any variable
		 * product - the refusal arriving as Woo's generic "go to the product
		 * page" response, with nothing naming the audience as the reason.
		 *
		 * Resolving to the parent is also the only reading consistent with the
		 * model: audience is a property of the product, and a variation is a
		 * price and a set of attributes belonging to one.
		 */
		$parent = wp_get_post_parent_id( $product_id );

		if ( $parent && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = (int) $parent;
		}

		/*
		 * get_the_terms(), not wp_get_object_terms(): it reads the term
		 * relationship cache, so filtering a list of search hits or related
		 * products costs no query per product. Setting a term clears it.
		 */
		$terms = get_the_terms( $product_id, self::TAXONOMY );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return self::RETAIL;
		}

		$slug = (string) reset( $terms )->slug;

		return in_array( $slug, self::audiences(), true ) ? $slug : self::RETAIL;
	}

	/**
	 * Replaces any existing audience rather than appending — a product has
	 * exactly one audience.
	 */
	public static function set_audience( int $product_id, string $audience ): bool {
		/*
		 * Setting RETAIL means removing the term, because that is what retail
		 * -only IS. Returning false instead would leave callers no way to put a
		 * product back to the default.
		 */
		if ( self::RETAIL === $audience ) {
			$cleared = wp_set_object_terms( $product_id, array(), self::TAXONOMY, false );

			return ! is_wp_error( $cleared );
		}

		if ( ! in_array( $audience, self::audiences(), true ) ) {
			return false;
		}

		$result = wp_set_object_terms( $product_id, $audience, self::TAXONOMY, false );

		return ! is_wp_error( $result );
	}

	/**
	 * The audiences a B2B session may see.
	 */
	public static function b2b_visible_slugs(): array {
		return array( self::B2B, self::BOTH );
	}

	/**
	 * The audiences a retail session may see. Retail never sees B2B-only
	 * products, and untagged products are retail by default.
	 */
	public static function retail_hidden_slugs(): array {
		return array( self::B2B );
	}
}
