<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The trade ordering page, at /loja/ on the trade host.
 *
 * A named accessor over Frontend\HostPages, which is what actually serves it
 * there. Everything that styles or drives the ordering surface asks here, so
 * none of it has to know the page's id or where it physically lives.
 */
class OrderingPage {

	/** Its root slug in the HostPages map. */
	public const SLUG = 'loja';

	/** Where the ordering page lives on the trade host. */
	public const PATH = '/loja/';

	/** The configured page, or 0 if it is missing or not published. */
	public static function id(): int {
		return HostPages::id( self::SLUG );
	}

	/**
	 * Whether the request being rendered is the trade ordering page.
	 *
	 * By id, not by path: on the retail host /loja/ resolves to the shop and
	 * must say no, and on the trade host it resolves to this page whichever
	 * path it arrived by.
	 */
	public static function is_ordering_page(): bool {
		$id = self::id();

		return $id > 0 && function_exists( 'is_page' ) && is_page( $id );
	}
}
