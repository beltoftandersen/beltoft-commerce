<?php

namespace BeltoftCommerce\Frontend;


defined( 'ABSPATH' ) || exit;

/**
 * Product and cart facts for the trade list, as Bricks dynamic data tags.
 *
 * DELIVERED AS DYNAMIC DATA TAGS, not markup, so the figures are available to
 * anyone building in Bricks:
 *
 *   {beltoft_ean}        the EAN, as the cart shows it (Checkout\ItemCodes)
 *   {beltoft_cart_shipping} the cart's shipping cost, read from the cart
 *   {beltoft_cart_product_id} the product behind the current cart line
 *   {beltoft_rrp}        the retail price - the recommended resale price
 *   {beltoft_summary}    the product's short text; for a variation its own
 *                        description, else the parent's short description
 *   {beltoft_buy}        the product list's quantity stepper and add button
 *                        for this product (LoopQuantity::buy_control)
 *
 * RETAIL IS THE RECOMMENDED PRICE, and that is a deliberate reading: this store
 * sells the same SKUs to the public, so its own shelf price is the defensible
 * recommendation rather than an invented RRP field nobody maintains.
 */
class ResellerTags {

	private const TAGS = array( 'beltoft_rrp', 'beltoft_ean', 'beltoft_cart_shipping', 'beltoft_cart_product_id', 'beltoft_summary', 'beltoft_buy' );

