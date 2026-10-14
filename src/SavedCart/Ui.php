<?php

namespace BeltoftCommerce\SavedCart;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * The saved-orders panel: one block, used in two places.
 *
 * `[beltoft_saved_carts]` goes beside the cart on the ordering page, where a
 * partner saves what they have just built; the same markup is rendered in My
 * Account, where they come back for it weeks later. One renderer, so the two
 * cannot describe the feature differently.
 */
class Ui {

	public const SHORTCODE = 'beltoft_saved_carts';

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	public static function shortcode( $atts = array() ): string {
		if ( ! self::applies() ) {
			return '';
		}

		$atts = shortcode_atts( array( 'show_save' => 'yes' ), (array) $atts, self::SHORTCODE );

		return self::render( 'yes' === $atts['show_save'] );
	}

	private static function applies(): bool {
		return is_user_logged_in() && Hosts::is_b2b_host();
	}

	public static function render( bool $show_save = true ): string {
		$user_id = get_current_user_id();
		$saved   = Repository::all( $user_id );
		$notice  = Actions::take_notice( $user_id );

		/*
		 * A disclosure, not a panel that is always open.
		 *
		 * Saving an order and reopening one are things a partner does at the
		 * two ends of a session, not while working. Left expanded it took a
		 * permanent block of the cart column - the most valuable space on the
		 * page - and put a heading, a form and a list directly under the order
		 * total, all competing with it.
		 *
		 * <details> rather than a scripted popover on purpose: it opens and
		 * closes with no JavaScript at all, it is keyboard operable and
		 * screen-reader announced without any help from us, and if scripts fail
		 * the feature still works. The panel is only positioned over the cart -
		 * if that positioning never applies, it falls back to an ordinary block
		 * that pushes the page down, which is ugly and still usable.
		 *
		 * It opens by itself when there is something to act on - an error, or a
		 * restore that skipped items. A plain success (saved, replaced, merged,
		 * deleted) leaves it closed: the partner is done with it, and the cart
		 * above already shows the result. The message then sits as one line
		 * under the button instead.
		 *
		 * Closing it is enhanced when scripts run (print_script): a × button in
		 * the panel, a click anywhere outside it, and Esc all close it. The ×
		 * starts hidden so a page without scripts never shows a dead button.
		 */
		$success = $notice && 'success' === $notice['type'];
		$open    = $notice && ! $success ? ' open' : '';
		$count = count( $saved );

		ob_start();
		?>
		<details class="beltoft-saved"<?php echo esc_attr( $open ); ?>>
			<summary class="beltoft-saved__toggle">
				<span class="beltoft-saved__toggle-label">
					<?php esc_html_e( 'Saved orders', 'beltoft-commerce' ); ?>
				</span>
				<?php if ( $count ) : ?>
					<span class="beltoft-saved__badge"><?php echo esc_html( $count ); ?></span>
				<?php endif; ?>
				<span class="beltoft-saved__caret" aria-hidden="true">&#9662;</span>
			</summary>

			<div class="beltoft-saved__panel">
				<button type="button" class="beltoft-saved__close" aria-label="<?php esc_attr_e( 'Close', 'beltoft-commerce' ); ?>" hidden>&times;</button>
				<?php if ( $notice && ! $success ) : ?>
					<p class="beltoft-saved__notice beltoft-saved__notice--<?php echo esc_attr( $notice['type'] ); ?>">
						<?php echo esc_html( $notice['message'] ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $show_save ) : ?>
					<form class="beltoft-saved__save" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( Actions::SAVE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( Actions::SAVE ); ?>">
						<label class="screen-reader-text" for="beltoft-saved-name">
							<?php esc_html_e( 'Order name', 'beltoft-commerce' ); ?>
						</label>
						<input type="text" id="beltoft-saved-name" name="saved_cart_name" maxlength="120"
							placeholder="<?php esc_attr_e( 'Save the current order as…', 'beltoft-commerce' ); ?>">
						<button type="submit"><?php esc_html_e( 'Save', 'beltoft-commerce' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( ! $saved ) : ?>
					<p class="beltoft-saved__empty"><?php esc_html_e( 'You have not saved any orders yet.', 'beltoft-commerce' ); ?></p>
				<?php else : ?>
					<ul class="beltoft-saved__list">
						<?php foreach ( $saved as $cart ) : ?>
							<li class="beltoft-saved__item">
								<div class="beltoft-saved__meta">
									<span class="beltoft-saved__name"><?php echo esc_html( $cart->name ); ?></span>
									<span class="beltoft-saved__sub">
										<?php
										printf(
											/* translators: 1: number of lines, 2: date the order was saved. */
											esc_html( _n( '%1$d line · %2$s', '%1$d lines · %2$s', (int) $cart->item_count, 'beltoft-commerce' ) ),
											(int) $cart->item_count,
											esc_html( date_i18n( get_option( 'date_format' ), strtotime( $cart->updated_at . ' UTC' ) ) )
										);
										?>
									</span>
								</div>

								<div class="beltoft-saved__actions">
									<?php
									/*
									 * Two ways in, because both are real: a
									 * partner repeating a standing order wants
									 * the basket replaced, one topping up a
									 * part-built order wants it added to.
									 */
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( Actions::RESTORE ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Actions::RESTORE ); ?>">
										<input type="hidden" name="saved_cart_id" value="<?php echo esc_attr( $cart->id ); ?>">
										<?php
										/*
										 * "Substituir", not "Abrir". Opening
										 * describes looking at something; this
										 * throws away whatever is in the cart
										 * and puts the saved order there
										 * instead. The label has to carry that,
										 * because it pairs with "Juntar" beside
										 * it and the difference between the two
										 * is the only thing a buyer needs to
										 * read before pressing one.
										 */
										?>
										<button type="submit" class="beltoft-saved__use">
											<?php esc_html_e( 'Replace', 'beltoft-commerce' ); ?>
										</button>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( Actions::RESTORE ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Actions::RESTORE ); ?>">
										<input type="hidden" name="saved_cart_id" value="<?php echo esc_attr( $cart->id ); ?>">
										<input type="hidden" name="saved_cart_merge" value="1">
										<button type="submit" class="beltoft-saved__merge">
											<?php esc_html_e( 'Add to current order', 'beltoft-commerce' ); ?>
										</button>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										onsubmit="return confirm('<?php echo esc_js( __( 'Delete this saved order?', 'beltoft-commerce' ) ); ?>');">
										<?php wp_nonce_field( Actions::DELETE ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( Actions::DELETE ); ?>">
										<input type="hidden" name="saved_cart_id" value="<?php echo esc_attr( $cart->id ); ?>">
										<button type="submit" class="beltoft-saved__delete"
											aria-label="<?php esc_attr_e( 'Delete', 'beltoft-commerce' ); ?>">&times;</button>
									</form>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</details>
		<?php if ( $success ) : ?>
			<p class="beltoft-saved__done" role="status"><?php echo esc_html( $notice['message'] ); ?></p>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Inline, in the head, and only on the B2B host.
	 *
	 * Small enough that a file would cost more in a request than it saves, and
	 * in the head rather than the footer for the reason Frontend\LoopQuantity
	 * carries at length: CSS that arrives after the markup it styles makes the
	 * page lay out twice.
	 */
	public static function styles(): void {
		if ( ! self::applies() ) {
			return;
		}

		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 20 );
	}

	/**
	 * Close on ×, on a click outside the panel, and on Esc.
	 *
	 * Delegated from document, so it keeps working when the cart's markup is
	 * re-rendered by WooCommerce fragments. Esc is caught before the cart
	 * Offcanvas sees it: the first Esc closes the saved-orders panel, the next
	 * one the cart, instead of both at once.
	 */
	public static function print_script(): void {
		?>
<script>
(function(){
	var SEL='details.beltoft-saved';
	function shut(d){d.removeAttribute('open');var s=d.querySelector('summary');if(s){s.focus();}}
	document.addEventListener('toggle',function(e){
		var d=e.target;
		if(d.matches&&d.matches(SEL)&&d.open){var b=d.querySelector('.beltoft-saved__close');if(b){b.hidden=false;}}
	},true);
	document.addEventListener('click',function(e){
		var x=e.target.closest('.beltoft-saved__close');
		if(x){var d=x.closest(SEL);if(d){e.preventDefault();shut(d);}return;}
		var inside=e.target.closest(SEL);
		document.querySelectorAll(SEL+'[open]').forEach(function(d){if(d!==inside){d.removeAttribute('open');}});
	});
	document.addEventListener('keydown',function(e){
		if(e.key!=='Escape'){return;}
		var d=document.querySelector(SEL+'[open]');
		if(d){e.stopPropagation();shut(d);}
	},true);
	document.querySelectorAll(SEL+'[open] .beltoft-saved__close').forEach(function(b){b.hidden=false;});
})();
</script>
		<?php
	}

	public static function print_styles(): void {
		?>
<style>
/* Saved orders: a quiet button under the cart that opens over it.

   It used to sit open in the cart column, putting a heading, a form and a list
   directly beneath the order total. Collapsed, it costs one line; opened, it
   floats above the cart rather than pushing the page down, so the total and
   Finalizar encomenda never move under someone's cursor. */
.beltoft-saved{position:relative;margin-top:10px;font-size:14px}
.beltoft-saved__toggle{
	display:flex;align-items:center;gap:8px;width:100%;
	padding:9px 12px;border:1px solid #e3e8dd;background:#fff;
	color:#264F37;font:inherit;font-size:13px;font-weight:500;
	cursor:pointer;list-style:none;user-select:none;
	transition:border-color .12s,background-color .12s;
}
.beltoft-saved__toggle::-webkit-details-marker{display:none}
.beltoft-saved__toggle:hover{border-color:#cfd8c4;background:#F4F6F1}
.beltoft-saved__toggle:focus-visible{outline:2px solid #264F37;outline-offset:-2px}
.beltoft-saved__toggle-label{flex:1 1 auto;text-align:left}
.beltoft-saved__badge{
	flex:0 0 auto;min-width:20px;height:20px;padding:0 6px;
	display:inline-grid;place-items:center;background:#264F37;color:#fff;
	font-size:11px;font-weight:600;font-variant-numeric:tabular-nums;
}
.beltoft-saved__caret{flex:0 0 auto;font-size:10px;color:#7a857d;transition:transform .12s}
.beltoft-saved[open] .beltoft-saved__caret{transform:rotate(180deg)}
.beltoft-saved[open] .beltoft-saved__toggle{border-color:#264F37;background:#F4F6F1}

/* Opens upward over the cart: the button sits low in a tall column, so a panel
   dropping downward would fall off the screen. */
.beltoft-saved__panel{
	position:absolute;left:0;right:0;bottom:calc(100% + 6px);z-index:30;
	max-height:min(60vh,420px);overflow-y:auto;
	padding:14px;background:#fff;border:1px solid #264F37;
	box-shadow:0 -6px 18px rgba(31,42,35,.12);
}
.beltoft-saved__done{margin:6px 0 0;padding:6px 10px;border-left:3px solid #4f7a5c;background:#F4F6F1;font-size:13px;line-height:1.4}
.beltoft-saved__close{position:absolute;top:4px;right:6px;width:28px;height:28px;padding:0;border:0;background:transparent;color:#1f2a23;font-size:20px;line-height:28px;cursor:pointer}
.beltoft-saved__close:hover{color:#264F37}
.beltoft-saved__close:focus-visible{outline:2px solid #264F37;outline-offset:-2px}
.beltoft-saved__close[hidden]{display:none}
.beltoft-saved__panel>.beltoft-saved__close+*{margin-right:28px}
.beltoft-saved__notice{margin:0 0 10px;padding:8px 10px;border-left:3px solid #4f7a5c;background:#F4F6F1;line-height:1.4}
.beltoft-saved__notice--error{border-left-color:#a3402f}
.beltoft-saved__notice--notice{border-left-color:#c8922f}
.beltoft-saved__save{display:flex;gap:0;margin:0 0 12px}
.beltoft-saved__save input{flex:1 1 auto;min-width:0;height:38px;padding:0 10px;border:1px solid #dddedf;border-right:0;background:#fff;font:inherit;font-size:14px}
.beltoft-saved__save button{flex:0 0 auto;height:38px;padding:0 16px;border:0;background:#264F37;color:#fff;font:inherit;font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.02em;cursor:pointer}
.beltoft-saved__save button:hover{background:#1d3d2a}
.beltoft-saved__empty{margin:0;color:#7a857d}
.beltoft-saved__list{list-style:none;margin:0;padding:0}
.beltoft-saved__item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #e3e8dd}
.beltoft-saved__item:last-child{border-bottom:0}
.beltoft-saved__meta{min-width:0}
.beltoft-saved__name{display:block;color:#1f2a23;line-height:1.3}
.beltoft-saved__sub{display:block;margin-top:2px;font-size:12px;color:#7a857d}
.beltoft-saved__actions{display:flex;align-items:center;gap:6px;flex:0 0 auto}
.beltoft-saved__actions form{margin:0}
.beltoft-saved__use,.beltoft-saved__merge{height:32px;padding:0 12px;border:1px solid #264F37;background:#fff;color:#264F37;font:inherit;font-size:13px;cursor:pointer}
.beltoft-saved__use{background:#264F37;color:#fff}
.beltoft-saved__delete{width:32px;height:32px;padding:0;border:0;background:none;color:#7a857d;font-size:18px;line-height:1;cursor:pointer}
.beltoft-saved__delete:hover{color:#a3402f}

/* In My Account there is no cart to float over and plenty of room, so it is an
   ordinary block that opens in place. */
.woocommerce-MyAccount-content .beltoft-saved__panel{
	position:static;max-height:none;box-shadow:none;border-top:0;
}

@media (max-width:767px){
	.beltoft-saved__panel{position:static;max-height:none;box-shadow:none;margin-top:6px}
}
</style>
		<?php
	}
}
