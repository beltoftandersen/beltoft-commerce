<?php

namespace BeltoftCommerce\Scanner;

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Frontend\OrderingPage;
use BeltoftCommerce\Support\Hosts;
use WC_Product;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The B2B loja's place in the barcode scanner (beltoft-scanner).
 *
 * The scanner is a core that knows nothing about this shop: it asks the plugins
 * that use it where it belongs, who may scan, and what they may order. This is
 * the trade list's answer to all three, given only through the scanner's
 * filters - no scanner class is referenced, so either plugin can be absent
 * without the other failing.
 */
class ScannerContext {

	public const ID = 'b2b-loja';

	/** The scanner's statuses for a refused product (see its ProductLookup). */
	private const NOT_VISIBLE     = 'not_visible';
	private const NOT_PURCHASABLE = 'not_purchasable';

	/**
	 * Who may scan on the trade list.
	 *
	 * b2b_pending is deliberately not here: an applicant can sign in before
	 * anyone has approved them and must not price or order anything. The till
	 * scans on its own page, through beltoft-pos's own context.
	 */
	private const ROLES = array( 'b2b_customer' );

	public static function register(): void {
		add_filter( 'beltoft_scanner_contexts', array( __CLASS__, 'add_context' ) );
		add_filter( 'beltoft_scanner_assess', array( __CLASS__, 'assess' ), 10, 3 );
		/*
		 * Only with the notifier. The scanner offers "Notify me" whenever
		 * anything answers this hook (has_filter), so hooking in without the
		 * notifier would show a button that cannot work.
		 */
		if ( class_exists( '\BeltoftInStockNotifier\Subscription\Repository' ) ) {
			add_filter( 'beltoft_scanner_notify', array( __CLASS__, 'notify' ), 10, 4 );
		}
	}

	/**
	 * "Notify me": a partner who scans an out-of-stock product can ask for the
	 * back-in-stock email there and then.
	 *
	 * The scanner keeps no subscriptions; beltoft-in-stock-notifier does, and
	 * this hands the request over to it. Without the notifier this answers
	 * nothing and the scanner simply does not offer the button. The scanner
	 * has already applied the catalogue rules (assess() above) before asking.
	 *
	 * The notifier stores a variation under its parent, as its own product page
	 * form does. Asking means consent - the partner pressed the button for
	 * exactly this email.
	 *
	 * @param mixed       $result   An earlier handler's answer, which stands.
	 * @param \WC_Product $product
	 * @param \WP_User    $user
	 * @param int         $quantity
	 * @return mixed 'subscribed', 'already', a WP_Error, or $result untouched.
	 */
	public static function notify( $result, $product = null, $user = null, $quantity = 1 ) {
		if ( null !== $result || ! $product instanceof WC_Product || ! $user instanceof WP_User ) {
			return $result;
		}

		if ( ! class_exists( '\BeltoftInStockNotifier\Subscription\Repository' ) ) {
			return $result;
		}

		$parent = $product->get_parent_id();

		$id = \BeltoftInStockNotifier\Subscription\Repository::upsert( array(
			'product_id'        => $parent ?: $product->get_id(),
			'variation_id'      => $parent ? $product->get_id() : 0,
			'email'             => $user->user_email,
			'locale'            => get_user_locale( $user ),
			'quantity'          => max( 1, (int) $quantity ),
			'gdpr_consent'      => 1,
			'unsubscribe_token' => wp_generate_password( 32, false ),
		) );

		if ( false === $id ) {
			return new \WP_Error(
				'beltoft_commerce_notify_failed',
				__( 'The back-in-stock alert could not be saved. Please try again.', 'beltoft-commerce' ),
				array( 'status' => 500 )
			);
		}

		// The notifier answers 0 for an address already waiting on this product.
		return 0 === $id ? 'already' : 'subscribed';
	}

	/**
	 * @param mixed $contexts
	 * @return array
	 */
	public static function add_context( $contexts ): array {
		$contexts = is_array( $contexts ) ? $contexts : array();

		$contexts[ self::ID ] = array(
			'applies'  => array( __CLASS__, 'applies' ),
			'may_scan' => array( __CLASS__, 'may_scan' ),
		);

		return $contexts;
	}

	/** The ordering page, on the trade host. */
	public static function applies(): bool {
		return Hosts::is_b2b_host() && OrderingPage::is_ordering_page();
	}

	public static function may_scan( $user ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		// Shop staff use it to check the catalogue.
		return (bool) array_intersect( self::ROLES, (array) $user->roles ) || user_can( $user, 'manage_woocommerce' );
	}

	/**
	 * The catalogue's own answer, so the scanner never adds a line the list
	 * would not have shown. Judged on the parent product, as the catalogue is.
	 *
	 * @param mixed      $status  An earlier rule's refusal, which stands.
	 * @param WC_Product $product
	 * @param int        $user_id
	 * @return mixed
	 */
	public static function assess( $status, $product = null, $user_id = 0 ) {
		if ( null !== $status || ! $product instanceof WC_Product ) {
			return $status;
		}

		$parent = $product->get_parent_id() ?: $product->get_id();

		if ( ! CatalogAudience::can_view( (int) $user_id, $parent ) ) {
			return self::NOT_VISIBLE;
		}

		if ( ! CatalogAudience::can_purchase( (int) $user_id, $parent ) ) {
			return self::NOT_PURCHASABLE;
		}

		return null;
	}
}
