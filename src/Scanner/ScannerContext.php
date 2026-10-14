<?php

namespace BeltoftCommerce\Scanner;

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Frontend\OrderingPage;
use BeltoftCommerce\Support\Hosts;
use WC_Product;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The B2B loja's place in the barcode scanner (beltoft-b2b-scanner).
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
	 * anyone has approved them and must not price or order anything.
	 *
	 * pos_operator is here only because the till still orders from this list.
	 * It goes when beltoft-pos registers the till's own page.
	 */
	private const ROLES = array( 'b2b_customer', 'pos_operator' );

	public static function register(): void {
		add_filter( 'beltoft_scanner_contexts', array( __CLASS__, 'add_context' ) );
		add_filter( 'beltoft_scanner_assess', array( __CLASS__, 'assess' ), 10, 3 );
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
