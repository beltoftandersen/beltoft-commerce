<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of pricing group slugs.
 *
 * Nothing in production code outside this class may contain a group slug
 * literal. Field names, admin rows, resolver behaviour, lookup rows and the
 * exposure tests are all derived from what this returns, which is what makes
 * adding a group config rather than code. See architecture §2.4.
 */
class Registry {

	/** The till's group. Named because behaviour keys on it by name. */
	public const POS = 'pos';

	public static function retail_slug(): string {
		return 'retail';
	}

	/**
	 * @return array<string, array{label: string, roles: string[]}>
	 */
	public static function groups(): array {
		return (array) apply_filters( 'pricing_groups', array(
			'b2b_standard' => array(
				'label'  => __( 'B2B', 'beltoft-commerce' ),
				'roles'  => array( 'b2b_customer' ),
				// Own price meta, the shop's own stock, trade terms.
				'prices' => 'own',
				'stock'  => 'shared',
				'trade'  => true,
			),
			/*
			 * The till. Retail prices and retail terms, its own stock.
			 *
			 * A group rather than a bare role because only a group can own a
			 * stock pool - and its own stock is the whole point: what is on the
			 * shelf is not what is in the warehouse, and neither may decrement
			 * the other.
			 *
			 * It inherits prices instead of carrying its own, because the price
			 * at the till IS the PVP - WooCommerce's own regular price. Giving
			 * it group price meta would mean maintaining a second copy of every
			 * price for no gain, and the fail-closed rule would make any product
			 * anyone forgot silently unsellable.
			 */
			'pos'          => array(
				'label'  => __( 'POS', 'beltoft-commerce' ),
				'roles'  => array( 'pos_operator' ),
				'prices'   => 'inherit',
				'stock'    => 'own',
				/*
				 * Audience is retail's. The till sells the shop's catalogue:
				 * untagged and "Retail and B2B" products, but not the B2B-only
				 * ones - those exist for trade (mostruarios and the like) and
				 * are not counter stock.
				 */
				'trade'    => false,
			),
		) );
	}

	/**
	 * Whether a group is sold on trade terms.
	 *
	 * NOT the same question as "is this group retail?", though it was while
	 * B2B was the only group. Net prices, trade shipping, reseller tags and
	 * the trade catalogue all hang off this, and the till wants none of them
	 * while still not being the retail group.
	 */
	public static function is_trade( string $slug ): bool {
		if ( self::retail_slug() === $slug ) {
			return false;
		}

		$group = self::groups()[ $slug ] ?? array();

		// Default true: a registered group that says nothing is a trade group,
		// which is what every group before this one was.
		return (bool) ( $group['trade'] ?? true );
	}

	/**
	 * Whether a group is priced from WooCommerce's own fields rather than from
	 * group price meta.
	 */
	public static function inherits_prices( string $slug ): bool {
		if ( self::retail_slug() === $slug ) {
			return true;
		}

		return 'inherit' === ( self::groups()[ $slug ]['prices'] ?? 'own' );
	}

	/**
	 * Whether a group keeps a stock pool of its own.
	 *
	 * Groups that do not - retail and B2B - share WooCommerce's `_stock`, so
	 * a trade order and a web order draw down the same shelf, which is what
	 * they should do.
	 */
	public static function has_own_stock( string $slug ): bool {
		return 'own' === ( self::groups()[ $slug ]['stock'] ?? 'shared' );
	}

	/** The stock meta key for a group that keeps its own pool. */
	public static function stock_key( string $slug ): string {
		return '_stock_group_' . $slug;
	}

	public static function has( string $slug ): bool {
		return array_key_exists( $slug, self::groups() );
	}

	public static function regular_key( string $slug ): string {
		return '_price_group_' . $slug;
	}

	public static function sale_key( string $slug ): string {
		return '_sale_price_group_' . $slug;
	}

	public static function sale_from_key( string $slug ): string {
		return '_sale_price_dates_from_group_' . $slug;
	}

	public static function sale_to_key( string $slug ): string {
		return '_sale_price_dates_to_group_' . $slug;
	}

	/**
	 * Every meta key this plugin owns, across every registered group.
	 * Used by the cache-invalidation hook and the exposure tests so that a
	 * newly registered group is covered without touching either.
	 *
	 * @return string[]
	 */
	public static function all_price_keys(): array {
		$keys = array();

		foreach ( array_keys( self::groups() ) as $slug ) {
			// A group priced from WooCommerce's own fields owns no price meta.
			if ( self::inherits_prices( $slug ) ) {
				continue;
			}

			$keys[] = self::regular_key( $slug );
			$keys[] = self::sale_key( $slug );
			$keys[] = self::sale_from_key( $slug );
			$keys[] = self::sale_to_key( $slug );
		}

		return $keys;
	}
}
