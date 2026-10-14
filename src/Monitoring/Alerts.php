<?php

namespace BeltoftCommerce\Monitoring;

use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Operational alerts.
 *
 * §2.5 requires a missing group price to raise an alert NAMING THE SKU —
 * "some products are missing prices" is not actionable.
 *
 * The recipient comes from configuration and defaults to the site admin
 * address. It is never derived from a customer record: this store's staging
 * clone carries live SES credentials and a full customer list, so an alert
 * that could pick up a customer address is a hazard rather than a feature.
 */
class Alerts {

	public const RECIPIENT_OPTION = 'beltoft_commerce_alert_email';

	/** Kept as literals-turned-constants: the missing-price key predates this
	 * class carrying two reports and must not change, or a pending notice
	 * from the previous release would be stranded. */
	private const MISSING_TRANSIENT  = 'beltoft_commerce_missing_price_notice';
	private const ORPHANED_TRANSIENT = 'beltoft_commerce_orphaned_price_notice';

	public static function recipient(): string {
		$configured = (string) get_option( self::RECIPIENT_OPTION, '' );

		return $configured ?: (string) get_option( 'admin_email' );
	}

	/**
	 * @param array<string, array<int, string>> $report
	 */
	public static function missing_prices( array $report ): void {
		$lines = array();

		foreach ( $report as $group => $products ) {
			if ( ! $products ) {
				continue;
			}

			$groups = Registry::groups();
			$label  = $groups[ $group ]['label'] ?? $group;

			$lines[] = sprintf(
				/* translators: 1: pricing group label, 2: number of products */
				__( '%1$s — %2$d product(s) visible to this group with no price:', 'beltoft-commerce' ),
				$label,
				count( $products )
			);

			foreach ( $products as $product_id => $sku ) {
				$lines[] = sprintf(
					'  #%d  %s  %s',
					$product_id,
					$sku !== '' ? $sku : '(no SKU)',
					get_edit_post_link( $product_id, 'raw' )
				);
			}

			$lines[] = '';
		}

		if ( ! $lines ) {
			return;
		}

		self::dispatch(
			self::MISSING_TRANSIENT,
			'beltoft_commerce_send_missing_price_alert',
			__( 'Products are missing a group price', 'beltoft-commerce' ),
			implode( "\n", $lines )
		);
	}

	/**
	 * The mirror fault: a group price exists for a product that group is not
	 * allowed to see, so the price never applies to anything.
	 *
	 * @param array<string, array<int, string>> $report
	 */
	public static function orphaned_prices( array $report ): void {
		$lines = array();

		foreach ( $report as $group => $products ) {
			if ( ! $products ) {
				continue;
			}

			$groups = Registry::groups();
			$label  = $groups[ $group ]['label'] ?? $group;

			$lines[] = sprintf(
				/* translators: 1: pricing group label, 2: number of products */
				__( '%1$s — %2$d product(s) priced for this group but not tagged for it, so this group never sees them:', 'beltoft-commerce' ),
				$label,
				count( $products )
			);

			foreach ( $products as $product_id => $sku ) {
				$lines[] = sprintf(
					'  #%d  %s  %s',
					$product_id,
					$sku !== '' ? $sku : '(no SKU)',
					get_edit_post_link( $product_id, 'raw' )
				);
			}

			$lines[] = '';
		}

		if ( ! $lines ) {
			return;
		}

		self::dispatch(
			self::ORPHANED_TRANSIENT,
			'beltoft_commerce_send_orphaned_price_alert',
			__( 'Products are priced for a group that cannot see them', 'beltoft-commerce' ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Stores the report for the admin notice and mails it.
	 *
	 * @param string $transient Where the admin notice reads it from.
	 * @param string $filter    Lets an integration route this somewhere other
	 *                          than email; returning false suppresses the mail.
	 */
	private static function dispatch( string $transient, string $filter, string $subject, string $body ): void {
		set_transient( $transient, $body, DAY_IN_SECONDS );

		if ( false === apply_filters( $filter, true, $body ) ) {
			return;
		}

		wp_mail( self::recipient(), $subject, $body );
	}

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notices = array(
			self::MISSING_TRANSIENT  => __( 'Products are missing a group price', 'beltoft-commerce' ),
			self::ORPHANED_TRANSIENT => __( 'Products are priced for a group that cannot see them', 'beltoft-commerce' ),
		);

		foreach ( $notices as $transient => $heading ) {
			$body = get_transient( $transient );

			if ( ! $body ) {
				continue;
			}

			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong></p><pre>%s</pre></div>',
				esc_html( $heading ),
				esc_html( $body )
			);
		}
	}
}
