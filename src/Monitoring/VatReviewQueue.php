<?php

namespace BeltoftCommerce\Monitoring;

use BeltoftCommerce\Vat\OrderEvidence;

defined( 'ABSPATH' ) || exit;

/**
 * Orders whose VAT number could not be verified.
 *
 * §2.10: the order completes with IVA charged and enters a manual review
 * queue. The operational target is zero unresolved `unverified` orders at
 * month end.
 *
 * Queried through the WooCommerce order API, so it works whether orders live
 * in wp_posts or the HPOS tables - staging runs HPOS, production does not -
 * and the column is added to both order screens for the same reason. With
 * only the wp_posts hooks the column never appeared under HPOS, and nothing
 * else showed the queue.
 */
class VatReviewQueue {

	public static function register(): void {
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * @return \WC_Order[]
	 */
	public static function pending( array $args = array() ): array {
		$orders = wc_get_orders( array_merge( array(
			'limit'      => -1,
			'meta_key'   => OrderEvidence::CHECK_STATUS,
			'meta_value' => 'unverified',
		), $args ) );

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Unresolved orders for a given month, for the month-end reconciliation.
	 *
	 * @param string $month Y-m
	 */
	public static function month_end_report( string $month ): array {
		/*
		 * The month's first and last second in the shop's own time, as
		 * timestamps. The range used to end at the 1st of the next month as a
		 * date, which WooCommerce includes whole - so that day's orders were in
		 * two reports - and was worked out in UTC rather than Lisbon time.
		 */
		$first = \DateTimeImmutable::createFromFormat( '!Y-m', $month, wp_timezone() );
		$start = $first ? $first->getTimestamp() : 0;
		$end   = $first ? $first->modify( 'first day of next month' )->getTimestamp() - 1 : 0;

		$orders = self::pending( array(
			'date_created' => $start . '...' . $end,
		) );

		return array(
			'month'  => $month,
			'count'  => count( $orders ),
			'orders' => array_map(
				static function ( $order ) {
					return array(
						'id'         => $order->get_id(),
						'vat_number' => $order->get_meta( OrderEvidence::VAT_NUMBER ),
						'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
					);
				},
				$orders
			),
		);
	}

	public static function add_column( $columns ) {
		$columns['beltoft_vat'] = __( 'VAT check', 'beltoft-commerce' );

		return $columns;
	}

	/**
	 * @param string             $column
	 * @param int|\WC_Order $order_or_id An id on the wp_posts screen, the order under HPOS.
	 */
	public static function render_column( $column, $order_or_id ): void {
		if ( 'beltoft_vat' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );

		if ( ! $order ) {
			return;
		}

		$status = (string) $order->get_meta( OrderEvidence::CHECK_STATUS );

		if ( 'unverified' === $status ) {
			echo '<mark class="order-status status-on-hold"><span>' . esc_html__( 'Needs review', 'beltoft-commerce' ) . '</span></mark>';

			return;
		}

		echo esc_html( $status ?: '—' );
	}
}
