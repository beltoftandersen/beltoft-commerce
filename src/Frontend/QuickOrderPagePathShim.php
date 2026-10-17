<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The ordering page's path, in one place.
 *
 * Kept as the name every caller already uses; the answers come from
 * Frontend\OrderingPage, which owns the page (Config 'ordering_page').
 */
class QuickOrderPagePathShim {

	/** Where to send someone who should be on the ordering page. */
	public static function ordering_path(): string {
		return OrderingPage::path();
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
		return array( OrderingPage::path() );
	}

	/** Whether the current request is rendering the ordering surface. */
	public static function is_ordering_page(): bool {
		return OrderingPage::is_ordering_page();
	}
}