	public static function register(): void {
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 10, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 10, 3 );
	}

	/**
	 * @param mixed  $tag  The tag being rendered.
	 * @param mixed  $post Post or object in context.
	 * @param string $context
	 * @return mixed
	 */
	public static function render_tag( $tag, $post = null, $context = 'text' ) {
		$name = is_string( $tag ) ? trim( $tag, '{}' ) : '';

		if ( ! in_array( $name, self::TAGS, true ) ) {
			return $tag;
		}

		return self::value( $name, self::post_id( $post ) );
	}

	/**
	 * @param string $content
	 * @param mixed  $post
	 * @param string $context
	 * @return string
	 */
	public static function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{beltoft_' ) ) {
			return $content;
		}

		$post_id = self::post_id( $post );

		foreach ( self::TAGS as $name ) {
			if ( false === strpos( $content, '{' . $name . '}' ) ) {
				continue;
			}

			$content = str_replace( '{' . $name . '}', self::value( $name, $post_id ), $content );
		}

		return $content;
	}

	private static function post_id( $post ): int {
		if ( $post instanceof \WP_Post ) {
			return (int) $post->ID;
		}

		if ( is_numeric( $post ) ) {
			return (int) $post;
		}

		return (int) get_the_ID();
	}

	/**
	 * Values already worked out for this request, keyed tag:product.
	 *
	 * Bricks resolves each tag wherever it appears, and a product row carries
	 * four of them. Every resolution rebuilt the product - and for a variable
	 * product walked its children rebuilding those - because wc_get_product()
	 * keeps nothing between calls. After the audience checks were memoised
	 * these tags were the largest remaining source of that work on the trade
	 * list. A price and a barcode cannot change while a page is
	 * being drawn.
	 *
	 * @var array<string,string>
	 */
	private static array $values = array();

	/** @var array<int,\WC_Product|null> */
	private static array $products = array();

	public static function flush(): void {
		self::$values   = array();
		self::$products = array();
	}

	/** wc_get_product() with a per-request memory. Read-only use only. */
	private static function product_for( int $id ) {
		if ( ! $id ) {
			return null;
		}

		if ( ! array_key_exists( $id, self::$products ) ) {
			$product               = wc_get_product( $id );
			self::$products[ $id ] = $product instanceof \WC_Product ? $product : null;
		}

		return self::$products[ $id ];
	}

	private static function value( string $name, int $post_id ): string {
		/*
		 * The cart tags below are deliberately outside the cache: they answer
		 * from the cart, which changes as the page renders its own cart loop.
		 */
		if ( 0 !== strpos( $name, 'beltoft_cart_' ) ) {
			$key = $name . ':' . $post_id;

			if ( isset( self::$values[ $key ] ) ) {
				return self::$values[ $key ];
			}

			return self::$values[ $key ] = self::compute_value( $name, $post_id );
		}

		return self::compute_value( $name, $post_id );
	}

	private static function compute_value( string $name, int $post_id ): string {
		/*
		 * A cart figure, so it is answered before anything asks for a product.
		 *
		 * Bricks' own {woo_cart_shipping_total_amount} resolves the chosen rate
		 * id against WC()->shipping()->get_packages(), and when that lookup
		 * comes back empty - as it does rendering the inline cart - it falls
		 * through to zero and prints "Grátis". Telling a buyer delivery is free
		 * while charging 5,70 for it is not a rounding difference. The cart's
		 * own total is already computed and cannot miss.
		 */
		if ( 'beltoft_cart_shipping' === $name ) {
			return self::cart_shipping();
		}

		/*
		 * WooCommerce's cart line knows its product; Bricks has no dynamic tag
		 * that exposes it (only the name, title and remove link), so a cart row
		 * cannot open the quick view for its own item without this.
		 */
		if ( 'beltoft_cart_product_id' === $name ) {
			return self::cart_product_id();
		}

		$product = $post_id ? self::product_for( $post_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		/*
		 * The code, not a price, so it is answered before the price work below
		 * and is not held back when there is no retail price.
		 *
		 * A variable parent carries no GTIN of its own - the barcode belongs to
		 * the thing in the box - so the parent falls back to its children's
		 * codes. One child: show it. Several: say how many rather than pick
		 * one arbitrarily, because a wrong barcode on an order form is worse
		 * than none.
		 */
		if ( 'beltoft_ean' === $name ) {
			return self::gtin( $product );
		}

		if ( 'beltoft_summary' === $name ) {
			return self::summary( $product );
		}

		if ( 'beltoft_buy' === $name ) {
			return LoopQuantity::buy_control( $product );
		}

		// beltoft_rrp.
		$retail = self::retail_price( $product );

		return $retail > 0 ? wc_price( $retail ) : '';
	}

	/**
	 * The retail price, asking the right object for it.
	 *
	 * A variable product's parent holds no price - `get_regular_price('edit')`
	 * returns an empty string and every tag silently rendered blank on exactly
	 * the products with the most variations. Same trap as
	 * `WC_Product_Variable::is_purchasable()`, which is documented in
	 * LoopQuantity and was walked into again here.
	 *
	 * The lowest child is used because that is the figure the list already
	 * shows beside it: a range reads "from X", so the recommended price has to
	 * be the X it is compared against, not an average or a maximum.
	 */
	private static function retail_price( \WC_Product $product ): float {
		if ( ! $product->is_type( 'variable' ) ) {
			return (float) $product->get_regular_price( 'edit' );
		}

		$lowest = 0.0;

		foreach ( $product->get_children() as $child_id ) {
			$child = self::product_for( (int) $child_id );

			if ( ! $child instanceof \WC_Product ) {
				continue;
			}

			$price = (float) $child->get_regular_price( 'edit' );

			if ( $price > 0 && ( 0.0 === $lowest || $price < $lowest ) ) {
				$lowest = $price;
			}
		}

		return $lowest;
	}

	/**
	 * The short text for a product, including a variation.
	 *
	 * The trade list and its quick view work on variations as their own rows,
	 * and a variation has no short description - WooCommerce keeps that on the
	 * parent. Bricks' Product short description element renders nothing at all
	 * for a variation (it only accepts `product` posts), so the quick view of
	 * every variable product came up without its text. A variation's own
	 * description, when someone wrote one, is more specific and wins.
	 */
	private static function summary( \WC_Product $product ): string {
		$text = '';

		if ( $product->is_type( 'variation' ) ) {
			$text = (string) $product->get_description();

			if ( '' === trim( $text ) ) {
				$parent = self::product_for( (int) $product->get_parent_id() );
				$text   = $parent ? (string) $parent->get_short_description() : '';
			}
		} else {
			$text = (string) $product->get_short_description();
		}

		if ( '' === trim( $text ) ) {
			return '';
		}

		// The same filter WooCommerce's own short-description template runs:
		// paragraphs, shortcodes, embeds.
		return (string) apply_filters( 'woocommerce_short_description', $text );
	}

	/** The product's EAN as the cart shows it (Checkout\ItemCodes::ean()). */
	private static function gtin( \WC_Product $product ): string {
		$own = \BeltoftCommerce\Checkout\ItemCodes::ean( $product );

		if ( '' !== $own ) {
			return $own;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return '';
		}

		$codes = array();

		foreach ( $product->get_children() as $child_id ) {
			$child = self::product_for( (int) $child_id );

			if ( ! $child ) {
				continue;
			}

			$code = \BeltoftCommerce\Checkout\ItemCodes::ean( $child, false );

			if ( '' !== $code ) {
				$codes[ $code ] = true;
			}
		}

		if ( ! $codes ) {
			return '';
		}

		if ( 1 === count( $codes ) ) {
			return (string) key( $codes );
		}

		/* translators: %d: number of distinct GTINs across a product's variations. */
		return sprintf( _n( '%d code', '%d codes', count( $codes ), 'beltoft-commerce' ), count( $codes ) );
	}

	private static function cart_shipping(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$net = (float) WC()->cart->get_shipping_total();

		if ( $net <= 0 ) {
			return esc_html__( 'Free', 'beltoft-commerce' );
		}

		// B2B displays ex-IVA and retail inc-IVA; follow whichever this session
		// is in, so the line agrees with the subtotal beside it.
		$show = WC()->cart->display_prices_including_tax()
			? $net + (float) WC()->cart->get_shipping_tax()
			: $net;

		return wc_price( $show );
	}

	/** The product id behind the cart line currently being looped. */
	private static function cart_product_id(): string {
		if ( ! class_exists( '\\Bricks\\Query' ) ) {
			return '';
		}

		$item = \Bricks\Query::get_loop_object();

		if ( ! is_array( $item ) ) {
			return '';
		}

		// The variation when there is one: that is the thing in the cart, and
		// the list beside it lists variations as their own rows too.
		$id = (int) ( $item['variation_id'] ?? 0 );

		if ( ! $id ) {
			$id = (int) ( $item['product_id'] ?? 0 );
		}

		return $id ? (string) $id : '';
	}
}
