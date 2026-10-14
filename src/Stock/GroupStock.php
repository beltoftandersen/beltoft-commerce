<?php

namespace BeltoftCommerce\Stock;

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Support\Session;
use WC_Order;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * A stock pool of its own for groups that keep one.
 *
 * Retail and trade share WooCommerce's `_stock`: a web order and a partner
 * order draw down the same shelf, which is right, because it is the same shelf.
 * The till is not that shelf. What is in the shop is not what is in the
 * warehouse and neither may decrement the other, so the POS group carries
 * `_stock_group_pos` and nothing else in WooCommerce knows about it.
 *
 * WHY THIS IS SO SMALL. Every stock read in this codebase - the loop quantity
 * caps, the scanner, saved carts, quick order, cart validation, the Store API -
 * goes through WC_Product's getters, and WC_Data::get_prop() runs every
 * property through `woocommerce_product_get_<prop>`
 * (abstract-wc-data.php:960). Swapping the property is therefore enough; not
 * one of those call sites needs to know this exists.
 *
 * WHY THE WRITE SIDE USES THE ORDER AND NOT THE SESSION. Stock is reduced after
 * checkout is over - on payment complete, on an admin status change, from cron.
 * There is no till session then. The group is recorded on the order when it is
 * created and read back from there; asking PricingContext at reduction time
 * would decrement whichever pool the person clicking "completed" happens to
 * belong to.
 *
 * WHY IT IS SAFE TO REDUCE INSIDE A `can_reduce` FILTER.
 * wc_maybe_reduce_stock_levels() sets the order's `_order_stock_reduced` flag
 * after calling through, whatever the filter returned
 * (wc-stock-functions.php:122), so WooCommerce's own guard stops this running
 * twice. The same holds for restoring.
 */
class GroupStock {

	public const ORDER_GROUP_META = '_beltoft_pricing_group';

