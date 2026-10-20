<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Records which host an order was placed on, and replays it when order
 * email is rendered.
 *
 * Order emails, password resets and anything rendered from cron or a queued
 * job have no HTTP_HOST and would otherwise build retail-domain links
 * regardless of where the order was placed. See architecture §2.3.
 */
class OrderHost {

	private const META_KEY = '_beltoft_origin_host';

	/** @var array<int, string|null> */
	private static array $host_stack = array();

	/** The host each nested push wants links built from. */
	private static array $hosts = array();

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'record_from_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'record_from_order' ) );

		/*
		 * THE WHOLE EMAIL, not the order table.
		 *
		 * This used to run between woocommerce_email_before_order_table and
		 * its after twin, which sounds like the part of the email that talks
		 * about the order and is in fact the part with no links in it. The
		 * order table lists names and prices; every link in the message - the
		 * logo, "view your order", the account link, the footer - is outside
		 * it. Measured on a trade order: fourteen links, fourteen of them
		 * retail, with the replay firing correctly the whole time and
		 * protecting a region that contained none of them.
		 *
		 * The header and footer hooks wrap everything the templates render.
		 * The inner pair is kept because the plain-text templates do not all
		 * fire the header, and the stack nests safely either way.
		 */
		add_action( 'woocommerce_email_header', array( __CLASS__, 'push_from_email' ), 1, 2 );
		add_action( 'woocommerce_email_footer', array( __CLASS__, 'pop_host' ), 99 );

		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'push_host' ), 1 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'pop_host' ), 99 );

		/*
		 * Late, and on the options rather than on HTTP_HOST.
		 *
		 * Setting HTTP_HOST and letting UrlFilter read it was the whole
		 * mechanism, which made this depend on UrlFilter being awake - and it
		 * is asleep for exactly the work that sends most of these emails,
		 * because a queued job has no host of its own and must not guess one
		 * from the request that happened to spawn the runner. An order does
		 * know its host; it recorded it. So it says so directly, at a priority
		 * after UrlFilter's, and the answer no longer depends on how the mail
		 * came to be sent.
		 */
		add_filter( 'option_home', array( __CLASS__, 'swap' ), 99 );
		add_filter( 'option_siteurl', array( __CLASS__, 'swap' ), 99 );
	}

	/** @param mixed $email */
	public static function push_from_email( $heading = '', $email = null ): void {
		$order = ( is_object( $email ) && isset( $email->object ) ) ? $email->object : null;

		self::push_host( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * Rewrite a generated URL onto the host the order was placed on.
	 *
	 * Applies to retail orders too, and deliberately: it is what makes the
	 * links in an order email a property of the order rather than of whichever
	 * process happened to render it.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function swap( $value ) {
		$host = self::current_host();

		if ( '' === $host || ! is_string( $value ) ) {
			return $value;
		}

		$parts = wp_parse_url( $value );

		if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) === strtolower( $host ) ) {
			return $value;
		}

		return str_replace( '://' . $parts['host'], '://' . $host, $value );
	}

	/** The innermost push that named a host. */
	private static function current_host(): string {
		for ( $i = count( self::$hosts ) - 1; $i >= 0; $i-- ) {
			if ( '' !== self::$hosts[ $i ] ) {
				return self::$hosts[ $i ];
			}
		}

		return '';
	}

	public static function record_from_order( $order ): void {
		self::record( $order instanceof \WC_Order ? $order->get_id() : (int) $order );
	}

	public static function record( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// The trade host it was placed on - one of several, one per language.
		$host = '' !== Hosts::current_b2b() ? Hosts::current_b2b() : Hosts::retail();

		$order->update_meta_data( self::META_KEY, $host );
		$order->save();
	}

	/**
	 * The host an order's links are built on.
	 *
	 * Orders placed before this feature existed, and orders staff made in
	 * wp-admin, have no recorded host. For a customer whose account trades,
	 * that is their own trade host (Registration\TradeHost: where they
	 * applied or last signed in, else the default trade host); for anyone
	 * else retail, where they were placed.
	 */
	public static function for_order( \WC_Order $order ): string {
		$host = $order->get_meta( self::META_KEY );

		if ( $host ) {
			return (string) $host;
		}

		$customer = (int) $order->get_customer_id();

		if ( $customer && Hosts::is_b2b_configured() && \BeltoftCommerce\Pricing\Registry::is_trade( \BeltoftCommerce\Pricing\PricingContext::for_user( $customer ) ) ) {
			return \BeltoftCommerce\Registration\TradeHost::for_user( $customer );
		}

		return Hosts::retail();
	}

	/**
	 * Whether an order is a trade order: placed on the trade host, or - with
	 * no host recorded (placed before hosts were recorded, or made by staff in
	 * wp-admin) - for a customer whose account trades. Decides how the order
	 * is paid wherever it is paid from (Checkout\B2BPaymentMethods).
	 */
	public static function is_trade_order( \WC_Order $order ): bool {
		$host = (string) $order->get_meta( self::META_KEY );

		if ( '' !== $host ) {
			return Hosts::is_b2b_host( $host );
		}

		$customer = (int) $order->get_customer_id();

		return $customer && \BeltoftCommerce\Pricing\Registry::is_trade( \BeltoftCommerce\Pricing\PricingContext::for_user( $customer ) );
	}

	/** Whether the order was placed on the trade host (recorded, not inferred). */
	public static function placed_on_trade_host( \WC_Order $order ): bool {
		$host = (string) $order->get_meta( self::META_KEY );

		return '' !== $host && Hosts::is_b2b_host( $host );
	}

	/**
	 * Emails can be rendered mid-request during checkout, so the previous
	 * value is stacked and restored rather than unset — clobbering the live
	 * host would corrupt the rest of that request.
	 *
	 * SETTING HTTP_HOST IS A CONTRACT WITH ZEROLANG (spec 2026-10-20, R4).
	 * Zerolang picks its active host set - the trade hosts, one per language -
	 * from HTTP_HOST, so a trade order's email, written from cron, wp-admin or
	 * the checkout, keeps every link on the order's own trade host and moves
	 * a link to another language to that language's trade host, never to a
	 * retail domain. Both suites pin it
	 * (OrderHostTest::test_the_email_push_sets_http_host_to_the_order_host and
	 * Zerolang's HostSetsTest::test_an_order_email_follows_the_pushed_host).
	 */
	public static function push_host( $order ): void {
		self::$host_stack[] = $_SERVER['HTTP_HOST'] ?? null;
		self::$hosts[]      = $order instanceof \WC_Order ? self::for_order( $order ) : '';

		if ( $order instanceof \WC_Order ) {
			// Still set, because plenty of code asks the request which host it
			// is on - trade labels and tax display among it - and that should
			// answer the same way the links do.
			$_SERVER['HTTP_HOST'] = self::for_order( $order );
		}
	}

	public static function pop_host(): void {
		if ( ! self::$host_stack ) {
			return;
		}

		array_pop( self::$hosts );

		$previous = array_pop( self::$host_stack );

		if ( null === $previous ) {
			unset( $_SERVER['HTTP_HOST'] );

			return;
		}

		$_SERVER['HTTP_HOST'] = $previous;
	}
}
