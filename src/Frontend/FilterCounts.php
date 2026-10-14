<?php

namespace BeltoftCommerce\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * No counts on the trade filters.
 *
 * A count is a count of PRODUCTS and this list shows ROWS: Frontend\VariationRows
 * gives a variable product one row per orderable variation, so an exact "(7)"
 * sits above nine lines. Bricks also counts a term by the products assigned
 * DIRECTLY to it while filtering with include_children, so a parent showed (1)
 * and returned 36. Both were true of the number; neither was fixable here.
 *
 * Correcting them WAS built and did work on a page render - verified exact on
 * eighteen filters across trade and the till - but not once a filter was
 * active. Bricks builds each option's count from the unfiltered set narrowed by
 * the OTHER active filters, deliberately excluding the element's own selection,
 * and that set is assembled inside the filter element where nothing can reach
 * it. Computing the counts also cost 706ms a page. A number that is right until
 * you touch a filter is worse than no number, so it goes.
 *
 * SWITCHED OFF AT BRICKS' OWN SETTING, not stripped from the markup. An earlier
 * version removed the count spans on `bricks/frontend/render_element`, which
 * only runs on a page render - so the counts came back the moment a filter was
 * clicked, which is what made them look broken rather than absent.
 * `filterHideCount` is read inside get_option_text_with_count(), and both the
 * page render and the filter's REST re-render go through that.
 *
 * Note Bricks tests it with isset(), so the value is irrelevant - setting it at
 * all hides the count.
 *
 * The results summary above the list is deliberately left alone. It counts
 * parent products and is wrong in the same way, and the shop would rather have
 * Bricks' number than none.
 */
class FilterCounts {

	/** The filter elements that print a count beside each option. */
	private const COUNTED = array( 'filter-checkbox', 'filter-radio', 'filter-select' );

	public static function register(): void {
		add_filter( 'bricks/element/settings', array( __CLASS__, 'hide' ), 10, 2 );
	}

	private static function applies(): bool {
		// Exactly where VariationRows expands rows - see expands_rows().
		return VariationRows::expands_rows();
	}

	/**
	 * @param mixed $settings
	 * @param mixed $element
	 * @return mixed
	 */
	public static function hide( $settings, $element = null ) {
		if ( ! is_array( $settings ) || ! is_object( $element ) || ! self::applies() ) {
			return $settings;
		}

		if ( ! in_array( (string) ( $element->name ?? '' ), self::COUNTED, true ) ) {
			return $settings;
		}

		$settings['filterHideCount'] = true;

		return $settings;
	}
}
