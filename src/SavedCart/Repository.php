<?php

namespace BeltoftCommerce\SavedCart;

use BeltoftCommerce\Catalog\CatalogAudience;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Saving, listing and restoring a partner's baskets.
 */
class Repository {

	/** A partner cannot keep an unbounded number of these. */
	private const MAX_PER_USER = 50;

	/**
	 * Snapshot the cart as it stands.
	 *
	 * Only the ids and quantities are kept - never the price. A basket saved in
	 * March and restored in June must be priced in June, at whatever the
	 * partner's group pays then. Storing the price would quietly promise an old
	 * one, and the first anyone would know is a disputed invoice.
	 *
	 * @return int|\WP_Error The new id.
	 */
	public static function save( int $user_id, string $name ) {
		global $wpdb;

		if ( ! $user_id ) {
			return new \WP_Error( 'beltoft_saved_cart_auth', __( 'Inicie sessão para guardar a encomenda.', 'beltoft-commerce' ) );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return new \WP_Error( 'beltoft_saved_cart_empty', __( 'A encomenda está vazia.', 'beltoft-commerce' ) );
		}

		if ( self::count_for( $user_id ) >= self::MAX_PER_USER ) {
			return new \WP_Error(
				'beltoft_saved_cart_limit',
				sprintf(
					/* translators: %d: how many saved orders are allowed. */
					__( 'Só pode guardar %d encomendas. Elimine uma antes de guardar outra.', 'beltoft-commerce' ),
					self::MAX_PER_USER
				)
			);
		}

		$items = array();

		foreach ( WC()->cart->get_cart() as $line ) {
			$items[] = array(
				'product_id'   => (int) ( $line['product_id'] ?? 0 ),
				'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
				'quantity'     => (int) ( $line['quantity'] ?? 0 ),
			);
		}

		Table::maybe_install();

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			Table::name(),
			array(
				'user_id'    => $user_id,
				'name'       => self::clean_name( $name ),
				'items'      => wp_json_encode( $items ),
				'item_count' => count( $items ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : new \WP_Error( 'beltoft_saved_cart_failed', __( 'Não foi possível guardar.', 'beltoft-commerce' ) );
	}

	public static function all( int $user_id ): array {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		Table::maybe_install();
		$table = Table::name();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, item_count, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC",
				$user_id
			)
		);
	}

	public static function count_for( int $user_id ): int {
		global $wpdb;

		Table::maybe_install();
		$table = Table::name();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/** Always scoped to the owner: an id alone is never authority to read one. */
	public static function get( int $id, int $user_id ) {
		global $wpdb;

		Table::maybe_install();
		$table = Table::name();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ) );
	}

	public static function delete( int $id, int $user_id ): bool {
		global $wpdb;

		Table::maybe_install();

		return (bool) $wpdb->delete( Table::name(), array( 'id' => $id, 'user_id' => $user_id ), array( '%d', '%d' ) );
	}

	/**
	 * Put a saved basket back in the cart.
	 *
	 * Every line is re-checked against today's catalogue rather than trusted.
	 * A basket is a list of intentions from some weeks ago: products get
	 * discontinued, move out of the trade catalogue, or run out. Those lines are
	 * skipped and named in the result, because a partner who asked for forty
	 * lines and silently received thirty-seven has been given a wrong order.
	 *
	 * @param bool $replace Empty the cart first, rather than adding to it.
	 * @return array{added:int, skipped:array<string>}
	 */
	public static function restore( int $id, int $user_id, bool $replace = true ): array {
		$row = self::get( $id, $user_id );

		if ( ! $row ) {
			return array( 'added' => 0, 'skipped' => array() );
		}

		$items = json_decode( (string) $row->items, true );

		if ( ! is_array( $items ) ) {
			return array( 'added' => 0, 'skipped' => array() );
		}

		return self::restore_items( $items, $user_id, $replace );
	}

	/**
	 * Put a list of {product_id, variation_id, quantity} into the cart.
	 *
	 * Shared with Account\Reorder, which turns a past order into exactly this
	 * shape. Both are the same problem - a list of intentions from weeks ago,
	 * to be checked against what the shop can sell today - and one of them
	 * silently dropping lines while the other names them would be worse than
	 * either behaviour on its own.
	 *
	 * @param array $items
	 * @return array{added:int, skipped:array<string>}
	 */
	public static function restore_items( array $items, int $user_id, bool $replace = true ): array {
		if ( ! WC()->cart ) {
			return array( 'added' => 0, 'skipped' => array() );
		}

		/*
		 * Everything is checked before anything is emptied.
		 *
		 * This used to clear the basket first and then discover, line by line,
		 * that none of it could be added - a partner with a half-built order
		 * who opened a saved one full of discontinued products was left with
		 * nothing at all and a list of reasons. Now the basket is only replaced
		 * once there is something to replace it with.
		 */
		$plan    = array();
		$skipped = array();

		foreach ( $items as $item ) {
			$parent    = (int) ( $item['product_id'] ?? 0 );
			$variation = (int) ( $item['variation_id'] ?? 0 );
			$quantity  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$product   = wc_get_product( $variation ?: $parent );

			if ( ! $product instanceof WC_Product ) {
				$skipped[] = self::skipped_label( $parent, __( 'já não existe', 'beltoft-commerce' ) );
				continue;
			}

			if ( class_exists( CatalogAudience::class )
				&& ! CatalogAudience::can_purchase( $user_id, $parent ) ) {
				$skipped[] = self::skipped_label( $parent, __( 'já não está disponível', 'beltoft-commerce' ), $product );
				continue;
			}

			if ( ! $product->is_purchasable() ) {
				$skipped[] = self::skipped_label( $parent, __( 'já não está à venda', 'beltoft-commerce' ), $product );
				continue;
			}

			// Out of stock is not a reason to drop the line when the shop takes
			// backorders on it; it is when the shop does not.
			if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
				$skipped[] = self::skipped_label( $parent, __( 'sem stock', 'beltoft-commerce' ), $product );
				continue;
			}

			if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
				$quantity = min( $quantity, max( 0, (int) $product->get_stock_quantity() ) );
			}

			if ( $quantity < 1 ) {
				$skipped[] = self::skipped_label( $parent, __( 'sem stock', 'beltoft-commerce' ), $product );
				continue;
			}

			$plan[] = array(
				'parent'    => $parent,
				'variation' => $variation,
				'quantity'  => $quantity,
				'product'   => $product,
			);
		}

		if ( $replace && $plan ) {
			WC()->cart->empty_cart();
		}

		$added = 0;

		foreach ( $plan as $line ) {
			if ( WC()->cart->add_to_cart( $line['parent'], $line['quantity'], $line['variation'] ) ) {
				$added++;
			} else {
				$skipped[] = self::skipped_label(
					$line['parent'],
					__( 'não foi possível adicionar', 'beltoft-commerce' ),
					$line['product']
				);
			}
		}

		// WooCommerce collects its own notices while adding; they would surface
		// on the next page as duplicates of what is reported here.
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		return array( 'added' => $added, 'skipped' => $skipped );
	}

	private static function skipped_label( int $parent, string $why, ?WC_Product $product = null ): string {
		$name = $product ? $product->get_name() : get_the_title( $parent );

		return sprintf( '%s (%s)', wp_strip_all_tags( (string) $name ), $why );
	}

	public static function clean_name( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );

		if ( '' === $name ) {
			/* translators: %s: today's date. */
			$name = sprintf( __( 'Encomenda de %s', 'beltoft-commerce' ), date_i18n( get_option( 'date_format' ) ) );
		}

		return mb_substr( $name, 0, 120 );
	}
}
