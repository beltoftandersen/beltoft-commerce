<?php

namespace BeltoftCommerce\Stock;

use BeltoftCommerce\Pricing\Registry;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Which shelves sold past their count.
 *
 * The till does not refuse a sale it cannot account for - the customer picked
 * the thing up and carried it to the counter, so the goods are not in doubt and
 * the number is (GroupStock explains why at length). The price of that is that
 * the number can go below zero, and a negative shelf that nobody ever sees is
 * just a wrong number with extra steps.
 *
 * So this is the other half: a shelf below zero is a measurement, not a fault,
 * and it says exactly how far that count had drifted by the end of the day.
 * Somebody recounting after the market wants the list of them, and wants it
 * where they already are - on the products screen, not in a report nobody opens.
 *
 * Variations drift on their own shelves but are edited on their parent's screen,
 * so the filter lists parents while the count counts shelves.
 */
class Recount {

	/** edit.php?post_type=product&beltoft_recount=1 */
	public const FLAG = 'beltoft_recount';

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'only_the_drifted' ) );
	}

	/** The meta keys of every group that keeps its own stock - today, the till. */
	private static function keys(): array {
		$keys = array();

		foreach ( array_keys( Registry::groups() ) as $slug ) {
			if ( Registry::has_own_stock( $slug ) ) {
				$keys[] = Registry::stock_key( $slug );
			}
		}

		return $keys;
	}

	/**
	 * Every product or variation whose own-stock shelf is below zero.
	 *
	 * @return int[]
	 */
	public static function below_zero(): array {
		global $wpdb;

		$keys = self::keys();

		if ( ! $keys ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// CAST, because meta_value is text and '-3' < '0' is not what a string
		// comparison would answer.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key IN ($in) AND CAST( meta_value AS SIGNED ) < 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of placeholders.
				$keys
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The same set, as things that can be opened and edited.
	 *
	 * @return int[]
	 */
	public static function products_to_recount(): array {
		$out = array();

		foreach ( self::below_zero() as $id ) {
			$parent = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : 0;
			$out[]  = $parent ?: $id;
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	public static function notice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'edit-product' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Already looking at the list - saying it again is noise.
		if ( isset( $_GET[ self::FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a list filter.
			return;
		}

		$shelves = count( self::below_zero() );

		if ( ! $shelves ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of products whose till stock has gone below zero. */
					_n(
						'%d product has sold past its counted till stock. The sales went through; the count needs correcting.',
						'%d products have sold past their counted till stock. The sales went through; the counts need correcting.',
						$shelves,
						'beltoft-commerce'
					),
					$shelves
				)
			),
			esc_url( add_query_arg( self::FLAG, 1, admin_url( 'edit.php?post_type=product' ) ) ),
			esc_html__( 'Show them', 'beltoft-commerce' )
		);
	}

	/** @param mixed $query */
	public static function only_the_drifted( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( empty( $_GET[ self::FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a list filter.
			return;
		}

		// 0 rather than an empty array: post__in with nothing in it is ignored
		// by WP_Query, which would answer "nothing drifted" with every product.
		$query->set( 'post__in', self::products_to_recount() ?: array( 0 ) );
	}
}
