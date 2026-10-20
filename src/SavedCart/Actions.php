<?php

namespace BeltoftCommerce\SavedCart;

defined( 'ABSPATH' ) || exit;

/**
 * Save, restore and delete, as ordinary form posts.
 *
 * admin-post rather than REST on purpose: these are three buttons on a page a
 * partner is already looking at, each needs a nonce and a redirect back with
 * something to read, and none of them needs to happen without a page load.
 * Plain forms also keep working if the ordering page's JavaScript does not.
 */
class Actions {

	public const SAVE    = 'beltoft_save_cart';
	public const RESTORE = 'beltoft_restore_cart';
	public const DELETE  = 'beltoft_delete_saved_cart';

	/** Where the result of the last action is kept until it has been shown. */
	private const NOTICE_TRANSIENT = 'beltoft_saved_cart_notice_';

	public static function register(): void {
		foreach ( array( self::SAVE, self::RESTORE, self::DELETE ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle' ) );
		}

		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_b2b_host' ) );
	}

	public static function handle(): void {
		$action  = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$user_id = get_current_user_id();

		if ( ! $user_id || ! check_admin_referer( $action ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		switch ( $action ) {
			case self::SAVE:
				self::do_save( $user_id );
				break;

			case self::RESTORE:
				self::do_restore( $user_id );
				break;

			case self::DELETE:
				self::do_delete( $user_id );
				break;
		}

		wp_safe_redirect( self::back_to() );
		exit;
	}

	private static function do_save( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		$name = isset( $_POST['saved_cart_name'] ) ? sanitize_text_field( wp_unslash( $_POST['saved_cart_name'] ) ) : '';

		self::prepare_cart();

		$result = Repository::save( $user_id, $name );

		if ( is_wp_error( $result ) ) {
			self::notice( $user_id, $result->get_error_message(), 'error' );

			return;
		}

		self::notice(
			$user_id,
			sprintf(
				/* translators: %s: the name the partner gave this order. */
				__( 'Order saved as «%s».', 'beltoft-commerce' ),
				Repository::clean_name( $name )
			),
			'success'
		);
	}

	private static function do_restore( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		$id = isset( $_POST['saved_cart_id'] ) ? absint( $_POST['saved_cart_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		$merge = ! empty( $_POST['saved_cart_merge'] );

		self::prepare_cart();

		$result = Repository::restore( $id, $user_id, ! $merge );

		self::report( $user_id, $result );
	}

	/**
	 * Say what went into the basket, and what did not and why.
	 *
	 * Shared by the saved baskets and by Account\Reorder: both take a list of
	 * intentions from weeks ago and check it against what can be sold today,
	 * and a partner is owed the same account of it either way.
	 *
	 * @param array{added:int, skipped:array<string>} $result
	 */
	public static function report( int $user_id, array $result ): void {
		$added   = (int) ( $result['added'] ?? 0 );
		$skipped = (array) ( $result['skipped'] ?? array() );

		if ( ! $added && ! $skipped ) {
			self::notice( $user_id, __( 'That order could not be opened.', 'beltoft-commerce' ), 'error' );

			return;
		}

		$message = sprintf(
			/* translators: %d: how many lines were put back on the order. */
			_n( '%d line added to the order.', '%d lines added to the order.', $added, 'beltoft-commerce' ),
			$added
		);

		if ( $skipped ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated list of products that could not be added. */
				__( 'Could not be added: %s.', 'beltoft-commerce' ),
				implode( ', ', $skipped )
			);
		}

		self::notice( $user_id, $message, $skipped ? 'notice' : 'success' );
	}

	private static function do_delete( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		$id = isset( $_POST['saved_cart_id'] ) ? absint( $_POST['saved_cart_id'] ) : 0;

		Repository::delete( $id, $user_id );
		self::notice( $user_id, __( 'Saved order deleted.', 'beltoft-commerce' ), 'success' );
	}

	/**
	 * admin-post runs without WooCommerce's cart, the same way REST does.
	 *
	 * Public because Account\Reorder needs the same preparation before it can
	 * put a past order back in the basket.
	 */
	public static function prepare_cart(): void {
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( WC()->cart ) {
			// WC_Cart fills itself on wp_loaded, which has already passed here.
			WC()->cart->get_cart();
		}
	}

	private static function notice( int $user_id, string $message, string $type ): void {
		set_transient( self::NOTICE_TRANSIENT . $user_id, array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
	}

	/** Read once, then gone - a notice should not survive a refresh. */
	public static function take_notice( int $user_id ): ?array {
		$notice = get_transient( self::NOTICE_TRANSIENT . $user_id );

		if ( ! $notice ) {
			return null;
		}

		delete_transient( self::NOTICE_TRANSIENT . $user_id );

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Back where the partner pressed the button.
	 *
	 * wp_safe_redirect() refuses a host it does not know, and the B2B host is a
	 * different one from siteurl, so it is allowed explicitly rather than
	 * letting every one of these land on the retail home page.
	 */
	private static function back_to(): string {
		$referer = wp_get_referer();

		return $referer ?: home_url( '/' );
	}

	/**
	 * wp_safe_redirect() refuses any host it has not been told about, and the
	 * trade site is a different host from the one in siteurl. Without this a
	 * partner pressing Guardar on the B2B ordering page would be redirected to
	 * the retail home page - the save having worked, with nothing to show for
	 * it but the wrong shop.
	 *
	 * @param array $hosts
	 * @return array
	 */
	public static function allow_b2b_host( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}

		if ( class_exists( '\BeltoftCommerce\Support\Hosts' ) ) {
			// Every trade host: one per language.
			$hosts = array_merge( $hosts, \BeltoftCommerce\Support\Hosts::all_b2b_hosts() );
		}

		return array_values( array_unique( array_filter( $hosts ) ) );
	}
}
