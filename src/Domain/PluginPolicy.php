<?php

namespace BeltoftCommerce\Domain;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins that have no business running on the trade host do not run there.
 *
 * Not a performance measure - it is worth about 15ms, which is nothing. It is a
 * correctness one. A payment gateway the host refuses to offer, a social login
 * SDK on a page behind a password, a wishlist that was deliberately taken off
 * this host: each of those is code with no purpose here, and code with no
 * purpose is code nobody is checking. The Facebook SDK in particular was being
 * handed every trade page view.
 *
 * WHERE THIS APPLIES, AND WHERE IT MUST NOT. Front-end requests to the trade
 * host, and nothing else. wp-admin, cron, REST and admin-ajax.php keep the full
 * set, because that is where these plugins do their real work: follow-up emails are
 * sent on cron, an order placed through checkout fires hooks a gateway may
 * still need to see, and an administrator editing a product on this host must
 * have every plugin that owns a field on that screen. Unloading a plugin from
 * the request that saves data is how data gets lost.
 *
 * WooCommerce's own ?wc-ajax= requests (fragments, add to cart, the trade
 * checkout) are front-end requests to this host and DO get the short list:
 * DOING_AJAX is defined for them only on init, long after this has run. None
 * of the plugins below takes part in a trade checkout - payment is bank
 * transfer and points are retail-only - so that is the intended reading.
 *
 * WHY THE LIST IS SHORT AND NAMED. Every entry is a plugin checked against what
 * the trade site does, not a guess:
 *
 * - super-socializer: sharing buttons and social login. There is no share
 *   button on a trade order list and nobody signs in here with Facebook.
 * - woo-smart-wishlist: the wishlist was already removed from this host.
 * - wpc-composite-products: the trade catalogue is 189 simple and 42 variable
 *   products. There is not one composite product in it.
 * - pymntpl-paypal-woocommerce and multibanco-ifthen-...: trade pays by bank
 *   transfer and nothing else - Checkout\B2BPaymentMethods enforces it - so
 *   these gateways are loaded on every request to be filtered out of every
 *   checkout.
 * - beltoft-loyalty-rewards and -pro: points are a retail scheme.
 *   Account\MenuItems already takes the endpoint off the trade account.
 *
 * - duracelltomi-google-tag-manager and pressidium-cookie-consent: dropped
 *   TOGETHER, and the order matters more than the saving.
 *
 *   The trade host is behind a login and sells to named partners; there is no
 *   audience to measure and no campaign to attribute, so the tag manager has
 *   nothing to do there. The consent banner exists to gate exactly that kind of
 *   tracking, so once the tracking is gone it is asking permission for nothing
 *   and costs every partner a click on every device.
 *
 *   Dropping the banner ALONE would have been the wrong half. Checked before
 *   removing either: GTM was loading unconditionally on the trade host - not a
 *   single script was parked as type="text/plain" awaiting consent - so taking
 *   the banner away on its own would have left the tracking running with
 *   nothing in front of it. Neither goes without the other; if the tag manager
 *   is ever wanted back here, the banner comes back with it.
 *
 * ONE KNOWN INTERACTION. beltoft-gift-cards checks class_exists() for the
 * loyalty plugin and hooks wclr_max_redeemable_points when it finds it. With
 * loyalty unloaded here that integration is simply absent on the trade host,
 * which is the intended outcome - trade has no points to redeem - but it is a
 * silent difference, so it is written down.
 */
class PluginPolicy {

	/**
	 * Plugin directory names, matched against the start of the plugin file:
	 * Config 'unload_on_trade'. mindthetrash's list, measured before each was
	 * added: woocommerce-subscriptions (no subscription products on trade),
	 * meta-box (its field groups appear nowhere on the trade pages),
	 * beltoft-gift-cards (a consumer product; no trade redemptions), and
	 * woo-cancel-abandoned-order (no gateway on the trade host it can act on;
	 * its job runs on cron, which keeps every plugin). If trade ever sells a
	 * gift card or a subscription, take its line out first: the product type
	 * does not exist on this host until its plugin loads.
	 *
	 * @return string[]
	 */
	private static function unused_on_b2b(): array {
		return array_values( array_filter( array_map( 'strval', (array) Config::get( 'unload_on_trade' ) ) ) );
	}

	public static function register(): void {
		add_filter( 'option_active_plugins', array( __CLASS__, 'filter' ), 1 );
	}

	/**
	 * @param mixed $plugins
	 * @return mixed
	 */
	public static function filter( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::applies() ) {
			return $plugins;
		}

		$kept = array_values( array_filter( $plugins, static function ( $plugin ) {
			foreach ( self::unused_on_b2b() as $unused ) {
				if ( 0 === strpos( (string) $plugin, $unused ) ) {
					return false;
				}
			}

			return true;
		} ) );

		/**
		 * Lets a site override the policy without editing it - and lets it be
		 * turned off entirely from wp-config while something is diagnosed.
		 *
		 * @param array $kept    Plugins that will load.
		 * @param array $plugins Plugins that would have loaded.
		 */
		return (array) apply_filters( 'beltoft_commerce_b2b_active_plugins', $kept, $plugins );
	}

	/**
	 * Front-end trade requests only.
	 *
	 * This runs before WordPress has loaded a plugin, so none of the usual
	 * conditionals exist yet - is_admin(), wp_doing_ajax() and REST_REQUEST are
	 * all unavailable or unreliable this early. The checks are therefore made
	 * against the request itself, which is the only thing that does exist.
	 */
	private static function applies(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return false;
		}

		if ( ! Hosts::is_b2b_host() ) {
			return false;
		}

		$path = (string) ( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/' );

		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-cron.php', '/wp-json', '/xmlrpc.php' ) as $reserved ) {
			if ( 0 === strpos( $path, $reserved ) ) {
				return false;
			}
		}

		// admin-ajax.php lives under /wp-admin and is caught above; this covers
		// the cart fragment calls that arrive before DOING_AJAX is defined.
		return false === strpos( $path, 'admin-ajax.php' );
	}
}
