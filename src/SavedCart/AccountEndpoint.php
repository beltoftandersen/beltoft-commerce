<?php

namespace BeltoftCommerce\SavedCart;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Saved orders as a My Account section.
 *
 * The ordering page is where a basket is saved; this is where a partner comes
 * back for it, next to their orders and addresses, which is where anyone would
 * look for "the things I keep here".
 *
 * NOTE: adding an endpoint adds a rewrite rule. Rules are flushed on activation
 * and whenever this endpoint is missing from the stored rules, rather than on
 * every request - flushing is expensive and doing it on init is a well-known
 * way to make a site slow for reasons nobody can find later.
 */
class AccountEndpoint {

	public const ENDPOINT = 'encomendas-guardadas';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_var' ) );
	}

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( ! get_option( 'beltoft_saved_carts_endpoint_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'beltoft_saved_carts_endpoint_flushed', '1', false );
		}
	}

	/**
	 * @param array $vars
	 * @return array
	 */
	public static function query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Trade only. Retail customers do not build forty-line baskets they need
	 * again in three weeks, and an empty section in their account would be one
	 * more thing to explain.
	 *
	 * @param array $items
	 * @return array
	 */
	public static function menu_item( $items ) {
		if ( ! Hosts::is_b2b_host() || ! is_array( $items ) ) {
			return $items;
		}

		$new = array();

		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;

			// Directly after Orders, which is the thing it is closest to.
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Saved orders', 'beltoft-commerce' );
			}
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Saved orders', 'beltoft-commerce' );
		}

		return $new;
	}

	public static function content(): void {
		echo '<h2>' . esc_html__( 'Saved orders', 'beltoft-commerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Open a saved order to put it in the cart, or add it to what you already have.', 'beltoft-commerce' ) . '</p>';

		/*
		 * Echoed, not passed through wp_kses_post().
		 *
		 * That function's allowlist is for untrusted post content: it strips
		 * <form> and <input> outright. Running our own markup through it left
		 * this page listing saved orders with no form around them, no nonce and
		 * no id - every button dead, and nothing to show that anything was
		 * wrong. The markup is ours and every value in it is escaped where it
		 * is written; there is nothing here to sanitise.
		 */
		// No save form: there is nothing to save from an account page.
		echo Ui::render( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
