<?php

namespace BeltoftCommerce\Scanner;

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * The back-in-stock email's product link, on the partner's own site.
 *
 * beltoft-in-stock-notifier builds the link from get_permalink() when stock
 * changes - in admin, cron or a sync, all on the retail host. A partner who
 * asked from the trade list ("Notify me", ScannerContext::notify()) was sent
 * to the retail shop: retail prices, and a 404 for a B2B-only product. A
 * recipient whose account prices as trade gets the same page on the trade
 * host instead. Hooked only when the notifier is present.
 */
class BackInStockLink {

	public static function register(): void {
		if ( class_exists( '\BeltoftInStockNotifier\Subscription\Repository' ) ) {
			add_filter( 'bisn_email_product_url', array( __CLASS__, 'filter' ), 10, 3 );
		}
	}

	/**
	 * @param mixed $url
	 * @param mixed $product
	 * @param mixed $email The notifier's WC_Email, carrying the recipient.
	 * @return mixed
	 */
	public static function filter( $url, $product = null, $email = null ) {
		if ( ! is_string( $url ) || ! $product instanceof \WC_Product || ! Hosts::is_b2b_configured() ) {
			return $url;
		}

		$recipient = $email instanceof \WC_Email ? (string) $email->get_recipient() : '';
		$user      = '' !== $recipient ? get_user_by( 'email', $recipient ) : false;

		if ( ! $user || ! Registry::is_trade( PricingContext::for_user( (int) $user->ID ) ) ) {
			return $url;
		}

		$parent = $product->get_parent_id() ?: $product->get_id();

		if ( ! CatalogAudience::can_view( (int) $user->ID, (int) $parent ) ) {
			return $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? (string) preg_replace( '#^(https?://)' . preg_quote( $host, '#' ) . '#i', '${1}' . Hosts::b2b(), $url, 1 ) : $url;
	}
}
