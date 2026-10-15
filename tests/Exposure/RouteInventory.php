<?php

/**
 * The enumerated surface the anonymous exposure contract is tested against.
 *
 * §5 requires the contract to be tested against an enumerated inventory
 * rather than an unbounded "anywhere", and requires that a newly registered
 * route which is not listed here fails the build. Review this list whenever
 * a plugin is added.
 *
 * Every route in the Store API product namespace is listed, including the
 * taxonomy ones that carry no price today — being listed is what stops a
 * future change to them going unnoticed.
 */
class RouteInventory {

	/** Store API routes that return product data, and therefore prices. */
	public static function product_bearing(): array {
		return array(
			'/wc/store/v1/products',
			'/wc/store/v1/products/(?P<id>[\d]+)',
			'/wc/store/v1/products/(?P<slug>[\S]+)',
			'/wc/store/v1/products/collection-data',
		);
	}

	/**
	 * Store API routes in the product namespace that describe taxonomy
	 * rather than products. They carry no price today; they are listed so
	 * that a change which starts embedding product data in them fails the
	 * drift test rather than passing silently.
	 */
	public static function product_taxonomy(): array {
		return array(
			'/wc/store/v1/products/attributes',
			'/wc/store/v1/products/attributes/(?P<id>[\d]+)',
			'/wc/store/v1/products/attributes/(?P<attribute_id>[\d]+)/terms',
			'/wc/store/v1/products/categories',
			'/wc/store/v1/products/categories/(?P<id>[\d]+)',
			'/wc/store/v1/products/brands',
			'/wc/store/v1/products/brands/(?P<identifier>[\w-]+)',
			'/wc/store/v1/products/reviews',
			'/wc/store/v1/products/tags',
		);
	}

	/**
	 * WordPress's own routes for the product post type. No price, but a
	 * product's title and content: the single-item route is audience-guarded
	 * (StoreApiGuard), the listing goes through QueryFilter, and the
	 * revision and autosave routes need edit rights.
	 */
	public static function core_product(): array {
		return array(
			'/wp/v2/product',
			'/wp/v2/product/(?P<id>[\d]+)',
			'/wp/v2/product/(?P<parent>[\d]+)/revisions',
			'/wp/v2/product/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)',
			'/wp/v2/product/(?P<id>[\d]+)/autosaves',
			'/wp/v2/product/(?P<parent>[\d]+)/autosaves/(?P<id>[\d]+)',
		);
	}

	/** Everything in the product namespace, and WordPress's product routes. */
	public static function all_product_namespace(): array {
		return array_merge( self::product_bearing(), self::product_taxonomy(), self::core_product() );
	}

	/** Core WordPress routes reachable anonymously. */
	public static function core(): array {
		return array(
			'/wp/v2/posts',
			'/wp/v2/pages',
			'/wp/v2/search',
		);
	}

	/** Every route the leak assertions fetch. */
	public static function rest(): array {
		return array_merge( self::product_bearing(), self::core() );
	}

	/** Routes that must NOT be reachable without authentication at all. */
	public static function authenticated_only(): array {
		return array( '/wc/v3/products' );
	}
}
