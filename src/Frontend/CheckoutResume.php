<?php

namespace BeltoftCommerce\Frontend;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A refresh part-way through the checkout does not start the sale again.
 *
 * The trade checkout is a four-step Bricks form - os seus dados, notas,
 * revisão, pagamento - and the step lives nowhere but in a data attribute.
 * Reload the page, lose a connection, let a phone sleep and come back, and the
 * buyer is on step one looking at an empty form with a full cart. The cart
 * survives because WooCommerce keeps it in the session; nothing else typed
 * into the form does.
 *
 * WHAT IS RESTORED, AND WHY BOTH HALVES ARE NEEDED. The step goes in the
 * address bar as `#passo=payment`, so the URL is the thing that says where you
 * were - that is what makes a refresh and the back button agree. Each move the
 * buyer makes is a history entry, so Back steps back through the checkout and
 * leaves it from the first step, the way it reads.
 * On its own it would be worse than nothing: WooCommerce only persists
 * country, state, postcode, city and address to the session, so landing back
 * on the payment step would show a checkout whose earlier steps had quietly
 * emptied. So the answers are kept too, in sessionStorage, and put back before
 * the step is. At a till that is the whole point - an operator mid-sale should
 * not lose a customer's NIF to a stray refresh.
 *
 * WHY sessionStorage AND NOT THE SERVER. These are answers somebody is still
 * typing, not an order. They belong to one tab, they should disappear when it
 * closes, and they should never reach the database or a cache. The key carries
 * the WooCommerce customer id so a second person served on the same machine
 * cannot inherit the first one's details.
 *
 * NOT RESTORED: the terms checkbox. A consent is given, not remembered.
 */
class CheckoutResume {

	/** `.../finalizar-b2b/#passo=payment` */
	private const PARAM = 'passo';

	public static function register(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	private static function applies(): bool {
		return Hosts::is_b2b_host() && function_exists( 'is_checkout' ) && is_checkout();
	}

	/**
	 * Whether the sale is already placed.
	 *
	 * The thank-you page is still the checkout page as far as is_checkout() is
	 * concerned, and it is the one moment we know for certain the stored
	 * answers belong to something finished - so it is where they are dropped.
	 */
	private static function finished(): bool {
		return function_exists( 'is_order_received_page' ) && is_order_received_page();
	}

	/**
	 * What scopes the stored answers to one shopper.
	 *
	 * The WooCommerce customer id, which is the account id for a signed-in
	 * buyer and the session's own id for a guest. It changes when somebody
	 * signs out, which is exactly when the previous person's details must stop
	 * being offered to the next one.
	 */
	private static function scope(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 'anon';
		}

		$id = (string) WC()->session->get_customer_id();

		return '' !== $id ? $id : 'anon';
	}

	/** The thank-you page carries the cleanup and nothing else. */
	private static function forget( string $key ): void {
		?>
<script>try{sessionStorage.removeItem(<?php echo wp_json_encode( $key ); ?>);}catch(e){}</script>
		<?php
	}

