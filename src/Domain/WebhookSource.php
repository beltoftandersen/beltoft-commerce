<?php

namespace BeltoftCommerce\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A webhook says which shop it came from, and there is only one shop.
 *
 * `X-WC-Webhook-Source` is how the receiving end identifies the sender, and
 * WooCommerce builds it from home_url() at the moment of delivery. That made it
 * a property of whichever request happened to deliver the webhook rather than
 * of the shop: deliveries drained by a queue runner spawned from the trade host
 * announced themselves as the trade host, and the Odoo endpoint answered every
 * one of those with 400 Bad Request - 18 rejected against 73 accepted, a clean
 * split with no exceptions either way.
 *
 * UrlFilter's queue suspension is what stops that happening, and this is the
 * guarantee underneath it: the header is pinned whatever context delivers the
 * webhook, including a synchronous delivery, which no queue hook would cover.
 *
 * WHY NOT `woocommerce_webhook_http_args`, which exists for exactly this.
 * WooCommerce applies that filter and then writes the source header over the
 * top of whatever it returned (class-wc-webhook.php:355). So the header has to
 * be set later than WooCommerce sets it, and `http_request_args` - WordPress's
 * own filter, run as the request leaves - is the first hook that qualifies.
 *
 * The signature is generated from the body alone (class-wc-webhook.php:360), so
 * rewriting a header cannot invalidate it.
 *
 * The trade hostname is a presentation detail for trade sessions. It is not a
 * second store, and it must never introduce itself as one.
 */
class WebhookSource {

	private const HEADER = 'X-WC-Webhook-Source';

	public static function register(): void {
		add_filter( 'http_request_args', array( __CLASS__, 'pin' ), 10, 2 );
	}

	/**
	 * @param mixed $args
	 * @param mixed $url
	 * @return mixed
	 */
	public static function pin( $args, $url = '' ) {
		// Every outgoing HTTP request passes through here. The header is what
		// identifies this as a webhook delivery, so nothing else is touched.
		if ( ! is_array( $args ) || empty( $args['headers'][ self::HEADER ] ) ) {
			return $args;
		}

		$args['headers'][ self::HEADER ] = UrlFilter::canonical();

		return $args;
	}
}
