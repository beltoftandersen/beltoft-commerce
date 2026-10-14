<?php

namespace BeltoftCommerce\Pos;

use BeltoftCommerce\Support\Session;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout as a counter sees it.
 *
 * A till sale has no account behind it. The operator is signed in, but the
 * person paying is whoever is standing there, and most of them will not give a
 * name - so the form starts filled in as a counter sale and the operator types
 * over it only when the customer wants an invoice in their own name.
 *
 * WHY EVERY BILLING FIELD IS OPTIONAL. WooCommerce requires a name and an email
 * because it is built for someone buying from their sofa. Requiring either at a
 * counter means inventing them for every sale, and invented addresses are worse
 * than absent ones: they reach the accounting export looking like real
 * customers. Nothing is required; what the customer offers is recorded.
 *
 * WHY THE NIF IS ON THE ORDER AND NOT THE CUSTOMER. Vat\Field keeps a VAT
 * number against the account, which is right for a partner who always buys as
 * the same company. Here the account is the till, and each sale may carry a
 * different NIF - so it belongs to the sale.
 *
 * NOTHING IS SHIPPED. The customer is holding it - so the shipping plugins'
 * checkout furniture goes with the shipping.
 */
class Checkout {

	/** The name a sale carries when nobody gives one. */
	public const WALKIN_OPTION = 'beltoft_pos_walkin_name';

	/** The address a counter sale is filed under when nobody gives one. */
	public const EMAIL_OPTION = 'beltoft_pos_walkin_email';

