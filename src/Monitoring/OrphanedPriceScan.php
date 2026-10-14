<?php

namespace BeltoftCommerce\Monitoring;

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Finds products a pricing group HAS a price for but is not allowed to see.
 *
 * The mirror image of MissingPriceScan, and the direction that actually
 * fails quietly. A product visible to B2B with no price is loud: the buyer
 * reaches it and cannot buy it. A product priced for B2B with no audience
 * term is silent — QueryFilter excludes it from every B2B listing, so the
 * price simply never applies to anything and nobody notices. The catalog
 * looks smaller than it is and no error is raised anywhere.
 *
 * That is precisely the shape of a partial import. Audience and price are
 * two independent recordings of one decision ("B2B may buy this"), written
 * by two different processes; when only one of them lands, this is the only
 * thing that will say so.
 *
 * Runs on MissingPriceScan's hook rather than its own. The two checks are
 * the two halves of one question — does the audience taxonomy still agree
 * with the price index — and an operator should not have to discover that
 * catalog integrity is covered by two separate daily jobs, either of which
 * could be unscheduled without the other showing a gap.
 */
class OrphanedPriceScan {

	public static function register(): void {
		add_action( MissingPriceScan::HOOK, array( __CLASS__, 'run_and_alert' ) );
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
			$group            = (string) $group;
			$report[ $group ] = array();

			$priced = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT product_id FROM {$table} WHERE price_group = %s", $group )
				)
			);

			if ( ! $priced ) {
				continue;
			}

			$orphans = array_diff( $priced, self::visible_product_ids() );

			foreach ( $orphans as $product_id ) {
				$product = wc_get_product( $product_id );

				/*
				 * A price row whose product no longer exists is a stale index
				 * entry, not an untagged product, and reporting it as one
				 * would send an operator looking for a term to fix on a post
				 * that is gone.
				 */
				if ( ! $product ) {
					continue;
				}

				$report[ $group ][ (int) $product_id ] = (string) $product->get_sku();
			}
		}

		return $report;
	}

	/**
	 * Products carrying an audience term that grants a B2B session sight of
	 * them. Deliberately not per-group: the audience taxonomy does not model
	 * individual pricing groups, only retail versus trade, so every non-retail
	 * group shares one visible set. If audience ever becomes group-aware this
	 * is the one place that has to learn about it.
	 *
	 * @return int[]
	 */
	private static function visible_product_ids(): array {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
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
			// Runs from cron with no user, which resolves to retail context;
			// without the bypass the audience filter would hide the very
			// products this scan compares against.
			'beltoft_bypass_audience' => true,
		) );

		return array_map( 'intval', $ids );
	}

	public static function run_and_alert(): void {
		$report = array_filter( self::run() );

		if ( ! $report ) {
			return;
		}

		Alerts::orphaned_prices( $report );
	}
}
