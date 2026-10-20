<?php

namespace BeltoftCommerce\Checkout;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\Language;
use BeltoftCommerce\Support\TradePath;

defined( 'ABSPATH' ) || exit;

/**
 * A checkout of its own for trade, without a second checkout implementation.
 *
 * The fields are the retail ones — same address, same payment, same validation.
 * What differs is the layout: trade orders are long, so the B2B page uses
 * Bricks' multistep Checkout v2 rather than one tall column.
 *
 * WHY THE PAGE ID IS FILTERED rather than the URL. `wc_get_page_id( 'checkout' )`
 * runs its option through `woocommerce_get_checkout_page_id`
 * (wc-page-functions.php:89), and everything downstream asks that one function:
 * `is_checkout()`, `wc_get_checkout_url()`, the order-pay and order-received
 * endpoints, notice output, and Bricks' own checkout-state detection. Swapping
 * the id makes the B2B page a REAL checkout to all of them. Filtering only the
 * URL would have produced a page that looks like a checkout and is not one —
 * `is_checkout()` false, endpoints resolving to the retail page, and no notices.
 *
 * Retail is untouched: the filter answers only for a B2B session.
 */
class B2BCheckoutPage {

	/** Option holding the B2B checkout page id, so the id is not hard-coded. */
	public const OPTION = 'beltoft_commerce_b2b_checkout_page_id';

	/**
	 * Where the trade checkout lives on the trade host - Frontend\HostPages
	 * serves it there - in a language when a code is given (Config
	 * 'trade_paths'), without the language's path prefix.
	 */
	public static function path( string $code = '' ): string {
		return Config::path( 'checkout_path', $code );
	}

	/*
	 * Where it used to live. Order e-mails already sent carry thank-you and
	 * pay links on this path, so it forwards rather than 404s - endpoint tail
	 * and ?key= included, or the link in the e-mail would open to nothing.
	 */
	public static function legacy_paths( string $code = '' ): array {
		return Config::paths( 'checkout_legacy_paths', $code );
	}

	/**
	 * The Store API's checkout is refused on the trade host.
	 *
	 * The trade checkout is the classic one (this page): that is where the VAT
	 * number is checked against VIES and its evidence written to the order
	 * (Vat\Field, Vat\OrderEvidence), on hooks the Store API never fires. A
	 * signed-in partner posting /wc/store/v1/checkout got a zero-rated order
	 * decided from a cached answer, with no evidence and no review flag.
	 * Batch requests are dispatched one by one through the same filter.
	 *
	 * @param mixed            $result
	 * @param mixed            $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function refuse_store_api_checkout( $result, $server = null, $request = null ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! Hosts::is_b2b_host() ) {
			return $result;
		}

		if ( ! preg_match( '#^/wc/store(/v\d+)?/checkout(/|$)#i', $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error(
			'beltoft_commerce_use_checkout_page',
			__( 'Please place trade orders from the checkout page.', 'beltoft-commerce' ),
			array( 'status' => 403 )
		);
	}

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'refuse_store_api_checkout' ), 10, 3 );
		/*
		 * Priority 5: a language plugin (Zerolang's Woo::page_id, at 10)
		 * translates whatever id it is handed, so the trade id has to be in
		 * place before it runs - at the same priority Zerolang ran first and
		 * this overwrote its answer with the Portuguese page.
		 */
		add_filter( 'woocommerce_get_checkout_page_id', array( __CLASS__, 'page_id' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'forward_legacy' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'forward_trade_order_pay' ), 1 );
	}

	/**
	 * A trade order's pay page opened anywhere but the trade host goes there.
	 *
	 * My Account on retail lists the partner's orders with their "Pay" links,
	 * on retail. Since 2026-10-20 the retail host prices and pays a partner as
	 * a retail shopper (architecture §2.3, amended); a trade order is paid on
	 * the trade checkout. B2BPaymentMethods decides from the order as well,
	 * for anything that reaches the endpoint here regardless.
	 */
	public static function forward_trade_order_pay(): void {
		global $wp;

		$id    = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order = $id ? wc_get_order( $id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$target = self::pay_target( $order );

		if ( '' !== $target ) {
			// A different host, which wp_safe_redirect() would refuse.
			wp_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * The pay page of an order placed on a trade host, on that very trade
	 * host (the partner's session is there and nowhere else) in its language,
	 * or '' when it is paid where it is.
	 */
	public static function pay_target( \WC_Order $order ): string {
		if ( Hosts::is_b2b_host() || ! Hosts::is_b2b_configured() || ! \BeltoftCommerce\Domain\OrderHost::placed_on_trade_host( $order ) ) {
			return '';
		}

		$host = \BeltoftCommerce\Domain\OrderHost::for_order( $order );
		$code = Hosts::b2b_language( $host );
		$code = '' !== $code ? $code : Language::of_order( $order );

		$slug = function_exists( 'WC' ) && isset( WC()->query ) ? (string) ( WC()->query->get_query_vars()['order-pay'] ?? '' ) : '';

		if ( '' === $slug ) {
			return '';
		}

		return 'https://' . $host . TradePath::prefix( $code ) . self::path( $code ) . $slug . '/' . $order->get_id() . '/?pay_for_order=true&key=' . rawurlencode( $order->get_order_key() );
	}

	/** The old checkout path forwards to the new one, endpoint and query intact. */
	public static function forward_legacy(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		$target = self::legacy_target( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );

		if ( null !== $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Where a legacy URI should go, or null if it is not one. Pure, for tests.
	 * In the request's language, prefix kept: /es/finalizar-b2b/ goes to
	 * /es/<the Spanish checkout path>/.
	 */
	public static function legacy_target( string $request_uri ): ?string {
		list( $code, $path ) = TradePath::split( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );

		foreach ( self::legacy_paths( $code ) as $legacy ) {
			if ( '' === $path || 0 !== strpos( $path, $legacy ) ) {
				continue;
			}

			$target = home_url( TradePath::prefix( $code ) . self::path( $code ) . substr( $path, strlen( $legacy ) ) );
			$query  = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

			return '' !== $query ? $target . '?' . $query : $target;
		}

		return null;
	}

	public static function page_id( $id ) {
		if ( ! self::applies() ) {
			return $id;
		}

		$b2b = self::configured_id();

		return $b2b ?: $id;
	}

	public static function configured_id(): int {
		$id = (int) get_option( self::OPTION, 0 );

		// A trashed or deleted page must fall back to retail rather than send
		// the buyer to a 404 with a full cart.
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}
}
