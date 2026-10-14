<?php

namespace BeltoftCommerce\Monitoring;

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Finds products a pricing group can see but has no price for.
 *
 * §2.5 requires this condition to raise an operational alert naming the SKU:
 * a missing wholesale price means the import did not cover that product, and
 * it should be visible as a fault rather than absorbed.
 *
 * Implemented as a query against the group price lookup index rather than a
 * meta scan — the index already has to be correct, so reusing it is both
 * cheaper and self-checking.
 */
class MissingPriceScan {

	public const HOOK = 'beltoft_commerce_missing_price_scan';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_and_alert' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * @return array<string, array<int, string>> group slug => [product id => sku]
	 */
	public static function run(): array {
		global $wpdb;

		$report = array();

		if ( ! LookupIndex::table_exists() ) {
			return $report;
		}

		$table = LookupIndex::table();

		foreach ( array_keys( Registry::groups() ) as $group ) {
			/*
			 * A group priced from WooCommerce's own fields owns no group price
			 * meta, so it can have neither a missing one nor an orphaned one.
			 * Scanning it reports every product in the shop.
			 */
			if ( Registry::inherits_prices( $group ) ) {
				continue;
			}
			$report[ $group ] = array();

			$visible = self::visible_product_ids( (string) $group );

			if ( ! $visible ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $visible ), '%d' ) );

			$priced = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$table} WHERE price_group = %s AND product_id IN ({$placeholders})",
					array_merge( array( $group ), $visible )
				)
			);

			$missing = array_diff( $visible, array_map( 'intval', $priced ) );

			foreach ( $missing as $product_id ) {
				$product = wc_get_product( $product_id );

				$report[ $group ][ (int) $product_id ] = $product ? (string) $product->get_sku() : '';
			}
		}

		return $report;
	}

	/**
	 * Products the given group is allowed to see.
	 *
	 * @return int[]
	 */
	private static function visible_product_ids( string $group ): array {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => Taxonomy::b2b_visible_slugs(),
					'operator' => 'IN',
				),
			),
			// Runs from cron with no user, which resolves to retail
			// context. Without this bypass the audience filter would hide
			// the B2B-only products this scan exists to find.
			'beltoft_bypass_audience' => true,
		) );

		return array_map( 'intval', $ids );
	}

	public static function run_and_alert(): void {
		$report = array_filter( self::run() );

		if ( ! $report ) {
			return;
		}

		Alerts::missing_prices( $report );
	}
}
