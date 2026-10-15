<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A quantity field next to add-to-cart in the B2B product list.
 *
 * WHY THIS IS NOT A BRICKS SETTING. Bricks' product-add-to-cart element asks
 * whether it is inside a query loop and renders accordingly
 * (`includes/woocommerce/elements/product-add-to-cart.php:1482`): looping calls
 * woocommerce_template_loop_add_to_cart(), which is a link carrying
 * data-quantity="1", and only the non-looping branch calls
 * woocommerce_template_single_add_to_cart(), which is the form with a quantity
 * input. There is no control to switch that, so the field has to come from the
 * WooCommerce side. Everything else on the ordering page is a native Bricks
 * element.
 *
 * WHY IT IS GATED ON THE HOST. A wholesale buyer orders twelve of something;
 * a retail visitor orders one, and Stage 1 acceptance is that retail guest
 * output is byte-identical (bin/guest-parity.sh). Gating on the host keeps this
 * off every retail render rather than relying on a page check that a future
 * template could quietly bypass.
 *
 * WHY ONLY SIMPLE PRODUCTS. A variable product's loop button is a link to the
 * product page because the variation is not chosen yet; attaching a quantity to
 * it would promise an add-to-cart that cannot happen.
 */
class LoopQuantity {

	public static function register(): void {
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'prepend_quantity' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ) );
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host();
	}

	/**
	 * @param string      $html    The add-to-cart markup WooCommerce built.
	 * @param \WC_Product $product The looped product.
	 * @return string
	 */
	public static function prepend_quantity( $html, $product = null ): string {
		if ( ! self::applies() || ! $product instanceof \WC_Product ) {
			return $html;
		}

		/*
		 * Out of stock still gets the full control, just inert. Collapsing the
		 * row to a bare link made every sold-out line a different shape, so the
		 * column stopped scanning as a column and the eye caught on the gap
		 * rather than on the word that matters.
		 */
		if ( ! $product->is_in_stock() ) {
			return self::sold_out( $product );
		}

		/*
		 * A variable product's loop button is a link to the product page,
		 * because WooCommerce cannot know which variation is wanted. For a
		 * reorder list that is a dead end: the buyer leaves the list, chooses,
		 * comes back and finds their place again, twice per line. Instead the
		 * variations are offered inline and the chosen one is added directly.
		 *
		 * CHECKED BEFORE is_purchasable(), and that order is the whole reason
		 * this works. WC_Product_Variable::is_purchasable() asks about the
		 * PARENT, which under group pricing has no price of its own, so every
		 * variable product answers false and would be skipped here. Whether a
		 * variable product can be bought is a question about its children -
		 * which is exactly what CatalogAudience::can_purchase() does too, and
		 * what variable_controls() re-checks per variation.
		 */
		/*
		 * A variable PARENT only still has a row when Frontend\VariationRows
		 * found nothing in it this buyer may order - it could not expand the
		 * parent into variation rows. Woo's own "choose an option" link is the
		 * honest control there, so leave it alone.
		 */
		if ( $product->is_type( 'variable' ) ) {
			return $html;
		}

		if ( ! $product->is_purchasable() ) {
			return $html;
		}

		/*
		 * A variation row is a product row like any other, except that Woo's
		 * loop link for a variation points at the parent page. Swap in the same
		 * stepper plus a button that posts the VARIATION id, which is what
		 * WC_AJAX::add_to_cart actually wants (class-wc-ajax.php:525).
		 */
		if ( $product->is_type( 'variation' ) ) {
			return self::line_control( $product );
		}

		if ( ! $product->is_type( 'simple' ) ) {
			return $html;
		}

		$min  = 1;
		$step = 1;
		$max  = $product->managing_stock() && ! $product->backorders_allowed()
			? (int) $product->get_stock_quantity()
			: '';

		/*
		 * A stepper, not a bare number field. A wholesale buyer orders in
		 * multiples and adjusts a line two or three times while scanning; a
		 * spin control the browser draws at four pixels wide is not a target
		 * anyone hits on the first try, and on touch it does not exist at all.
		 * The input stays a real number input, so typing 24 is still faster
		 * than pressing + twenty-four times.
		 */
		$field = sprintf(
			'<div class="beltoft-loop-qty">' .
			'<label class="screen-reader-text" for="beltoft-qty-%1$d">%2$s</label>' .
			'<button type="button" class="beltoft-loop-qty__step" data-step="-1" aria-label="%6$s" tabindex="-1">&minus;</button>' .
			'<input type="number" id="beltoft-qty-%1$d" class="beltoft-loop-qty__input" value="%3$d" min="%3$d" step="%4$d"%5$s inputmode="numeric">' .
			'<button type="button" class="beltoft-loop-qty__step" data-step="1" aria-label="%7$s" tabindex="-1">+</button>' .
			'</div>',
			(int) $product->get_id(),
			esc_attr__( 'Quantity', 'beltoft-commerce' ),
			$min,
			$step,
			'' !== $max ? ' max="' . (int) $max . '"' : '',
			esc_attr__( 'Decrease quantity', 'beltoft-commerce' ),
			esc_attr__( 'Increase quantity', 'beltoft-commerce' )
		);

		return $field . $html;
	}

	/**
	 * Select a variation, set a quantity, add it - without leaving the row.
	 *
	 * ONLY VARIATIONS THE VIEWER CAN ACTUALLY BUY are listed. `is_purchasable()`
	 * on a variation runs the same fail-closed group-price rule as everything
	 * else, so a variation priced for retail and not for this buyer's group
	 * never appears in their dropdown - the list cannot offer something the
	 * cart would then refuse.
	 *
	 * A single purchasable variation renders no dropdown at all: choosing from
	 * a list of one is a question with no information in it.
	 */
	/**
	 * The same shape as an orderable line, with nothing to operate.
	 *
	 * `aria-disabled` and `tabindex="-1"` rather than a `disabled` attribute:
	 * it is an anchor, so `disabled` means nothing to it, and a screen reader
	 * should still be able to read what the line says.
	 */
	private static function sold_out( \WC_Product $product ): string {
		return sprintf(
			'<div class="beltoft-loop-variable beltoft-loop-variable--sold-out">' .
			'<div class="beltoft-loop-qty" aria-hidden="true">' .
			'<span class="beltoft-loop-qty__step" aria-hidden="true">&minus;</span>' .
			'<input type="number" class="beltoft-loop-qty__input" value="1" disabled>' .
			'<span class="beltoft-loop-qty__step" aria-hidden="true">+</span>' .
			'</div>' .
			'<span class="button beltoft-loop-variable__add beltoft-loop-variable__add--sold-out" aria-disabled="true" tabindex="-1">%s</span>' .
			'</div>',
			esc_html__( 'Sold out', 'beltoft-commerce' )
		);
	}

	/**
	 * Stepper plus add button for one orderable product, posting its own id.
	 *
	 * An <a> carrying data-product_id, not a <button>. Bricks styles loop
	 * add-to-cart buttons through `a.button[data-product_id]`, so a <button>
	 * matched none of it and variation rows rendered an unstyled control next
	 * to properly themed ones. The classes Woo's and Bricks' own scripts bind
	 * to - add_to_cart_button, ajax_add_to_cart, brx_ajax_add_to_cart - are
	 * deliberately NOT used: this control is driven by the handler in
	 * print_script(), and carrying them would have two scripts adding the same
	 * line twice.
	 */
	private static function line_control( \WC_Product $product ): string {
		return sprintf(
			'<div class="beltoft-loop-variable" data-product="%1$d">' .
			'<input type="hidden" class="beltoft-loop-variation" value="%1$d">' .
			'%2$s<a href="%3$s" class="button beltoft-loop-variable__add" data-product_id="%1$d" data-quantity="1" rel="nofollow">%4$s</a></div>',
			(int) $product->get_id(),
			self::quantity_field( $product ),
			esc_url( $product->add_to_cart_url() ),
			// Woo's own label, so a variation row and a simple row do not
			// offer the same action under two different words.
			esc_html( $product->add_to_cart_text() )
		);
	}

	/**
	 * The list's ordering control for one product outside the list - the quick
	 * view - as the {beltoft_buy} dynamic tag.
	 *
	 * Bricks' add-to-cart element renders the single-product form there, which
	 * is a different design and, for a variation, nothing at all (Woo has no
	 * `woocommerce_variation_add_to_cart` template). The quick view is opened
	 * from a list row, so it offers exactly the control that row has: the same
	 * stepper, the same button, the same stock cap and the same handler, which
	 * works for a simple product as well as a variation because
	 * WC_AJAX::add_to_cart takes either id. A variable parent gets nothing - it
	 * has no single thing to add.
	 */
	public static function buy_control( \WC_Product $product ): string {
		if ( ! self::applies() || $product->is_type( 'variable' ) ) {
			return '';
		}

		if ( ! $product->is_in_stock() ) {
			return self::sold_out( $product );
		}

		if ( ! $product->is_purchasable() || ! $product->is_type( array( 'simple', 'variation' ) ) ) {
			return '';
		}

		return self::line_control( $product );
	}

	/**
	 * The stepper markup, shared by the simple and variation paths so the two
	 * cannot drift into looking like different controls.
	 */
	private static function quantity_field( \WC_Product $product ): string {
		// Stock is a property of the VARIATION, not the parent, so a variable
		// product's per-line cap has to come from the line's own product.
		$max = $product->managing_stock() && ! $product->backorders_allowed()
			? (int) $product->get_stock_quantity()
			: '';

		return sprintf(
			'<div class="beltoft-loop-qty">' .
			'<label class="screen-reader-text" for="beltoft-qty-%1$d">%2$s</label>' .
			'<button type="button" class="beltoft-loop-qty__step" data-step="-1" aria-label="%4$s" tabindex="-1">&minus;</button>' .
			'<input type="number" id="beltoft-qty-%1$d" class="beltoft-loop-qty__input" value="1" min="1" step="1"%3$s inputmode="numeric">' .
			'<button type="button" class="beltoft-loop-qty__step" data-step="1" aria-label="%5$s" tabindex="-1">+</button>' .
			'</div>',
			(int) $product->get_id(),
			esc_attr__( 'Quantity', 'beltoft-commerce' ),
			'' !== $max ? ' max="' . (int) $max . '"' : '',
			esc_attr__( 'Decrease quantity', 'beltoft-commerce' ),
			esc_attr__( 'Increase quantity', 'beltoft-commerce' )
		);
	}

	/**
	 * Carry the typed quantity into the button WooCommerce already wired up.
	 *
	 * WooCommerce's AJAX add-to-cart reads data-quantity off the button, so the
	 * whole job is to keep that attribute in step with the input. Doing it on
	 * input rather than on click means the value is already correct whichever
	 * way the button is activated - mouse, keyboard or an Enter press that
	 * follows the browser's own form handling.
	 */
	/**
	 * The control's CSS, in the head rather than beside the script.
	 *
	 * This used to be printed on wp_footer together with the behaviour, which
	 * put it after every product row in the document. The browser painted the
	 * rows with an unstyled quantity control - a full-width number input that
	 * pushed the add-to-cart button onto a second line - and collapsed them to
	 * a 32px row only once it reached the footer. Four hundred rows' worth of
	 * that was the last of the ordering page's layout shift, worth about 0.05
	 * CLS on its own. The markup is server-rendered, so the styles only have to
	 * arrive before it does.
	 */
	public static function print_styles(): void {
		if ( ! self::applies() ) {
			return;
		}
		?>
<style>
/* Matched to the inline cart's quantity control (Bricks'
   brx-number-layout--inline), measured in the browser: square corners, a 32px
   row, 32px steppers outlined in #dddedf, and a white input. The two controls
   sit a few hundred pixels apart on the same screen and doing the same job, so
   they should not be two different designs. The input is narrower than the
   cart's 80px only because the list column has a button beside it. */
.beltoft-loop-qty{
	display:inline-flex;align-items:stretch;height:32px;background:transparent;
}
.beltoft-loop-qty__step{
	width:32px;flex:0 0 32px;height:32px;padding:0;
	border:1px solid #dddedf;background:transparent;color:#264F37;
	font:inherit;font-size:15px;line-height:1;cursor:pointer;
	display:flex;align-items:center;justify-content:center;
	transition:background-color .12s,border-color .12s;
}
.beltoft-loop-qty__step:hover{background:#f2f5ee;border-color:#cfd8c4}
.beltoft-loop-qty__step:active{background:#e3e8dd}
.beltoft-loop-qty__input{
	width:56px;flex:0 0 56px;height:32px;padding:0;
	border:1px solid #dddedf;border-left:0;border-right:0;background:#fff;
	font:inherit;font-size:15px;font-variant-numeric:tabular-nums;
	text-align:center;-moz-appearance:textfield;color:#1f2a23;
}
.beltoft-loop-qty__input:focus{outline:2px solid #264F37;outline-offset:-2px}
/* The native spinner is redundant next to the buttons and steals width. */
.beltoft-loop-qty__input::-webkit-outer-spin-button,
.beltoft-loop-qty__input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}

/* Simple products: stepper then Woo's own button, on one baseline. */
.brxe-product-add-to-cart{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.beltoft-loop-qty{margin-right:0}

/* A variation row carries the same control as a simple product's row, so the
   column reads as one list of orderable lines. */
.beltoft-loop-variable{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
/* Matched to Woo's own loop button, measured in the browser rather than
   guessed: Bricks serves its CSS from the retail host, so it is cross-origin
   to this page and the rules that style a.button[data-product_id] cannot be
   inherited or even enumerated here. These are those computed values. */
.beltoft-loop-variable__add{
	white-space:nowrap;
	background:#264F37;color:#fff;
	padding:7px 20px;font-size:14px;line-height:23.8px;
	text-transform:uppercase;text-decoration:none;border:0;
}
.beltoft-loop-variable__add:hover,
.beltoft-loop-variable__add:focus{background:#1d3d2a;color:#fff}

/* Sold out: same geometry, drained of colour, nothing to click. */
.beltoft-loop-variable--sold-out .beltoft-loop-qty{opacity:.45}
.beltoft-loop-variable--sold-out .beltoft-loop-qty__step{cursor:default;color:#8a8f8b}
.beltoft-loop-variable__add--sold-out,
.beltoft-loop-variable__add--sold-out:hover{
	background:#eceeea;color:#8a8f8b;cursor:default;pointer-events:none;
}
.beltoft-loop-variable__add[disabled],.beltoft-loop-variable__add.is-busy{opacity:.6;pointer-events:none}

/* The label changes twice while a line is added: Woo's own script first, then
   Bricks swaps it to "Adicionado" and appends a "Ver carrinho" link on the
   added_to_cart event. Both are useful signals and neither should move the
   row. The width is reserved for the LONGEST state, so every label fits the
   same box, and the in-flight state is a spinner rather than a resize. */
/* Bricks styles these as `.brxe-<parent> .brxe-<id>.brxe-product-add-to-cart
   a.button[data-product_id]`, so matching that shape is what it takes to
   settle the height; a plainer selector loses and the two button kinds sat
   2px apart. */
.brxe-b59c51.brxe-product-add-to-cart a.button[data-product_id],
.brxe-product-add-to-cart .button,.beltoft-loop-variable__add{
	/* 40px, not 38: something in the theme stack sets min-height:40px on Woo's
	   own loop button, and min-height beats height, so `height:38px` was
	   silently ignored on that one and honoured on ours - which is exactly the
	   2px the two buttons differed by. Matching the value the theme already
	   uses settles it without an !important fight over a property that was
	   never the one deciding. */
	/* Sized to the quantity control beside it (32px), and fixed rather than
	   min-width so the three labels this button cycles through - Adicionar,
	   the in-flight one, Adicionado - cannot change its width. min-height is
	   set too: the theme puts min-height:40px on Woo's loop button and
	   min-height beats height. */
	width:132px;min-width:132px;height:32px;min-height:32px;padding:0 12px;
	display:inline-flex;align-items:center;justify-content:center;
	text-align:center;position:relative;
}
/* The cart is open on this page, a couple of hundred pixels to the right, so
   a per-row link to it buys nothing and reflows the row when it appears. */
.brxe-product-add-to-cart .added_to_cart{display:none}
.brxe-product-add-to-cart .button.loading,
.beltoft-loop-variable__add.is-busy{color:transparent !important}
.brxe-product-add-to-cart .button.loading::after,
.beltoft-loop-variable__add.is-busy::after{
	content:"";position:absolute;inset:0;margin:auto;width:15px;height:15px;
	border:2px solid currentColor;border-top-color:transparent;border-radius:50%;
	color:#fff;animation:beltoft-spin .6s linear infinite;
}
@keyframes beltoft-spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){
	.brxe-product-add-to-cart .button.loading::after,
	.beltoft-loop-variable__add.is-busy::after{animation:none}
}

/* Live stock message, in the row rather than as a popup somewhere else. */
.beltoft-loop-note{
	flex:1 0 100%;margin-top:4px;font-size:12px;line-height:1.35;color:#a3402f;
}
.beltoft-loop-note--ok{color:#5d6b60}
</style>
		<?php
	}

	public static function print_script(): void {
		if ( ! self::applies() ) {
			return;
		}

		$strings = array(
			/* translators: %d: units in stock */
			'only'      => __( 'Only %d in stock.', 'beltoft-commerce' ),
			/* translators: %d: units in stock */
			'failedCap' => __( 'Could not add. Only %d in stock.', 'beltoft-commerce' ),
			'failed'    => __( 'This line could not be added.', 'beltoft-commerce' ),
		);
		?>
<script>
(function(){
	var t=<?php echo wp_json_encode( $strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?>;
	function sync(input){
		var row=input.closest('.brxe-product-add-to-cart');
		if(!row)return;
		var button=row.querySelector('a.add_to_cart_button,a.button');
		if(!button)return;
		var value=parseInt(input.value,10);
		button.dataset.quantity=(isNaN(value)||value<1)?1:value;
	}
	/*
	 * Tell the buyer at the moment they type it, not after a round trip that
	 * comes back refused. The cap is already on the input as `max`, taken from
	 * the line's own stock, so this needs no request.
	 */
	function note(input){
		var row=input.closest('.brxe-product-add-to-cart')||input.closest('.beltoft-loop-variable');
		if(!row)return;
		var max=parseInt(input.max,10);
		var val=parseInt(input.value,10)||0;
		var host=input.closest('.beltoft-loop-variable')||row;
		var el=host.querySelector('.beltoft-loop-note');
		if(isNaN(max)||val<=max){ if(el)el.remove(); return; }
		if(!el){ el=document.createElement('span'); el.className='beltoft-loop-note'; el.setAttribute('role','status'); host.appendChild(el); }
		el.textContent=t.only.replace('%d',max);
	}
	document.addEventListener('input',function(e){
		var input=e.target.closest('.beltoft-loop-qty__input');
		if(input){sync(input);note(input);}
	});
	/*
	 * Variable products add through Woo's own AJAX endpoint with the chosen
	 * variation_id. Woo's loop script only knows how to add a simple product,
	 * so this posts the same shape it would and lets the same fragment refresh
	 * update the header and the cart panel - no second cart mechanism.
	 */
	document.addEventListener('click',function(e){
		var add=e.target.closest('.beltoft-loop-variable__add');
		if(!add)return;
		e.preventDefault();
		var wrap=add.closest('.beltoft-loop-variable');
		var pick=wrap&&wrap.querySelector('.beltoft-loop-variation');
		var qty=wrap&&wrap.querySelector('.beltoft-loop-qty__input');
		if(!wrap||!pick)return;
		/*
		 * The VARIATION id goes in as product_id. WC_AJAX::add_to_cart ignores
		 * a variation_id field entirely and instead detects that the posted
		 * product is a variation, deriving the parent itself
		 * (class-wc-ajax.php:525). Posting parent + variation_id looks right
		 * and fails with Woo's "go to the product page" response.
		 */
		var body=new URLSearchParams();
		body.append('product_id',pick.value);
		body.append('quantity',(parseInt(qty&&qty.value,10)||1));
		var label=add.textContent;
		add.classList.add('is-busy');
		add.setAttribute('aria-busy','true');
		fetch('/?wc-ajax=add_to_cart',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
			.then(function(r){return r.json();})
			.then(function(data){
				/*
				 * WC_AJAX::add_to_cart answers 200 with {error:true} whenever
				 * validation fails or the cart refuses the line - out of stock,
				 * an enforcement guard, an unpublished product. Treating any
				 * 200 as success showed a tick for an item that was never
				 * added, which is the worst possible outcome on an order form:
				 * the buyer believes the line is in and finds out at checkout.
				 */
				if(!data||data.error||!data.fragments){
					/*
					 * WC_AJAX answers 200 with {error:true} for every refusal -
					 * out of stock, an enforcement guard, an unpublished
					 * product - and carries no reason. Say what is knowable
					 * (the stock cap, which is on the input) rather than a bare
					 * cross the buyer cannot act on.
					 */
					var host=wrap;
					var el=host.querySelector('.beltoft-loop-note');
					if(!el){el=document.createElement('span');el.className='beltoft-loop-note';el.setAttribute('role','status');host.appendChild(el);}
					var cap=qty&&parseInt(qty.max,10);
					el.textContent=cap?t.failedCap.replace('%d',cap):t.failed;
					add.classList.remove('is-busy');
					add.removeAttribute('aria-busy');
					return;
				}
				var cleared=wrap.querySelector('.beltoft-loop-note');
				if(cleared)cleared.remove();
				Object.keys(data.fragments).forEach(function(sel){
					document.querySelectorAll(sel).forEach(function(node){
						node.outerHTML=data.fragments[sel];
					});
				});
				/*
				 * added_to_cart AFTER the DOM is replaced, with Woo's own
				 * payload shape. cart-fragments.js updates its sessionStorage
				 * cache on this event, and side-carts hook it to open; firing
				 * wc_fragments_refreshed beforehand did neither, leaving the
				 * cached hash diverged and costing a refresh round trip on the
				 * next page load.
				 */
				if(window.jQuery){
					jQuery(document.body).trigger('added_to_cart',[data.fragments,data.cart_hash,jQuery(add)]);
				}
				add.classList.remove('is-busy');
				add.removeAttribute('aria-busy');
			})
			.catch(function(){add.classList.remove('is-busy');add.removeAttribute('aria-busy');});
	});
	document.addEventListener('click',function(e){
		var step=e.target.closest('.beltoft-loop-qty__step');
		if(!step)return;
		e.preventDefault();
		var wrap=step.closest('.beltoft-loop-qty');
		var input=wrap&&wrap.querySelector('.beltoft-loop-qty__input');
		if(!input)return;
		var by=parseInt(step.dataset.step,10)||0;
		var min=parseInt(input.min,10); if(isNaN(min))min=1;
		var max=parseInt(input.max,10);
		var next=(parseInt(input.value,10)||min)+by;
		if(next<min)next=min;
		if(!isNaN(max)&&next>max)next=max;
		input.value=next;
		sync(input);
	});
})();
</script>
		<?php
	}
}
