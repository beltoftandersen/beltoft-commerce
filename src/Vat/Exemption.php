<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * Applies reverse charge while the totals are being worked out.
 *
 * The decision itself lives in Validator and is not repeated here. What this
 * class fixes is WHEN it is applied.
 *
 * OrderEvidence used to set the customer exempt on
 * `woocommerce_checkout_create_order`. WooCommerce fires that at
 * class-wc-checkout.php:476, AFTER set_data_from_cart() at line 465 has
 * already copied the cart's tax lines, the total and the `is_vat_exempt`
 * meta onto the order, and nothing recalculates afterwards. So the flag was
 * set on a customer object nobody consulted again: a Spanish buyer with a
 * valid VIES number was charged Portuguese IVA and the order was stored
 * saying they were not exempt. Same cart, measured: exempt before totals ->
 * 0,00 tax; exempt at that hook -> 1,68 tax.
 *
 * `woocommerce_before_calculate_totals` is the right seam because every path
 * that produces a figure a customer sees or pays goes through it — the cart,
 * the checkout review, and the recalculation WC_Checkout does before building
 * the order.
 *
 * FAIL CLOSED. Exemption requires a cached, definitive VIES `valid`. An
 * unreachable service, an unchecked number or a VIES outage all charge IVA,
 * because wrongly zero-rating is an unrecoverable loss while wrongly charging
 * is a refund.
 *
 * THE ACCOUNT'S OWN ANSWER COUNTS FOR THE CART. The VIES cache lasts a day and
 * the cart never calls VIES itself, so a partner verified on Monday saw IVA
 * in their cart on Wednesday until they opened the checkout. When the cache
 * has lapsed, the definitive answer stored on the account (Evidence) is used
 * instead - only for the same number, only if it said valid, and only if it
 * is at most ACCOUNT_GRACE old - and a VIES check is queued in the
 * background to refresh it, so no page waits on VIES. The checkout still asks
 * VIES live before the order is placed (Field::validate / capture_review), so
 * what is charged is decided there, as before.
 */
class Exemption {

	/** Early, so the flag is set before any line is totalled. */
	private const PRIORITY = 5;

	/** How old the account's stored VIES "valid" may be for the cart to use it. */
	public const ACCOUNT_GRACE = 30 * DAY_IN_SECONDS;

	/** Background VIES re-check of an account's number (Action Scheduler). */
	public const REFRESH_HOOK = 'beltoft_commerce_vies_refresh';

	/** What ViesClient answers when the cache is empty and remote is not allowed. */
	private const NOT_CHECKED = 'not checked in this context';

	/** VAT number seen in the checkout AJAX payload, for this request only. */
	private static ?array $posted_review = null;

	/** Memoised per request: totals are recalculated more than once. */
	private static ?bool $decided = null;

	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply' ), self::PRIORITY );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_review' ), self::PRIORITY );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * The checkout review posts the form as one url-encoded blob, so the VAT
	 * field is not in $_POST where Field::posted() looks for it. Capturing it
	 * here is also the moment the real VIES call is allowed: this request is
	 * already an AJAX round trip the customer is waiting on, and answering it
	 * warms the cache for the totals that follow.
	 */
	public static function capture_review( $post_data ): void {
		$fields = array();
		parse_str( (string) $post_data, $fields );

		self::$posted_review = array(
			'number'  => isset( $fields['beltoft_vat_number'] ) ? sanitize_text_field( (string) $fields['beltoft_vat_number'] ) : '',
			'country' => isset( $fields['billing_country'] ) ? sanitize_text_field( (string) $fields['billing_country'] ) : '',
		);

		self::$decided = null;

		$number = self::$posted_review['number'];

		if ( '' !== $number ) {
			Validator::decide( $number, self::$posted_review['country'], true );
		}
	}

	public static function apply(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		WC()->customer->set_is_vat_exempt( self::is_exempt() );
	}

	public static function is_exempt(): bool {
		if ( null !== self::$decided ) {
			return self::$decided;
		}

		$number = self::number();

		if ( '' === $number ) {
			self::$decided = false;

			return false;
		}

		// Never remote from here: see the class docblock. capture_review()
		// and Field::validate() are the two places allowed to ask VIES, and
		// both run before the totals that matter.
		$decision = Validator::decide( $number, self::country(), false );

		self::$decided = $decision->exempt;

		// The day-long cache lapsed: fall back to the account's own answer and
		// refresh it in the background.
		if ( ! $decision->exempt && 'unverified' === $decision->status && self::NOT_CHECKED === $decision->error && self::is_account_number( $number ) ) {
			self::queue_refresh( get_current_user_id() );
			self::$decided = self::account_says_valid( get_current_user_id() );
		}

		return self::$decided;
	}

	/** Whether $number is the one stored on the signed-in account (not a different one typed at checkout). */
	private static function is_account_number( string $number ): bool {
		$stored = Field::stored_value();
		$norm   = static fn( $n ) => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $n ) );

		return get_current_user_id() && '' !== $stored && $norm( $stored ) === $norm( $number );
	}

	/** The account's stored VIES answer: valid, and at most ACCOUNT_GRACE old. */
	public static function account_says_valid( int $user_id ): bool {
		if ( ! $user_id || 'valid' !== (string) get_user_meta( $user_id, OrderEvidence::CHECK_STATUS, true ) ) {
			return false;
		}

		$checked = strtotime( (string) get_user_meta( $user_id, OrderEvidence::REQUEST_DATE, true ) );

		return $checked && time() - $checked <= self::ACCOUNT_GRACE;
	}

	/** Queue one background VIES check for the account, unless one is waiting. */
	private static function queue_refresh( int $user_id ): void {
		if ( ! $user_id || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::REFRESH_HOOK, array( $user_id ), 'beltoft-commerce' ) ) {
			return;
		}

		as_enqueue_async_action( self::REFRESH_HOOK, array( $user_id ), 'beltoft-commerce', true );
	}

	/**
	 * The background check: ask VIES for the account's number (which also
	 * warms the day-long cache) and store a definitive answer on the account.
	 * An outage changes nothing; the stored answer ages out after the grace
	 * period and the cart charges IVA again.
	 *
	 * @param int $user_id
	 */
	public static function refresh( $user_id ): void {
		$user_id = (int) $user_id;
		$number  = Field::stored_value( $user_id );

		if ( ! $user_id || '' === $number ) {
			return;
		}

		$decision = Validator::decide( $number, (string) get_user_meta( $user_id, 'billing_country', true ), true );

		if ( in_array( $decision->status, array( 'valid', 'invalid' ), true ) ) {
			Evidence::store( $user_id, $number, $decision, $decision->status );
		}
	}

	/** Checkout submission, then checkout review, then what we hold on file. */
	private static function number(): string {
		$posted = Field::posted();

		if ( '' !== $posted ) {
			return $posted;
		}

		if ( null !== self::$posted_review ) {
			return self::$posted_review['number'];
		}

		return Field::stored_value();
	}

	private static function country(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only to pick a country for the VAT check; WooCommerce verifies the checkout nonce before submission is processed.
		if ( isset( $_POST['billing_country'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST['billing_country'] ) );
		}

		if ( null !== self::$posted_review && '' !== self::$posted_review['country'] ) {
			return self::$posted_review['country'];
		}

		return WC()->customer ? (string) WC()->customer->get_billing_country() : '';
	}

	/** Test seam. */
	public static function reset(): void {
		self::$posted_review = null;
		self::$decided       = null;
	}
}