	public static function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ) );
		// Both: one drops the address fields, the other stops WooCommerce
		// looking for a shipping method and charging for one.
		add_filter( 'woocommerce_cart_needs_shipping_address', array( __CLASS__, 'no_shipping' ) );
		add_filter( 'woocommerce_cart_needs_shipping', array( __CLASS__, 'no_shipping' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 10, 2 );
		add_filter( 'woocommerce_checkout_update_customer_data', array( __CLASS__, 'keep_account_clean' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_after_sale' ), 99 );
		add_filter( 'woocommerce_format_postcode', array( __CLASS__, 'blank_postcode_stays_blank' ), 10, 2 );

		/*
		 * On wp_loaded rather than with the rest of the counter tidy-up on
		 * template_redirect, for the reason given above the terms filter: the
		 * checkout posts to ?wc-ajax=checkout and WC_AJAX::do_wc_ajax() runs on
		 * template_redirect at priority 0 and exits, so a removal registered
		 * there never reaches the request that validates and saves. wp_loaded
		 * runs on every request, and late enough that the pickup plugin - which
		 * boots on plugins_loaded at 999 - has registered what is being removed.
		 */
		add_action( 'wp_loaded', array( __CLASS__, 'drop_pickup_point_field' ), 99 );

		/*
		 * Registered here, not in strip_consent_chrome() below, and this is the
		 * difference between the terms block being invisible and being gone.
		 *
		 * WC_Checkout::validate_checkout() does not ask whether the checkbox is
		 * enabled - it refuses the order when `terms-field` was posted and
		 * `terms` was not (class-wc-checkout.php:1012). That hidden input is
		 * rendered by the terms template whenever this filter says so, whether
		 * or not a checkbox goes with it. So clearing the checkbox text left an
		 * invisible field that failed every sale with "Por favor leia e aceite
		 * os termos e condicoes".
		 *
		 * It also has to be attached at register time. The checkout posts to
		 * ?wc-ajax=checkout, and WC_AJAX::do_wc_ajax() runs on template_redirect
		 * at priority 0 and exits - so anything hooked there later, as the rest
		 * of the consent tidy-up is, never runs on the request that validates.
		 */
		add_filter( 'woocommerce_checkout_show_terms', array( __CLASS__, 'no_terms' ) );

		/*
		 * And the same block again, because this checkout is not WooCommerce's.
		 * The page is built in Bricks and the acceptance box is a Bricks
		 * element, so the filter above - which only governs WooCommerce's own
		 * template - never saw it. It is kept for the day the page is rebuilt
		 * with the standard checkout; this is what actually removes it today.
		 */
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'drop_terms_element' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'name_the_sale' ), 10, 2 );

		/*
		 * After `wp`, because checkout-subscribe attaches its own checkout UI
		 * on that hook and there would be nothing to remove before it.
		 */
		add_action( 'template_redirect', array( __CLASS__, 'strip_consent_chrome' ), 20 );
	}

	/**
	 * Where a counter sale's paperwork goes.
	 *
	 * Not the operator's own address, which is what WooCommerce prefills for a
	 * signed-in user: every receipt for every walk-in customer would land in
	 * the till's inbox and the orders would all read as having been placed by
	 * the same person. A shared shop address is the honest answer - the sale
	 * genuinely has no customer email unless the customer offers one, and then
	 * the operator types it over this.
	 */
	public static function walkin_email(): string {
		$email = (string) get_option( self::EMAIL_OPTION, '' );

		return '' !== $email ? $email : 'encomendas@mindthetrash.pt';
	}

	/**
	 * Replace WooCommerce's prefill, which comes from the signed-in account.
	 *
	 * @param mixed $value
	 * @param mixed $key
	 * @return mixed
	 */
	public static function prefill( $value, $key = '' ) {
		if ( ! Session::is_pos() ) {
			return $value;
		}

		/*
		 * Never over the operator's own typing.
		 *
		 * WC_Checkout::get_value() checks $_POST before this filter, so a
		 * normal re-render keeps what was entered. The order review AJAX does
		 * not post discrete fields though - it sends the whole form as one
		 * serialised `post_data` string - so that check misses, this filter is
		 * reached, and an unconditional default puts "Cliente de balcao" back
		 * over the name somebody had just typed. On a multi-step checkout that
		 * happens every time the buyer moves between steps.
		 *
		 * A default is only a default until somebody answers.
		 */
		$typed = self::posted_value( (string) $key );

		if ( null !== $typed ) {
			return $typed;
		}

		if ( 'billing_email' === $key ) {
			return self::walkin_email();
		}

		if ( 'billing_first_name' === $key ) {
			return self::walkin_name();
		}

		/*
		 * Nothing else comes from the account. The till is one account serving
		 * a queue of strangers, so whatever an earlier sale left there - a
		 * name, an address, a phone, a NIF - belongs to somebody else. Each
		 * sale starts from the walk-in name and the shop's own country.
		 */
		if ( 'billing_country' === $key ) {
			return WC()->countries->get_base_country();
		}

		if ( 0 === strpos( (string) $key, 'billing_' ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Keep the customer's details on the order, not on the till account.
	 *
	 * WooCommerce saves the checkout's billing fields to the signed-in
	 * account, which for a shopper is right. At the till the account is the
	 * operator's, so the next walk-in would be offered the last one's details.
	 *
	 * @param mixed $update
	 * @return mixed
	 */
	public static function keep_account_clean( $update ) {
		return Session::is_pos() ? false : $update;
	}

	/**
	 * After every till sale the account goes back to the walk-in name alone.
	 *
	 * keep_account_clean() stops the checkout saving to it, but the order
	 * review saves the address it is given to the session customer and on
	 * to the account as the operator types. So whatever reached the account,
	 * by any route, is cleared once the sale is placed - the account and the
	 * session copy WooCommerce prefills from.
	 */
	public static function clear_after_sale(): void {
		if ( ! Session::is_pos() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$customer = function_exists( 'WC' ) ? WC()->customer : null;

		if ( $customer instanceof \WC_Customer && (int) $customer->get_id() === $user_id ) {
			foreach ( array_keys( $customer->get_billing() ) as $prop ) {
				$setter = 'set_billing_' . $prop;

				if ( is_callable( array( $customer, $setter ) ) ) {
					$customer->{$setter}( '' );
				}
			}

			$customer->delete_meta_data( 'billing_nif' );
			$customer->set_billing_first_name( self::walkin_name() );
			$customer->set_billing_country( WC()->countries->get_base_country() );
			$customer->save();
		}

		foreach ( array_keys( (array) get_user_meta( $user_id ) ) as $key ) {
			if ( 0 === strpos( (string) $key, 'billing_' ) ) {
				delete_user_meta( $user_id, $key );
			}
		}

		update_user_meta( $user_id, 'billing_first_name', self::walkin_name() );
		update_user_meta( $user_id, 'billing_country', WC()->countries->get_base_country() );
	}

	/**
	 * What the buyer has already entered for a field, from either shape the
	 * checkout posts it in.
	 *
	 * @return string|null Null when the field has not been submitted at all.
	 */
	private static function posted_value( string $key ): ?string {
		if ( '' === $key ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reading only, to avoid overwriting input; WooCommerce verifies before acting on it.
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		if ( empty( $_POST['post_data'] ) ) {
			return null;
		}

		$fields = array();
		parse_str( (string) wp_unslash( $_POST['post_data'] ), $fields );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return isset( $fields[ $key ] ) ? sanitize_text_field( (string) $fields[ $key ] ) : null;
	}

	public static function walkin_name(): string {
		$name = (string) get_option( self::WALKIN_OPTION, '' );

		return '' !== $name ? $name : __( 'Walk-in customer', 'beltoft-commerce' );
	}

	/**
	 * @param mixed $fields
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! Session::is_pos() || ! is_array( $fields ) ) {
			return $fields;
		}

		// The counter does not post to anybody.
		unset( $fields['shipping'] );

		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			return $fields;
		}

		foreach ( $fields['billing'] as $key => $field ) {
			$fields['billing'][ $key ]['required'] = false;
		}

		/*
		 * Prefilled rather than hidden. The operator sees what the sale will be
		 * recorded as, and replaces it by typing - one form, no mode to choose
		 * between, which is the difference between a queue moving and not.
		 */
		if ( isset( $fields['billing']['billing_first_name'] ) ) {
			$fields['billing']['billing_first_name']['default'] = self::walkin_name();
		}

		if ( isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['default'] = self::walkin_email();
		}

		return $fields;
	}

	/**
	 * Take the website's consent furniture off the counter.
	 *
	 * Three things, none of which mean anything at a till:
	 *
	 * - the privacy notice and the terms page text, which explain what happens
	 *   to personal information when someone buys from home. A walk-in sale
	 *   records no personal information unless the customer asks for a NIF;
	 * - the "I have read and agree" checkbox, which asks an operator to accept
	 *   the shop's own terms on behalf of a stranger, several hundred times a
	 *   day. A tick that is always ticked is not consent, and pretending it is
	 *   makes the retail one worth less;
	 * - the newsletter opt-in, which is a marketing permission nobody at a
	 *   counter has been asked for.
	 *
	 * Removed only for the till. Retail and trade keep all three - the same
	 * text is a legal requirement there, which is the reason this is scoped
	 * rather than switched off in the settings.
	 */
	public static function strip_consent_chrome(): void {
		if ( ! Session::is_pos() ) {
			return;
		}

		remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20 );
		remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );

		// An empty checkbox text is what WooCommerce reads as "no checkbox"
		// (wc_terms_and_conditions_checkbox_enabled), so nothing is bypassed:
		// the box is not rendered rather than rendered and ignored.
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', '__return_empty_string' );

		if ( class_exists( 'Envio_Checkout_Webhook_Subscribe' ) ) {
			remove_action(
				'woocommerce_review_order_before_submit',
				array( 'Envio_Checkout_Webhook_Subscribe', 'render_optout_checkbox' )
			);
		}
	}

	/**
	 * Remove whichever element carries the acceptance box.
	 *
	 * MATCHED ON WHAT IT CONTAINS, not on its id. Bricks generated
	 * `brxe-3de228` for this one and regenerates such ids whenever the page is
	 * re-saved in the builder, so an id would work until somebody opened the
	 * page and then fail silently - on a checkout, which is the worst place for
	 * a silent failure. The hidden `terms-field` input is the thing that makes
	 * WC_Checkout refuse the order, so that is what is looked for.
	 *
	 * @param mixed $html
	 * @param mixed $element
	 * @return mixed
	 */
	public static function drop_terms_element( $html, $element = null ) {
		if ( ! is_string( $html ) || ! Session::is_pos() ) {
			return $html;
		}

		return false !== strpos( $html, 'name="terms-field"' ) ? '' : $html;
	}

	/**
	 * No terms block at the counter - not a pre-ticked one.
	 *
	 * Ticking it by default and hiding it would record an acceptance nobody
	 * made, on behalf of a customer who never saw it, several hundred times a
	 * day. That is worth less than no record at all and would weaken the real
	 * acceptance the website collects. The requirement simply does not apply
	 * here: the shop's online terms are not what governs a counter sale.
	 *
	 * @param mixed $show
	 */
	public static function no_terms( $show ) {
		return Session::is_pos() ? false : $show;
	}

	/** @param mixed $needs */
	public static function no_shipping( $needs ) {
		return Session::is_pos() ? false : $needs;
	}

	/**
	 * Take the DPD pickup point off the counter. It is a shipping field.
	 *
	 * The customer is holding the goods, so there is nothing to send and
	 * nowhere to send it. The chronopost plugin adds its selector to every
	 * checkout all the same, because it keys on which shipping methods the shop
	 * has enabled rather than on whether this cart needs shipping - so the till
	 * form carried a `validate-required` pickup selector, loaded its scripts,
	 * ran its validation and posted a `cppw_point` on every sale, for orders
	 * with no shipping line at all.
	 *
	 * Removed by function name because the plugin registers plain functions.
	 * If it is ever deactivated these calls simply find nothing to remove.
	 */
	public static function drop_pickup_point_field(): void {
		if ( ! Session::is_pos() ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', 'cppw_wp_enqueue_scripts' );
		remove_action( 'woocommerce_review_order_before_payment', 'cppw_woocommerce_review_order_before_payment' );
		remove_filter( 'woocommerce_update_order_review_fragments', 'cppw_woocommerce_update_order_review_fragments' );
		remove_action( 'woocommerce_after_checkout_validation', 'cppw_woocommerce_after_checkout_validation' );
		remove_action( 'woocommerce_checkout_update_order_meta', 'cppw_save_extra_order_meta' );
	}

	/**
	 * A postcode nobody typed stays a postcode nobody typed.
	 *
	 * THIS IS WHY A COUNTER SALE COULD NOT BE PLACED WITHOUT AN ADDRESS.
	 * WC_Checkout::validate_posted_data() formats the postcode before it checks
	 * whether the field is empty (class-wc-checkout.php:933), and
	 * wc_format_postcode() formats Portugal by inserting a dash at offset 4 -
	 * so '' comes back as '-', which is no longer empty and is not a valid
	 * postcode either. Every sale without an address was refused with "Código
	 * postal de facturação não é um código postal válido", naming a field the
	 * form itself labels "(opcional)".
	 *
	 * Marking the field optional cannot reach this: the check hangs off the
	 * field's `validate` rules, not its `required` flag. Dropping the postcode
	 * rule would reach it, but would also drop the formatting and the real
	 * check from the sales that DO carry an address - and those are the ones
	 * worth getting right, because a mistyped postcode on an invoice is a
	 * genuine problem. So only the impossible value is corrected: a postcode
	 * consisting entirely of separators had nothing in it to format.
	 *
	 * At the till only. Retail and trade require a postcode, so an empty one
	 * there is answered by the required-field error long before this.
	 *
	 * @param mixed $postcode
	 * @param mixed $country
	 * @return mixed
	 */
	public static function blank_postcode_stays_blank( $postcode, $country = '' ) {
		if ( ! is_string( $postcode ) || ! Session::is_pos() ) {
			return $postcode;
		}

		return '' === preg_replace( '/[^A-Za-z0-9]/', '', $postcode ) ? '' : $postcode;
	}

	/**
	 * A sale with no name still gets one.
	 *
	 * NO NIF HANDLING HERE, deliberately. This class used to render its own
	 * "NIF (opcional)" field and keep the answer on the order - and it never
	 * ran: the Bricks checkout has a NIF field of its own, which posts
	 * billing_nif, so the value was already being recorded on a better key than
	 * ours while our field sat beside it collecting nothing. Same lesson as the
	 * terms block: this checkout is the page's, not WooCommerce's, and adding
	 * to it before looking at what it already does produces two mechanisms
	 * where one was working.
	 *
	 * @param mixed $order
	 * @param mixed $data
	 */
	public static function name_the_sale( $order, $data = array() ): void {
		if ( ! Session::is_pos() || ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' === trim( (string) $order->get_billing_first_name() ) ) {
			$order->set_billing_first_name( self::walkin_name() );
		}

		if ( '' === trim( (string) $order->get_billing_email() ) ) {
			$order->set_billing_email( self::walkin_email() );
		}
	}
}
