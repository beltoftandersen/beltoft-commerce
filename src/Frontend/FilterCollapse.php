<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * On a narrow screen the filters fold away behind a button.
 *
 * Stacked, the filter column is roughly 1300px tall - every category, every
 * brand, every tag - so a phone showed a full screen and a half of controls
 * before the first product. The buyer came to order from a list they already
 * know; filtering is the exception, not the entry point.
 *
 * The search box stays out of the fold, because searching a SKU is the one
 * thing a trade buyer does constantly and it should never be two taps away.
 */
class FilterCollapse {

	/** Matches the stacking breakpoint used by the ordering page. */
	/*
	 * Where the filters fold behind a toggle.
	 * this is a question rather than a constant.
	 */

	public static function register(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host() && QuickOrderPagePathShim::is_ordering_page();
	}

	/**
	 * The CSS, in the head rather than in the footer beside the behaviour.
	 *
	 * Printed on wp_footer it arrived after the whole product list, so the
	 * browser laid the page out once without these rules and again with them.
	 * On a phone that was the single largest layout shift on the ordering page
	 * - the grid went from collapsed to 587px tall in one frame. The markup
	 * these rules style is server-rendered, so they only need to arrive before
	 * it does; the script stays in the footer where it belongs.
	 */
	public static function print_styles(): void {
		if ( ! self::applies() ) {
			return;
		}

		$bp = OrderingBreakpoints::filters_collapse_max();

		?>
<style>
.beltoft-filters-toggle{display:none}
@media (max-width:<?php echo (int) $bp; ?>px){
	.beltoft-filters-toggle{
		display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;
		border:1px solid #264F37;border-radius:0;background:transparent;color:#264F37;
		font:inherit;font-size:14px;font-weight:500;cursor:pointer;flex:0 0 auto;
	}
	.beltoft-filters-toggle[aria-expanded="true"]{background:#264F37;color:#fff}
	.beltoft-filters-toggle__caret{transition:transform .18s}
	.beltoft-filters-toggle[aria-expanded="true"] .beltoft-filters-toggle__caret{transform:rotate(180deg)}
	.beltoft-filters-body{display:none;flex:1 0 100%}
	.beltoft-filters-body.is-open{
		display:flex;flex-direction:column;row-gap:6px;
		margin-top:10px;padding-top:12px;border-top:1px solid #e3e8dd;
	}
}

/*
 * The fold below is applied by JavaScript, which cannot run until the document
 * has been parsed - so the phone painted the whole filter column expanded,
 * about 580px of it, and collapsed it a frame later. That single reflow was
 * 0.46 CLS, the largest on the page by far.
 *
 * These rules put the column in its folded shape before anything is painted.
 * Only the search stays visible, and the ::after reserves exactly the toggle
 * the script is about to append - 42px tall on its own wrapped row, matching
 * the measured 228x94 column - so when the class comes off and the real button
 * takes its place, nothing moves.
 *
 * The class is set by a script rather than by the media query alone on purpose:
 * with JavaScript unavailable the class is never added, the filters stay where
 * the server rendered them, and the page still works.
 */
@media (max-width:<?php echo (int) $bp; ?>px){
	html.beltoft-filters-prefold #brxe-707f1c > *:not(#brxe-25deef):not(.beltoft-filters-keep){display:none}
	html.beltoft-filters-prefold #brxe-707f1c::after{
		content:"";display:block;width:91px;height:42px;
	}
}
/* A wider filter column on full-size screens.
   The page's own customCss sets grid-template-columns on this container at
   three tiers (292/255/228px for the filters). Only the widest one is touched
   here - below 1400px the middle column is already the tightest thing on the
   page, so taking another 48px from it there would cost more than the filters
   gain. The extra width comes out of the flexible middle column, not the cart.
   This lives here rather than in the page CSS because our styles print after
   Bricks' generated post stylesheet, so the same selector wins on order, and
   because a value in the repo survives the next builder save. */
/* Let each filter group fill the column.
   #brxe-707f1c is a column flex container with align-items:flex-start, so its
   children shrink to their content - each checkbox group sat at 196px inside a
   340px column. bricks-child already pushes the [+] toggle with margin-left:auto,
   so it was landing at the end of a short box rather than at the edge of the
   column. Stretching the groups is what moves it right; the toggle rule itself
   is the theme's and is shared with retail, so it is left alone. */
#brxe-707f1c .brxe-filter-checkbox{align-self:stretch}

/* NOTE: no CSS placeholder for the [+] markers.
   bricks-child's custom-bricks-filters.js appends them on DOMContentLoaded, so
   on a slow connection they arrive after the filter list has already painted.
   A placeholder drawn with the CSS relational selector was tried and removed;
   that selector is not used in this codebase.
   It is a flicker, not a layout shift, and not worth another mechanism: the
   markers sit in a flex row and change no heights. Measured on this page, CLS
   is 0.0020 unthrottled and 0.0000 at 4x CPU, with ZERO shifts inside the
   filter column in either case. */

/* Use the height that is actually there.
   Both sticky columns are capped at calc(100vh - 140px) by the page CSS while
   sitting at top:16px, so they stop 124px short of the bottom of the screen.
   Measured with nine lines in the cart: the column filled its 860px cap and
   the item list was showing 405px of 1132px, scrolling the rest through a
   small window, with 124px of empty screen underneath it.
   16px above, 16px below: the same breathing room at both ends, and about
	   110px more list on a 1000px screen. Below 992px the page already drops the
   cap and the columns stack, so this only touches the side-by-side layout. */
@media (min-width:<?php echo (int) OrderingBreakpoints::three_column_min(); ?>px){
	#brxe-586888.brxe-block{max-height:calc(100vh - 32px)}
	/* The filter column scrolls with the page; only the cart is pinned.
	   Both used to be sticky at top:16px. The cart earns it - it is what the
	   buyer is building and it has to stay reachable from anywhere in a 480
	   product list. The filters are something you set once and then stop
	   looking at, and pinning them put a second scroll context under the
	   cursor: a capped column with its own scrollbar sitting beside the page's,
	   so a wheel gesture over the left third of the screen did something
	   different from the same gesture over the middle.
	   The height cap goes with it. That cap exists to keep a sticky column
	   inside the viewport; a column that scrolls with the page wants its full
	   height and no inner scrollbar at all. */
	/* ...and it stops being a scroll container at all, which is the part that
	   bit. The page CSS gives it overflow-y:auto with overscroll-behavior:
	   contain - right when it was a pinned column with its own scrollbar,
	   because a wheel gesture inside it should not chain out to the page. With
	   max-height gone it has nothing left to scroll, so `contain` had nothing
	   to contain and simply swallowed the gesture: the page would not scroll
	   while the pointer was anywhere over the filters.
	   The scrollbar gutter and its compensating inset are gone; the filters use
	   their full column width. */
	#brxe-707f1c.brxe-block{position:static;max-height:none;overflow:visible;overscroll-behavior:auto}
	/* The cart panel gets its whole track, so its CONTENTS match the filters'.
	   Both tracks are 360px, but only the cart is a painted panel and it keeps
	   12px a side inside the tint. With the panel also inset 24px from its
	   track, like-for-like content came to 336px on the left against 310px on
	   the right, and the filter column read as the wider one - which it was,
	   where it counts.
	   The 24px inset that came off was there to make the GAPS symmetric, 48px
	   either side of the list. That was the wrong thing to match: the filter
	   column has no background, so what the eye compares on the left is the
	   list against text, while on the right it compares the list against a
	   tinted edge. Matching the contents is what makes the two columns look
	   like the same size; the tint marks its own boundary and does not need
	   the extra 24px to be legible.
	   Zeroed rather than removed because the page CSS is what sets it. */
	#brxe-586888.brxe-block{padding-left:0}
	/* A product row that cannot fit its column wraps instead of overflowing.
	   The row is a nowrap flex row whose quantity-and-button cell carries
	   min-width:264px, so below about 680px of column it overflows to the
	   right - out of the list and underneath the cart, where ADICIONAR was
	   clipped mid-word. Above 1401px the grid now guarantees that 680px, but
	   the page's own CSS hands the middle column 484px at a 1100px viewport
	   and 584px at 1200px, and no amount of column juggling fixes that: giving
	   the list 680px there would squeeze the filters to about 170px.
	   So the row is allowed to wrap. It only does so when it genuinely cannot
	   fit, which is why this is not scoped to those widths - at 1280 and above
	   nothing moves. The quantity and the button drop to a second line under
	   the name, which is the same shape the mobile layout already uses.
	   Hooked on data-product-id: it is on every row, it says what the element
	   IS, and unlike brxe-55c3c9 it survives the page being re-saved. */

}

/* A product row that cannot fit its column wraps instead of overflowing.
   The row is a nowrap flex row whose quantity-and-button cell carries
   min-width:264px, so it needs about 680px. Above 1401px the grid now
   guarantees that, but the page's own CSS hands the middle column 484px at a
   1100px viewport and 584px at 1200px - and no column juggling fixes that:
   giving the list 680px there would squeeze the filters to about 170px. So in
   that band the row wraps, and the quantity and button drop to a second line
   under the name, the same shape the mobile layout already uses.

   SCOPED TO THE BAND, and measured rather than assumed. Applied unscoped -
   on the theory that flex only wraps when it must - it wrapped at every width:
   rows went from 105px to 161px and the button left the product line at 1600px
   where it had fitted perfectly well. Flex wraps on the items' min-content
   widths, which are reached long before the row would actually overflow.
   1280px is the first width measured clean (664px of column); below it, 584px
   and 484px both overflowed.

   Hooked on data-product-id: it is on every row, it says what the element IS,
   and unlike brxe-55c3c9 it survives the page being re-saved. */
@media (min-width:<?php echo (int) OrderingBreakpoints::filters_collapse_max() + 1; ?>px) and (max-width:<?php echo (int) OrderingBreakpoints::cart_offcanvas_max(); ?>px){
	#brxe-c14407 [data-product-id]{flex-wrap:wrap}
}

/* The sort control keeps its place on the right.
   Its row is `justify-content:space-between` and holds two children - the
   results summary on the left, the sort on the right. While the summary was
   being removed, the sort was the only child and space-between had nothing to
   space, so it fell to the left of a very wide row.
   margin-left:auto rather than justify-content:flex-end on the parent: it
   holds whether the row has one child or two, so putting the summary back does
   not silently move this again.
   No generated ids - this stylesheet only prints on the ordering page, and the
   element-type class is what survives a re-save. */
.brxe-woocommerce-products-orderby{margin-left:auto}

/* An active filter should look like something you can take off.
   Bricks already renders each one as a real <button> carrying
   aria-label="Limpar filtro: X", so clicking it removes that filter - it just
   reads as a list of words, and nobody clicks a word. The behaviour was there
   and only the affordance was missing, which is why this is presentation and
   not a new control: no JavaScript, and the accessible name Bricks wrote is
   left exactly as it is.
   The x is generated content, so it stays out of the accessible name (which
   already says "Limpar filtro") and out of anything copied from the page. */
.brxe-filter-active-filters li{display:inline-flex}
.brxe-filter-active-filters button{
	display:inline-flex;align-items:center;gap:8px;
	padding:4px 10px;border:1px solid #cfd8c8;border-radius:999px;
	background:#f4f6f1;color:#264F37;font:inherit;font-size:13px;line-height:1.4;cursor:pointer;
}
.brxe-filter-active-filters button::after{
	content:"\00d7";font-size:15px;line-height:1;color:#6b7a63;
}
.brxe-filter-active-filters button:hover,
.brxe-filter-active-filters button:focus-visible{background:#e7ece1;border-color:#264F37}
.brxe-filter-active-filters button:hover::after,
.brxe-filter-active-filters button:focus-visible::after{color:#264F37}

</style>
<script>
(function(){
	try{
		if(window.matchMedia('(max-width:<?php echo (int) $bp; ?>px)').matches){
			document.documentElement.classList.add('beltoft-filters-prefold');
		}
	}catch(e){}
})();
</script>
		<?php
	}

	public static function render(): void {
		if ( ! self::applies() ) {
			return;
		}

		$label = esc_html__( 'Filtros', 'beltoft-commerce' );
		$bp    = OrderingBreakpoints::filters_collapse_max();
		?>
<script>
(function(){
	var col=document.getElementById('brxe-707f1c');
	if(!col)return;
	var search=document.getElementById('brxe-25deef');
	var mq=window.matchMedia('(max-width:<?php echo (int) $bp; ?>px)');

	/*
	 * Everything except the search goes in the fold. Done in JS rather than as
	 * a Bricks wrapper because on desktop the column must stay exactly as it
	 * is - a plain stack with no extra element between the filters.
	 */
	var body=document.createElement('div');
	body.className='beltoft-filters-body';
	body.id='beltoft-filters-body';

	var toggle=document.createElement('button');
	toggle.type='button';
	toggle.className='beltoft-filters-toggle';
	toggle.setAttribute('aria-expanded','false');
	toggle.setAttribute('aria-controls','beltoft-filters-body');
	toggle.innerHTML='<span><?php echo $label; ?></span><span class="beltoft-filters-toggle__caret" aria-hidden="true">▾</span>';

	var moved=false;
	function fold(){
		if(moved||!mq.matches)return;
		var kids=[].slice.call(col.children);
		kids.forEach(function(el){
			if(el===search||el===toggle||el===body)return;
			/*
			 * Anything marked keep stays beside the search instead of going in
			 * the fold. The scan button uses this: it is a way of finding a
			 * product, like the search box, and burying it behind "Filtros"
			 * would put the one control a phone is here for two taps away.
			 */
			if(el.classList.contains('beltoft-filters-keep'))return;
			body.appendChild(el);
		});
		col.appendChild(toggle);
		col.appendChild(body);
		moved=true;
	}
	function unfold(){
		if(!moved||mq.matches)return;
		while(body.firstChild)col.insertBefore(body.firstChild,toggle);
		toggle.remove();body.remove();
		body.classList.remove('is-open');
		moved=false;
	}
	toggle.addEventListener('click',function(){
		var open=body.classList.toggle('is-open');
		toggle.setAttribute('aria-expanded',open?'true':'false');
	});

	fold();
	/*
	 * The placeholder has done its job: the real toggle is in the document and
	 * occupies the space it was holding, so swapping them costs no movement.
	 */
	document.documentElement.classList.remove('beltoft-filters-prefold');
	// Rebuilt on resize so a rotated tablet does not end up with the desktop
	// column inside a collapsed panel, or the reverse.
	mq.addEventListener('change',function(){ if(mq.matches){fold();}else{unfold();} });
})();
</script>
		<?php
	}
}
