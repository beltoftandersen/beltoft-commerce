<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The ordering page's path, in one place.
 *
 * Kept as the name every caller already uses; the answers come from
 * Frontend\OrderingPage, which owns the page. Domain\HostRoutes still holds
 * the OLD path as a literal, because it is loaded by the mu-plugin before any
 * plugin and cannot reference either class - that literal is what keeps
 * /encomendar/ on the trade host so the forward from it can fire.
 */
class QuickOrderPagePathShim {

	/** Where to send someone who should be on the ordering page. */
	public static function ordering_path(): string {
		return OrderingPage::PATH;
	}

	/**
	 * Every path that renders the ordering surface.
	 *
	 * One, now. The legacy paths forward rather than render, so they are not
	 * listed here: anything asking "is this the ordering page" must answer for
	 * a page that is actually being rendered.
	 *
	 * @return array<int, string>
	 */
	public static function ordering_paths(): array {
		return array( OrderingPage::PATH );
	}

	/** Whether the current request is rendering the ordering surface. */
	public static function is_ordering_page(): bool {
		return OrderingPage::is_ordering_page();
	}
}
