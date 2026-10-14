<?php

namespace BeltoftCommerce\Account;

use BeltoftCommerce\SavedCart\Actions;
use BeltoftCommerce\SavedCart\Repository;
use BeltoftCommerce\Support\Hosts;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Ordering the same thing again, from a past order.
 *
 * WooCommerce has this already, and on a trade site it does the wrong thing
 * twice over.
 *
 * WHICH ORDERS COUNT. WooCommerce allows reordering from `completed` alone.
 * Trade pays by bank transfer, so an order sits `on-hold` while the transfer is
 * arranged and `processing` while it is packed, and only reaches `completed`
 * once it has shipped. A partner who ordered on Monday and wants the same again
 * on Friday would find the button missing from every order they had actually
 * placed. Trade therefore counts on-hold and processing too - those are real
 * orders that somebody committed to.
 *
 * WHAT HAPPENS TO LINES THAT NO LONGER WORK. WooCommerce's own reorder walks
 * the items and `continue`s past anything missing, out of stock, or a variable
 * product without a variation - silently, with nothing said afterwards. It also
 * builds the cart array directly rather than going through add_to_cart(), so
 * Enforcement\CartGuard's audience check never runs and a product that has
 * since left the trade catalogue is put back in the basket. A partner reorders
 * forty lines, receives thirty-seven, and finds out when the boxes arrive.
 *
 * So trade uses its own, over the same validation the saved baskets use:
 * every line checked against today's catalogue, stock and audience, and
 * whatever could not be added named with the reason.
 */
class Reorder {

	public const ACTION = 'beltoft_reorder';

	/**
	 * One button per request, however many times the hook fires.
	 *
	 * woocommerce_order_details_after_order_table runs once per rendered order
	 * table, and the Bricks thank-you page renders more than one - the order
	 * details and the summary beside it - so the button appeared three times on
	 * a single screen. Nothing downstream deduplicates it, and a page offering
	 * the same action three times reads as a fault.
	 */
	private static bool $rendered = false;

	public static function register(): void {
		add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( __CLASS__, 'statuses' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'list_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'single_button' ) );
	}

	/**
	 * @param array $statuses
	 * @return array
	 */
	public static function statuses( $statuses ) {
		if ( ! Hosts::is_b2b_host() || ! is_array( $statuses ) ) {
			return $statuses;
		}

		return array_values( array_unique( array_merge( $statuses, array( 'processing', 'on-hold' ) ) ) );
	}

	private static function reorderable( WC_Order $order ): bool {
		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		if ( (int) $order->get_customer_id() !== get_current_user_id() || ! get_current_user_id() ) {
			return false;
		}

		$statuses = (array) apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) );

		return $order->has_status( $statuses ) && count( $order->get_items() ) > 0;
	}

	/**
	 * The button, on every row of the orders list.
	 *
	 * WooCommerce only offers this on the single order page, which means
	 * opening an order to find out whether it can be repeated. A partner
	 * scanning their history for "the big one from March" wants it from the
	 * list.
	 *
	 * @param array    $actions
	 * @param WC_Order $order
	 * @return array
	 */
	public static function list_action( $actions, $order ) {
		if ( ! $order instanceof WC_Order || ! self::reorderable( $order ) ) {
			return $actions;
		}

		$actions['beltoft-reorder'] = array(
			'url'  => self::url( $order ),
			'name' => __( 'Encomendar novamente', 'beltoft-commerce' ),
		);

		return $actions;
	}

	public static function single_button( $order ): void {
		if ( self::$rendered || ! $order instanceof WC_Order || ! self::reorderable( $order ) ) {
			return;
		}

		self::$rendered = true;

		printf(
			'<p class="beltoft-reorder"><a class="button" href="%s">%s</a></p>',
			esc_url( self::url( $order ) ),
			esc_html__( 'Encomendar novamente', 'beltoft-commerce' )
		);
	}

	/**
	 * A GET link rather than a form, because that is what WooCommerce's order
	 * actions are and a row of buttons should not be half links and half
	 * forms. The nonce is what makes it safe to act on.
	 */
	private static function url( WC_Order $order ): string {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => self::ACTION, 'order_id' => $order->get_id() ),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order->get_id()
		);
	}

	public static function handle(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id || ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::ACTION . '_' . $order_id ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! self::reorderable( $order ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}

		Actions::prepare_cart();

		$items = self::items_from_order( $order );

		/*
		 * Added to whatever is in the cart rather than replacing it. Somebody
		 * part way through building an order who then repeats an old one has
		 * not asked for their current work to be thrown away.
		 */
		$result = Repository::restore_items( $items, get_current_user_id(), false );

		Actions::report( get_current_user_id(), $result );

		wp_safe_redirect( self::back_to() );
		exit;
	}

	/**
	 * Every line of the order, deleted products included.
	 *
	 * A line whose product no longer exists used to be dropped here, before
	 * restore_items() could list it as skipped - so a forty-line order came
	 * back as thirty-seven with no word about the other three. Each line
	 * carries the name it was ordered under, which is all that is left to
	 * report a deleted product by.
	 *
	 * @return array<int, array{product_id:int, variation_id:int, quantity:int, name:string}>
	 */
	public static function items_from_order( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'name'         => (string) $item->get_name(),
			);
		}

		return $items;
	}

	/**
	 * Back to the ordering page, where the cart they just filled is visible -
	 * not to the order history they were reading, which now shows nothing
	 * about what just happened.
	 */
	private static function back_to(): string {
		if ( class_exists( '\BeltoftCommerce\Frontend\QuickOrderPagePathShim' ) ) {
			return home_url( \BeltoftCommerce\Frontend\QuickOrderPagePathShim::ordering_path() );
		}

		return wc_get_cart_url();
	}
}
