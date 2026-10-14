<?php

namespace BeltoftCommerce\Stock;

use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Where somebody types what is actually on the shelf.
 *
 * One field per group that keeps its own pool, on the Inventory tab beside
 * WooCommerce's own quantity, and the same on each variation. Without this the
 * pool is only reachable from code, which makes the whole feature unusable by
 * the people who run the shop.
 *
 * READ AND WRITTEN AS RAW META, never through the product getters. An
 * administrator is not a till session, so Stock\GroupStock leaves the getters
 * alone for them - but an administrator who also held the POS role would
 * otherwise see, and save, the shelf count in WooCommerce's own quantity box.
 *
 * EMPTY IS NOT ZERO. An empty field means "never counted", which
 * GroupStock reads as sell-without-a-cap; a zero means "counted, none left"
 * and stops the sale. The two are different answers and the field keeps them
 * apart rather than saving 0 for a blank.
 */
class AdminField {

	public static function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'simple' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );

		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'variation' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/** @return string[] slug => label */
	private static function pools(): array {
		$pools = array();

		foreach ( Registry::groups() as $slug => $group ) {
			if ( Registry::has_own_stock( $slug ) ) {
				$pools[ $slug ] = (string) ( $group['label'] ?? $slug );
			}
		}

		return $pools;
	}

	private static function field_name( string $slug, ?int $loop = null ): string {
		return null === $loop
			? 'beltoft_stock_' . $slug
			: 'beltoft_stock_' . $slug . '[' . $loop . ']';
	}

	public static function simple(): void {
		global $post;

		foreach ( self::pools() as $slug => $label ) {
			woocommerce_wp_text_input( array(
				'id'          => self::field_name( $slug ),
				'value'       => (string) get_post_meta( (int) $post->ID, Registry::stock_key( $slug ), true ),
				/* translators: %s: the group label, e.g. POS. */
				'label'       => sprintf( __( '%s stock', 'beltoft-commerce' ), $label ),
				'desc_tip'    => true,
				'description' => __( 'Counted separately from the shop stock above. Leave empty if it has never been counted - empty sells without a cap, 0 refuses the sale.', 'beltoft-commerce' ),
				'type'        => 'number',
				'custom_attributes' => array( 'step' => '1' ),
			) );
		}
	}

	/** @param mixed $product */
	public static function save_simple( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		foreach ( array_keys( self::pools() ) as $slug ) {
			self::store( (int) $product->get_id(), $slug, $_POST[ 'beltoft_stock_' . $slug ] ?? null );
		}
	}

	/**
	 * @param int   $loop
	 * @param array $data
	 * @param mixed $variation
	 */
	public static function variation( $loop, $data, $variation ): void {
		foreach ( self::pools() as $slug => $label ) {
			woocommerce_wp_text_input( array(
				'id'      => self::field_name( $slug, (int) $loop ),
				'name'    => self::field_name( $slug, (int) $loop ),
				'value'   => (string) get_post_meta( (int) $variation->ID, Registry::stock_key( $slug ), true ),
				/* translators: %s: the group label, e.g. POS. */
				'label'   => sprintf( __( '%s stock', 'beltoft-commerce' ), $label ),
				'type'    => 'number',
				'wrapper_class' => 'form-row form-row-full',
				'custom_attributes' => array( 'step' => '1' ),
			) );
		}
	}

	/**
	 * @param int $variation_id
	 * @param int $loop
	 */
	public static function save_variation( $variation_id, $loop ): void {
		foreach ( array_keys( self::pools() ) as $slug ) {
			$posted = $_POST[ 'beltoft_stock_' . $slug ][ $loop ] ?? null;
			self::store( (int) $variation_id, $slug, $posted );
		}
	}

	/** @param mixed $raw */
	private static function store( int $product_id, string $slug, $raw ): void {
		$key = Registry::stock_key( $slug );

		if ( null === $raw || '' === trim( (string) $raw ) ) {
			// Blank means never counted, which is not the same as none left.
			delete_post_meta( $product_id, $key );

			return;
		}

		update_post_meta( $product_id, $key, (int) $raw );
	}
}
