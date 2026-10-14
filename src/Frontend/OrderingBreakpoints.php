<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Which widths the ordering surface changes shape at: Bricks' own, and nothing
 * in between - 1279 (desktop), 991 (tablet vertical), 767 (mobile horizontal),
 * 478 (mobile vertical). The column widths are fluid between them.
 *
 * THE 1280 IS NOT A PREFERENCE. Three columns need the filter column (228 at
 * its narrowest before the longest category label wraps), the cart (292 before
 * the EAN starts clipping), the product row's own minimum (664: 40 + 68 + 84 +
 * 264 of fixed columns, four 10px gaps, and 168 for the name), 48px of page
 * padding and two 24px gutters. That is 1280 exactly, which is Bricks' desktop
 * breakpoint to the pixel. Below it the cart cannot stay in the flow, so it
 * becomes the off-canvas panel that the phone layout already uses.
 *
 * The page these replaced, /encomendar/, carried its own set (1050, 1320,
 * 1400). It is gone, and so is the per-request switch that used to pick.
 */
class OrderingBreakpoints {

	private const DESKTOP         = 1279;
	private const TABLET_PORTRAIT = 991;
	private const MOBILE_PORTRAIT = 478;

	/**
	 * The widest screen that gets the off-canvas cart: below 1280 the cart
	 * cannot hold a column without squeezing the product row past its minimum.
	 */
	public static function cart_offcanvas_max(): int {
		return self::DESKTOP;
	}

	/**
	 * The widest screen that folds the filters behind a toggle. Lower than the
	 * cart's: between 992 and 1279 there is room for the filter column beside
	 * the list, and a filter column you can see is worth more than its width.
	 */
	public static function filters_collapse_max(): int {
		return self::TABLET_PORTRAIT;
	}

	/** The narrowest screen that keeps all three columns side by side. */
	public static function three_column_min(): int {
		return self::DESKTOP + 1;
	}

	/** Where the cart line puts SKU and EAN back on separate lines. */
	public static function code_wrap_max(): int {
		return self::MOBILE_PORTRAIT;
	}
}