	public static function render(): void {
		if ( ! self::applies() ) {
			return;
		}

		$key = 'beltoft-checkout-' . self::scope();

		if ( self::finished() ) {
			self::forget( $key );

			return;
		}

		?>
<script>
(function(){
	var KEY   = <?php echo wp_json_encode( $key ); ?>;
	var PARAM = <?php echo wp_json_encode( self::PARAM ); ?>;

	function drop(){ try{ sessionStorage.removeItem(KEY); }catch(e){} }

	var form = document.querySelector('form.checkout');
	if(!form) return;

	/*
	 * Everything the buyer answered, and nothing that answers for them.
	 *
	 * Out: the cart quantity inputs, which belong to the cart and are already
	 * kept there; the nonces and the referer, which are this request's and
	 * would be stale on the next one; and `terms`, because an acceptance that
	 * restores itself is not an acceptance.
	 */
	var SKIP = /^(cart\[|_wp|woocommerce-process-checkout-nonce|terms)/;

	function fields(){
		return Array.prototype.filter.call(
			form.querySelectorAll('input[name],select[name],textarea[name]'),
			function(el){
				return !SKIP.test(el.name) &&
					el.type !== 'hidden' && el.type !== 'password' && el.type !== 'file';
			});
	}

	function save(){
		var data = {};
		fields().forEach(function(el){
			if(el.type === 'checkbox' || el.type === 'radio'){
				if(el.checked) data[el.name] = el.value;
			} else if(el.value !== ''){
				data[el.name] = el.value;
			}
		});
		try{ sessionStorage.setItem(KEY, JSON.stringify(data)); }catch(e){}
	}

	/* Returns whether this tab had a checkout in progress at all, which is a
	   different question from whether any value needed changing. */
	function restore(){
		var raw; try{ raw = sessionStorage.getItem(KEY); }catch(e){ return false; }
		if(!raw) return false;
		var data; try{ data = JSON.parse(raw); }catch(e){ drop(); return false; }

		var changed = [];
		fields().forEach(function(el){
			if(!Object.prototype.hasOwnProperty.call(data, el.name)) return;
			if(el.type === 'checkbox' || el.type === 'radio'){
				if(el.value === data[el.name] && !el.checked){ el.checked = true; changed.push(el); }
			} else if(el.value !== data[el.name]){
				el.value = data[el.name]; changed.push(el);
			}
		});

		/*
		 * A restored value has to announce itself. WooCommerce rebuilds the
		 * district field and the totals from a country change and the gateway
		 * list from a payment_method change, and the country field is a
		 * SelectWoo whose visible text only follows a jQuery-triggered change -
		 * a native event would set the value and leave the box reading
		 * "Seleccione uma opção".
		 */
		changed.forEach(function(el){
			if(window.jQuery){ window.jQuery(el).trigger('change'); }
			else { el.dispatchEvent(new Event('change', {bubbles:true})); }
		});

		return true;
	}

	/* ---- the step, in the address bar ---------------------------------- */

	/* Read back as a step id only if it looks like one - it arrives from the
	   URL and is about to be put into a selector. */
	function wanted(){
		var m = new RegExp('[#&]' + PARAM + '=([A-Za-z0-9_-]+)').exec(location.hash || '');
		return m ? m[1] : '';
	}

	function url(id){ return location.pathname + location.search + '#' + PARAM + '=' + id; }
	function push(id){ try{ history.pushState({step:id}, '', url(id)); }catch(e){} }
	function replace(id){ try{ history.replaceState({step:id}, '', url(id)); }catch(e){} }

	function active(){ return form.dataset.brxCheckoutActiveStep || ''; }

	function firstStep(){
		var n = document.querySelector('[data-brx-checkout-step-nav-item]');
		return n ? n.dataset.stepId : '';
	}

	/*
	 * Move to a step the buyer has already been to, by its own nav item.
	 *
	 * Bricks enables a nav item once its step has been reached and leaves the
	 * rest disabled, which is exactly the rule Back and Forward need: they can
	 * only ever land on somewhere already visited. Backwards this works
	 * directly; forwards it works because returning to an earlier step does not
	 * lower the furthest-reached mark.
	 */
	function jump(id){
		var n = id ? document.querySelector('[data-brx-checkout-step-nav-item][data-step-id="' + id + '"]') : null;
		if(n) n.click();
	}

	function indexOf(id){
		var n = id ? document.querySelector('[data-brx-checkout-step-nav-item][data-step-id="' + id + '"]') : null;
		return n ? parseInt(n.dataset.stepIndex, 10) : -1;
	}

	/*
	 * The forward control of whichever step is showing.
	 *
	 * Found by its interaction config rather than its text, which is
	 * translated, or its id, which Bricks regenerates whenever the page is
	 * re-saved. Walking forward one step at a time is deliberate: it is the
	 * same path a buyer takes, so every step's own validation and the order
	 * review's AJAX run in the order they normally would. Jumping straight to
	 * the payment step would arrive before the gateways had been asked for.
	 */
	function forward(){
		var step = form.querySelector('[data-brx-checkout-step][data-step-id="' + active() + '"]');
		if(!step) return null;
		var c = step.querySelectorAll('[data-interactions]');
		for(var i = 0; i < c.length; i++){
			if((c[i].getAttribute('data-interactions') || '').indexOf('"checkoutStepMode":"next"') > -1) return c[i];
		}
		return null;
	}

	function walk(target, tries, done){
		if(tries > 8 || active() === target || indexOf(active()) >= indexOf(target)) return done();

		var btn = forward();
		if(!btn) return done();

		var before = active();
		btn.click();

		setTimeout(function(){
			// It would not advance - the restored answers do not carry this
			// buyer any further, so this is where they belong.
			if(active() === before) return done();
			walk(target, tries + 1, done);
		}, 450);
	}

	/* ---- who is allowed to write history ------------------------------- */

	/*
	 * Two flags, because a step change means three different things.
	 *
	 * `settled` is false while the page is still arranging itself. Bricks
	 * dispatches a step change when it activates the first step on every load,
	 * and pushing for that would put a second entry on the same page - so Back
	 * would return to the page you are already looking at before it would leave
	 * it. That one belongs in the entry the buyer arrived on, so it replaces.
	 *
	 * `driving` is true while this script is the one moving the step: the walk
	 * that restores a refreshed checkout, and the jumps that answer Back and
	 * Forward. Those must not add entries either - a Back that pushes is a Back
	 * that cannot be undone, and the resume walk would otherwise bury the page
	 * the buyer came from under one entry per step.
	 *
	 * Everything else - the buyer pressing Seguinte or Voltar, or a nav item -
	 * is a move they made, and gets an entry to come back to.
	 */
	var settled = false;
	var driving = false;

	document.body.addEventListener('bricks/woocommerce/checkout-step-changed', function(e){
		var id = (e.detail && e.detail.stepId) || active();
		if(!id) return;

		/*
		 * A step change that changes no step gets no entry.
		 *
		 * Bricks re-announces the active step whenever it re-renders its
		 * fragments, which the order review does more than once on a quiet
		 * page - and each of those was an entry, so Back landed three times on
		 * the first step before it would leave the checkout. What the URL
		 * already says is the record of where we were, so comparing against it
		 * both suppresses the repeats and needs nothing else to remember.
		 */
		if(settled && !driving && id !== wanted()) push(id);
		else replace(id);
	});

	window.addEventListener('popstate', function(){
		// No step in the URL means the entry the buyer arrived on, which is the
		// first step - not "leave it where it is".
		var id = wanted() || firstStep();
		if(!id || id === active()) return;

		driving = true;
		jump(id);
		setTimeout(function(){ driving = false; }, 700);
	});

	var timer;
	function queue(){ clearTimeout(timer); timer = setTimeout(save, 250); }
	form.addEventListener('input',  queue, true);
	form.addEventListener('change', queue, true);

	// Values first, so the step that follows has something to stand on.
	var resuming = restore();

	/*
	 * The step is read from the URL now and acted on later, and the order
	 * matters both ways.
	 *
	 * Now, because the listener above rewrites the hash the moment Bricks
	 * activates the first step - so by the time anything else runs, the URL no
	 * longer says where the buyer was.
	 *
	 * Later, because none of the rest of it is ready yet. Bricks writes
	 * `data-step-index` onto the nav items when it initialises, which is after
	 * this script is parsed, so asking for a step's position here gets NaN and
	 * silently answers "no" to every comparison - which is exactly how this
	 * failed the first time: the click path worked all along and was never
	 * reached.
	 */
	var target = wanted();

	/*
	 * Only this tab's own checkout is resumed.
	 *
	 * The step is in the URL, so the URL can be pasted somewhere else - and
	 * without this that would walk a stranger to the payment step of a form
	 * none of whose answers they had given. Having stored answers is what makes
	 * it a resumption rather than a link; without them the buyer starts where
	 * everybody starts.
	 */
	function begin(){
		setTimeout(function(){
			if(resuming && target && indexOf(target) > 0){
				driving = true;
				walk(target, 0, function(){ driving = false; settled = true; });
			} else {
				settled = true;
			}
		}, 300);
	}

	if(document.readyState === 'complete') begin();
	else window.addEventListener('load', begin);
})();
</script>
		<?php
	}
}