	public static function register(): void {
		/*
		 * Record which group an order belongs to, while there is still a
		 * session - and at priority 1, because two other things on this hook
		 * need to have read it. WooCommerce registers wc_reserve_stock_for_order
		 * on the same action (wc-stock-functions.php:465) at the default
		 * priority, and registers it as it loads, which is before this plugin
		 * runs - so at priority 10 the reservation would look for a group that
		 * had not been written yet and treat a counter sale as a website one.
		 */
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'record_group' ), 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'record_group' ), 1 );

		/*
		 * A group with its own pool does not reserve from the shared one.
		 *
		 * WooCommerce holds stock for 30 minutes while an order is paid for, so
		 * the website cannot sell something out from under a customer at the
		 * payment page. A counter sale is paid before the order exists, so the
		 * hold protects nothing - and it is placed against `_stock`, the
		 * WAREHOUSE, which this order is never going to draw down. An abandoned
		 * till checkout would make a product look unavailable online for half
		 * an hour over stock it was not taking.
		 *
		 * Zero is WooCommerce's documented way to exclude an order
		 * (ReserveStock::reserve_stock_for_order).
		 */
		add_filter( 'woocommerce_order_hold_stock_minutes', array( __CLASS__, 'no_hold' ), 10, 2 );

		// Read side.
		foreach ( array( 'product', 'product_variation' ) as $type ) {
			add_filter( "woocommerce_{$type}_get_stock_quantity", array( __CLASS__, 'quantity' ), 10, 2 );
			add_filter( "woocommerce_{$type}_get_manage_stock", array( __CLASS__, 'manage' ), 10, 2 );
			add_filter( "woocommerce_{$type}_get_stock_status", array( __CLASS__, 'status' ), 10, 2 );
			add_filter( "woocommerce_{$type}_get_backorders", array( __CLASS__, 'backorders' ), 10, 2 );
		}

		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 10, 2 );

		// Write side.
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'divert_reduce' ), 10, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', array( __CLASS__, 'divert_restore' ), 10, 2 );
	}

	/** @param mixed $order */
	public static function record_group( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::ORDER_GROUP_META, PricingContext::current() );
		$order->save();
	}

	/**
	 * @param mixed $minutes
	 * @param mixed $order
	 * @return mixed
	 */
	public static function no_hold( $minutes, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			return $minutes;
		}

		return Registry::has_own_stock( Session::group_for_order( $order ) ) ? 0 : $minutes;
	}

	/* ------------------------------------------------------------ read side */

	private static function pool(): string {
		$group = PricingContext::current();

		return Registry::has_own_stock( $group ) ? $group : '';
	}

	/**
	 * Add to or subtract from a group's count, in the database, in one statement.
	 *
	 * NOT read-then-write. get_post_meta() is served from the object cache -
	 * Redis, on this host - so two sales of the same product processing at once
	 * could both read 5 and both write 4, and one of them would simply not
	 * happen. A till is exactly where that bites: one operator scanning quickly,
	 * or two tills on the same shelf, or a refund landing while a sale is being
	 * taken. Letting MySQL do the arithmetic makes the read and the write the
	 * same operation, so concurrent decrements queue instead of overwriting.
	 *
	 * The count is deliberately allowed to go negative rather than clamped at
	 * zero. A negative shelf means something was sold that the system did not
	 * think was there, and that is worth seeing; clamping would hide it and
	 * leave the count quietly wrong.
	 */
	private static function move( string $group, int $product_id, int $delta ): void {
		global $wpdb;

		if ( 0 === $delta ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = %s",
				$delta,
				$product_id,
				Registry::stock_key( $group )
			)
		);

		// The row changed underneath the cache, which would otherwise keep
		// serving the old number for the rest of the request and beyond.
		wp_cache_delete( $product_id, 'post_meta' );
	}

	/**
	 * The group's count for a product, or null when it has never been counted.
	 */
	private static function counted( string $group, int $product_id ): ?int {
		$raw = get_post_meta( $product_id, Registry::stock_key( $group ), true );

		return '' === $raw || null === $raw ? null : (int) $raw;
	}

	/** @param mixed $value @param mixed $product */
	public static function quantity( $value, $product ) {
		$group = self::pool();

		if ( '' === $group || ! $product instanceof WC_Product ) {
			return $value;
		}

		return self::counted( $group, $product->get_id() ) ?? 0;
	}

	/** @param mixed $value @param mixed $product */
	public static function manage( $value, $product ) {
		$group = self::pool();

		if ( '' === $group || ! $product instanceof WC_Product ) {
			return $value;
		}

		/*
		 * Always managed - which now means always COUNTED, not always gated.
		 *
		 * This is what keeps the shelf moving on every sale. An uncounted
		 * product still sells (see backorders below) and still decrements, so
		 * it ends the day at -3 rather than at nothing, which is a better
		 * record of what went out than silence.
		 */
		return true;
	}

	/**
	 * THE COUNT IS A RECORD, NOT A GATE. The three filters below are what make
	 * that true, and they used to say the opposite.
	 *
	 * The first version refused any sale the shelf could not account for, on
	 * the reasoning that nothing should leave the shop that the shop does not
	 * know it has. That reasoning belongs to a warehouse. This is a market
	 * stand: the customer has already picked the thing up and carried it to the
	 * counter, so its existence is not in question and the number is the only
	 * thing that might be wrong. Blocking meant refusing a sale for goods in
	 * the buyer's hand, in front of a queue, until somebody went and edited an
	 * Inventory field - and the count drifts constantly, because nobody
	 * recounts a stand mid-market.
	 *
	 * So the till never blocks. The count still moves on every sale, which is
	 * the point: a shelf that goes below zero is not a failure, it is the
	 * system telling you precisely how far that count had drifted. Reconcile
	 * after the market, not in front of a customer - Stock\Recount puts the
	 * negatives where they can be found.
	 *
	 * Backorders are WooCommerce's own name for exactly this - sell it, keep
	 * counting - so saying yes here is what stops every stock gate downstream
	 * (add to cart, check_cart_items, the checkout) without any of them having
	 * to be switched off individually.
	 *
	 * @param mixed $value
	 * @param mixed $product
	 */
	public static function backorders( $value, $product ) {
		return '' === self::pool() ? $value : 'yes';
	}

	/** @param mixed $value @param mixed $product */
	public static function status( $value, $product ) {
		$group = self::pool();

		if ( '' === $group || ! $product instanceof WC_Product ) {
			return $value;
		}

		/*
		 * Never 'onbackorder', though that is technically what a negative shelf
		 * is. It reads as "available to order" wherever WooCommerce prints it,
		 * which at a counter would be a promise to supply later that nobody is
		 * making. The goods are on the table.
		 */
		return 'instock';
	}

	/** @param mixed $in_stock @param mixed $product */
	public static function in_stock( $in_stock, $product ) {
		return '' === self::pool() ? $in_stock : true;
	}

	/* ----------------------------------------------------------- write side */

	/** @param mixed $can @param mixed $order */
	public static function divert_reduce( $can, $order = null ) {
		return self::divert( $can, $order, -1 );
	}

	/** @param mixed $can @param mixed $order */
	public static function divert_restore( $can, $order = null ) {
		return self::divert( $can, $order, 1 );
	}

	/**
	 * Move the order's lines against the group's own pool, and keep
	 * WooCommerce away from `_stock`.
	 *
	 * @param mixed $can
	 * @param mixed $order
	 * @param int   $sign -1 to reduce, 1 to restore.
	 * @return mixed
	 */
	private static function divert( $can, $order, int $sign ) {
		if ( ! $order instanceof WC_Order ) {
			return $can;
		}

		$group = Session::group_for_order( $order );

		if ( ! Registry::has_own_stock( $group ) ) {
			return $can;
		}

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$id = $product->get_id();

			// Never invent a count for something that was never counted.
			if ( null === self::counted( $group, $id ) ) {
				continue;
			}

			self::move( $group, $id, $sign * (int) $item->get_quantity() );
		}

		// WooCommerce must not also touch the shared pool for this order.
		return false;
	}
}
