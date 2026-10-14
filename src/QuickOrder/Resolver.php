<?php

namespace BeltoftCommerce\QuickOrder;

use BeltoftCommerce\Catalog\CatalogAudience;

defined( 'ABSPATH' ) || exit;

/**
 * Turns typed or uploaded codes into cart lines.
 *
 * THE ONLY MATCHING LOGIC IN THE FEATURE. Both entry points come through here,
 * so the rules about what a buyer may order — and the words used when they may
 * not — exist once. Two implementations is how the paste pad and the sheet
 * start disagreeing about the same SKU.
 *
 * IT DOES NOT RE-IMPLEMENT PURCHASABILITY. It asks CatalogAudience and then
 * calls the real cart, so Enforcement\CartGuard re-validates every line exactly
 * as it does for a click on the ordering list. Quick order must never become a
 * way into the cart that the enforcement layer does not see.
 */
class Resolver {

	/**
	 * @param array<int, array{code: string, quantity: int}> $lines
	 */
	public static function resolve( array $lines, ?int $user_id = null ): Report {
		$user_id = $user_id ?? get_current_user_id();
		$report  = new Report();

		foreach ( $lines as $line ) {
			$code     = trim( (string) ( $line['code'] ?? '' ) );
			$quantity = (int) ( $line['quantity'] ?? 0 );

			if ( '' === $code ) {
				continue;
			}

			/*
			 * Most rows in a downloaded sheet have no quantity. That is the
			 * normal state of the file, not a mistake to report back.
			 */
			if ( $quantity < 1 ) {
				continue;
			}

			$product_id = self::find_product_id( $code );

			if ( ! $product_id ) {
				$report->add_rejection( $code, Report::UNKNOWN );
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				$report->add_rejection( $code, Report::UNKNOWN );
				continue;
			}

			$audience_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;

			/*
			 * A product the buyer cannot even SEE must read exactly like a
			 * code that does not exist. Distinguishing "hidden" from "does
			 * not exist" would let a buyer pasting codes map the B2B-only
			 * catalog one SKU at a time — the disclosure Enforcement\SingleProduct
			 * already refuses by name. The reason stays NOT_AVAILABLE, so logs
			 * can still tell the two apart, but the message shown on screen
			 * borrows UNKNOWN's wording.
			 */
			if ( ! CatalogAudience::can_view( $user_id, $audience_id ) ) {
				$report->add_rejection( $code, Report::NOT_AVAILABLE, Report::message_for( Report::UNKNOWN ) );
				continue;
			}

			if ( ! CatalogAudience::can_purchase( $user_id, $audience_id ) ) {
				$report->add_rejection( $code, Report::NOT_AVAILABLE );
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$report->add_rejection( $code, Report::OUT_OF_STOCK );
				continue;
			}

			if ( ! $product->is_purchasable() ) {
				$report->add_rejection( $code, Report::NO_PRICE );
				continue;
			}

			if ( ! self::add_to_cart( $product, $quantity, $detail ) ) {
				$report->add_rejection( $code, Report::REFUSED, $detail );
				continue;
			}

			$report->add_success( $code, $product_id, $quantity );
		}

		return $report;
	}

	/**
	 * SKU first, then GTIN, through WooCommerce's own lookups rather than a
	 * hand-rolled postmeta scan: both use the indexed
	 * wc_product_meta_lookup table, exclude trash, restrict to the product
	 * post type and stay filterable. Never by name: two products can share a
	 * name and a wrong guess is a wrong order.
	 */
	public static function find_product_id( string $code ): int {
		$code = trim( $code );

		if ( '' === $code ) {
			return 0;
		}

		$by_sku = (int) wc_get_product_id_by_sku( $code );

		if ( $by_sku ) {
			return $by_sku;
		}

		return (int) wc_get_product_id_by_global_unique_id( $code );
	}

	/**
	 * A variation is added by its own id with its parent, the way WooCommerce
	 * expects; anything else is added plainly.
	 *
	 * WC_Cart::add_to_cart() catches its own exception, wc_add_notice()s the
	 * reason and returns false. The reason is the useful part — "restante
	 * 9" — but it arrives with no SKU on it. Lift it out of the notice queue
	 * and hand it back to the caller, which knows which line it belonged to.
	 * CartGuard stays authoritative: this does not decide purchasability, it
	 * only relays what the cart decided.
	 */
	private static function add_to_cart( \WC_Product $product, int $quantity, ?string &$detail = null ): bool {
		$detail = null;

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$before = self::error_notices();

		$added = $product->is_type( 'variation' )
			? WC()->cart->add_to_cart( $product->get_parent_id(), $quantity, $product->get_id(), $product->get_variation_attributes() )
			: WC()->cart->add_to_cart( $product->get_id(), $quantity );

		$after = self::error_notices();

		if ( count( $after ) > count( $before ) ) {
			$new    = array_slice( $after, count( $before ) );
			$detail = trim( wp_strip_all_tags( (string) ( $new[0]['notice'] ?? '' ) ) );

			// We are about to say this ourselves, with the code attached.
			$all          = wc_get_notices();
			$all['error'] = $before;
			wc_set_notices( $all );
		}

		return (bool) $added;
	}

	private static function error_notices(): array {
		return ( function_exists( 'wc_get_notices' ) && WC()->session ) ? wc_get_notices( 'error' ) : array();
	}
}
