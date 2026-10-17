<?php

namespace BeltoftCommerce\QuickOrder;

use BeltoftCommerce\Support\Config;
use BeltoftCommerce\QuickOrder\Sheet\Reader;
use BeltoftCommerce\QuickOrder\Sheet\Writer;
use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * The quick-order surface: a paste pad, a download button and an upload form.
 *
 * Rendered by a shortcode so the page itself stays a Bricks page that anyone
 * can restyle in the builder, in the same spirit as the rest of this project:
 * layout in Bricks, rules in the plugin.
 */
class Page {

	/** Config 'quick_order_path', which HostRoutes also reads. */
	public static function path(): string {
		return Config::path( 'quick_order_path' );
	}

	public const SHORTCODE = 'beltoft_quick_order';


	/** Where the report waits between the POST and the redirected GET. */
	private const REPORT_KEY = 'beltoft_quick_order_report_';

	/*
	 * Formats that reach ZipArchive::open() today and fail with the generic
	 * ".xlsx inválido" message. The spec requires these be named explicitly,
	 * with an instruction, rather than left to that generic message.
	 */
	private const UNSUPPORTED_EXTENSIONS = array( 'csv', 'xls', 'xlsm', 'ods' );

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_post' ) );
	}

	/**
	 * Tab, comma, semicolon or a run of spaces all separate a code from its
	 * quantity, because a paste out of Excel is tab-separated and a paste out
	 * of an email is whatever the sender typed.
	 *
	 * @return array<int, array{code: string, quantity: int}>
	 */
	public static function parse_pad( string $raw ): array {
		$lines = array();

		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			/*
			 * A comma directly between two digits is the decimal separator
			 * Reader::to_quantity() goes out of its way to support, not a
			 * field separator. Left alone, "SKU<TAB>12,0" splits into THREE
			 * parts, the quantity becomes "0", and the line is dropped with
			 * no report at all -- the one number format this feature exists
			 * to handle. Normalise it to a dot before splitting, so it never
			 * reads as a separator.
			 */
			$line = preg_replace( '/(?<=\d),(?=\d)/', '.', $line );

			$parts = preg_split( '/[\t;,]+|\s+/', $line );
			$parts = array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$quantity = Reader::to_quantity( (string) array_pop( $parts ) );

			if ( $quantity < 1 ) {
				continue;
			}

			$lines[] = array(
				'code'     => implode( ' ', $parts ),
				'quantity' => $quantity,
			);
		}

		return $lines;
	}

	public static function maybe_download(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		if ( ! is_user_logged_in() || empty( $_GET['beltoft_sheet'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$nonce = isset( $_GET['beltoft_sheet_nonce'] ) ? sanitize_key( wp_unslash( $_GET['beltoft_sheet_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		/*
		 * wp_verify_nonce(), not check_admin_referer(): the latter does not
		 * return false on failure, it calls wp_nonce_ays() and dies. On a
		 * customer-facing page a stale link should quietly do nothing, not
		 * present WordPress's admin "Are you sure?" screen.
		 */
		if ( ! wp_verify_nonce( $nonce, 'beltoft_sheet' ) ) {
			return;
		}

		$bytes = Writer::build( Writer::rows() );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="encomenda.xlsx"' );
		header( 'Content-Length: ' . strlen( $bytes ) );

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function maybe_handle_post(): void {
		if ( ! Hosts::is_b2b_host() ) {
			return;
		}

		if ( ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		/*
		 * The overflow branch below fires before any nonce can be checked - a
		 * body over post_max_size leaves $_POST empty, nonce included. That
		 * makes it the one branch here with no proof it belongs to this form,
		 * so the page itself has to be the proof. Without this, an oversized
		 * checkout POST on this host is answered with a notice about
		 * spreadsheet limits and a redirect to the quick-order page.
		 */
		if ( ! self::is_quick_order_request() ) {
			return;
		}

		/*
		 * A post_max_size overflow empties $_POST and $_FILES entirely, with
		 * nothing PHP surfaces to userland. The request plainly had a body --
		 * Content-Length says so -- but nothing survived to parse. Checked
		 * BEFORE the nonce check below, because that check would otherwise
		 * just fail on the now-missing nonce and return silently, same as
		 * every other silent no-op this finding is about.
		 */
		if ( empty( $_POST ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: maximum upload size, e.g. "64 MB". */
					__( 'The upload exceeds the server limit (%s). Make the file smaller and try again.', 'beltoft-commerce' ),
					size_format( wp_max_upload_size() )
				),
				'error'
			);

			self::redirect_to_page();
		}

		if ( ! isset( $_POST['beltoft_quick_order_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['beltoft_quick_order_nonce'] ) ), 'beltoft_quick_order' ) ) {
			return;
		}

		$lines = array();

		$error = isset( $_FILES['beltoft_sheet_file']['error'] ) ? (int) $_FILES['beltoft_sheet_file']['error'] : UPLOAD_ERR_NO_FILE;

		/*
		 * Each panel has its own button, and the button says which route the
		 * buyer chose. Before this, a single button at the foot of the form
		 * served both panels and the handler guessed: file if there was one,
		 * pad otherwise. That guess was invisible - a buyer who attached a
		 * sheet AND had something left in the pad silently ordered only the
		 * sheet. Reading the pressed button removes the guess, so each button
		 * does exactly what its label says.
		 *
		 * Falls back to the old precedence when the value is missing, which is
		 * what a cached copy of the previous page sends.
		 */
		$route = isset( $_POST['beltoft_qo_route'] ) ? sanitize_key( wp_unslash( $_POST['beltoft_qo_route'] ) ) : '';

		if ( 'pad' === $route ) {
			$error = UPLOAD_ERR_NO_FILE;
		}

		if ( 'sheet' === $route && UPLOAD_ERR_NO_FILE === $error ) {
			wc_add_notice( __( 'First choose the .xlsx file you want to import.', 'beltoft-commerce' ), 'error' );

			self::redirect_to_page();
		}

		if ( UPLOAD_ERR_NO_FILE !== $error ) {
			$upload = $_FILES['beltoft_sheet_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			/*
			 * UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE leave tmp_name empty,
			 * so a branch that only checked tmp_name never ran for an
			 * oversized upload -- the customer saw nothing.
			 */
			if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: maximum upload size, e.g. "64 MB". */
						__( 'The file exceeds the upload limit (%s).', 'beltoft-commerce' ),
						size_format( wp_max_upload_size() )
					),
					'error'
				);

				self::redirect_to_page();
			}

			/*
			 * is_uploaded_file() is the only thing that establishes this path
			 * came from a multipart upload rather than being named by the
			 * request. sanitize_text_field() cleans a string; it says nothing
			 * about provenance, and the path goes straight to ZipArchive.
			 */
			if ( UPLOAD_ERR_OK !== $error || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
				wc_add_notice( __( 'The uploaded file could not be read.', 'beltoft-commerce' ), 'error' );

				self::redirect_to_page();
			}

			$extension = strtolower( (string) pathinfo( (string) ( $upload['name'] ?? '' ), PATHINFO_EXTENSION ) );

			if ( in_array( $extension, self::UNSUPPORTED_EXTENSIONS, true ) ) {
				wc_add_notice(
					__( 'This format is not supported. Save the sheet as .xlsx in Excel (File → Save as) and upload it again.', 'beltoft-commerce' ),
					'error'
				);

				self::redirect_to_page();
			}

			try {
				$lines = Reader::read( $upload['tmp_name'] );
			} catch ( \InvalidArgumentException $e ) {
				wc_add_notice( $e->getMessage(), 'error' );

				self::redirect_to_page();
			}
		} elseif ( ! empty( $_POST['beltoft_quick_order_pad'] ) ) {
			$lines = self::parse_pad( sanitize_textarea_field( wp_unslash( $_POST['beltoft_quick_order_pad'] ) ) );
		}

		if ( empty( $lines ) ) {
			/*
			 * Naming the route makes the difference between "your file had no
			 * quantities" and "you typed nothing", which the one generic
			 * sentence could not.
			 */
			if ( 'sheet' === $route ) {
				wc_add_notice(
					__( 'The sheet had no quantities. Fill in the Quantity column and upload it again.', 'beltoft-commerce' ),
					'error'
				);
			} elseif ( 'pad' === $route ) {
				wc_add_notice(
					__( 'No lines were found. Write one reference and one quantity per line.', 'beltoft-commerce' ),
					'error'
				);
			} else {
				wc_add_notice( __( 'No lines to order were found. Check the references and quantities.', 'beltoft-commerce' ), 'error' );
			}

			self::redirect_to_page();
		}

		$report = Resolver::resolve( $lines );

		self::notify( $report );

		self::redirect_to_page();
	}

	/**
	 * Whether this request is for the quick-order page.
	 *
	 * Path-based rather than is_page(), because this runs on template_redirect
	 * for requests that may not have resolved to a queried object.
	 */
	/**
	 * Every path that renders this surface.
	 *
	 * @return array<int, string>
	 */
	public static function paths(): array {
		return array( self::path() );
	}

	/**
	 * The quick-order page being viewed.
	 *
	 * Everything that leaves this page and comes back - the redirect after a
	 * submission, the link that downloads the sheet - has to return to the page
	 * the buyer is on. Sending them to self::path() would quietly move anyone
	 * working on the copy over to the live page mid-task.
	 */
	private static function current_page_url(): string {
		$id  = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$url = $id ? get_permalink( $id ) : '';

		return is_string( $url ) && '' !== $url ? $url : home_url( self::path() );
	}

	private static function is_quick_order_request(): bool {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return false;
		}

		foreach ( self::paths() as $known ) {
			if ( 0 === strpos( $path, $known ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Post/Redirect/Get. Without this, the POST stays the current request,
	 * so an F5 or a back-button re-post re-submits the pad AND the uploaded
	 * file -- and because upload merges with the pad by design, quantities
	 * double.
	 */
	private static function redirect_to_page(): void {
		self::stash_notices();

		wp_safe_redirect( self::current_page_url() );
		exit;
	}

	/**
	 * Carry the report across the redirect ourselves, because WooCommerce does not.
	 *
	 * The notices are written to WooCommerce's session, and on this stack they are
	 * simply not there afterwards: the session row for the customer holds
	 * customer, cart and cart_totals and NO wc_notices key by the time the
	 * redirected GET renders. Bricks compounds it -- `woocommerceUseBricksWooNotice`
	 * is on, and Bricks' own comment at themes/bricks/includes/woocommerce.php:2411
	 * describes wc_print_notices() running early in a post_class filter and ERASING
	 * the queue before the template renders.
	 *
	 * The effect was the worst kind of failure: the cart changed and the page said
	 * nothing at all -- no confirmation, and no word about the lines that were
	 * rejected. A short transient keyed to the user depends on neither the
	 * WooCommerce session nor Bricks' notice handling.
	 *
	 * Stashing happens at this one choke point because every failure path in the
	 * handler already redirects through here, so none of them can be forgotten.
	 */
	private static function stash_notices(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! function_exists( 'wc_get_notices' ) ) {
			return;
		}

		$notices = wc_get_notices();

		if ( empty( $notices ) ) {
			return;
		}

		set_transient( self::REPORT_KEY . $user_id, $notices, 5 * MINUTE_IN_SECONDS );

		// Ours now: leaving them queued would show the same report again on
		// whatever WooCommerce page the customer visited next.
		wc_clear_notices();
	}

	/**
	 * Render the stashed report once, then forget it.
	 */
	private static function stashed_report_html(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		$key     = self::REPORT_KEY . $user_id;
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return '';
		}

		delete_transient( $key );

		$items = '';

		foreach ( array( 'success', 'error', 'notice' ) as $type ) {
			foreach ( (array) ( $notices[ $type ] ?? array() ) as $notice ) {
				$text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;

				if ( '' === trim( $text ) ) {
					continue;
				}

				$items .= sprintf(
					'<li class="beltoft-quick-order__notice beltoft-quick-order__notice--%1$s">%2$s</li>',
					esc_attr( $type ),
					wp_kses_post( $text )
				);
			}
		}

		if ( '' === $items ) {
			return '';
		}

		$style = '<style>'
			. '.beltoft-quick-order__notices{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:6px}'
			. '.beltoft-quick-order__notice{padding:10px 14px;border-left:3px solid;font-size:14px;line-height:1.45}'
			. '.beltoft-quick-order__notice--success{border-color:#264F37;background:#f4f6f1;color:#264F37}'
			. '.beltoft-quick-order__notice--error{border-color:#a3402f;background:#fbf3f1;color:#7d3124}'
			. '.beltoft-quick-order__notice--notice{border-color:#8A9A7B;background:#f7f9f5;color:#333}'
			. '</style>';

		return $style . '<ul class="beltoft-quick-order__notices">' . $items . '</ul>';
	}

	private static function notify( Report $report ): void {
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$added = count( $report->successes() );

		if ( $added ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: number of lines added to the cart. */
					_n( '%d line added to the cart.', '%d lines added to the cart.', $added, 'beltoft-commerce' ),
					$added
				),
				'success'
			);
		}

		/*
		 * Grouped by MESSAGE, not by reason: NOT_AVAILABLE and NO_PRICE are
		 * deliberately worded the same (Report::message_for()) so a buyer
		 * cannot tell "you may not buy this" from "there is no price for
		 * you" -- two different reasons that must read as one sentence, not
		 * two lists under an identical line.
		 */
		foreach ( $report->rejections_by_message() as $message => $codes ) {
			wc_add_notice(
				$message . ' ' . implode( ', ', array_map( 'esc_html', $codes ) ),
				'error'
			);
		}
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$download = wp_nonce_url( add_query_arg( 'beltoft_sheet', '1', self::current_page_url() ), 'beltoft_sheet', 'beltoft_sheet_nonce' );

		ob_start();

		/*
		 * This page is a Bricks page, not a WooCommerce template, so nothing
		 * else on it calls wc_print_notices() -- WooCommerce only hooks
		 * woocommerce_output_all_notices onto shop/product/cart/checkout/
		 * account surfaces. Without this the success count and every
		 * rejection queue up silently and surface later, out of context, on
		 * whatever Woo page the buyer happens to visit next.
		 */
		echo self::stashed_report_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<style>
		/*
		 * The site's own palette, not a new one: this is an internal tool inside
		 * an existing identity, and a second green would read as a second brand.
		 * #264F37 deep green, #F4F6F1 paper, #e3e8dd rule, #7a857d muted.
		 */
		.beltoft-qo{--qo-ink:#264F37;--qo-rule:#e3e8dd;--qo-paper:#F4F6F1;--qo-muted:#7a857d;
			width:100%;max-width:960px;display:flex;flex-direction:column;row-gap:22px}
		/* Grid and flex children default to min-width:auto, so the textarea's
		   intrinsic width kept the panel from shrinking and it ran past the
		   page's right edge on a phone. */
		.beltoft-qo__routes > *,.beltoft-qo__panel > *{min-width:0}
		.beltoft-qo__routes{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:stretch}
		.beltoft-qo__panel{border:1px solid var(--qo-rule);background:#fff;padding:20px;display:flex;
			flex-direction:column;row-gap:10px}
		/* Natural height, not flex:1 1 auto. Stretching the list to fill an
		   equal-height panel put its slack AFTER the last step, which is
		   exactly where the import button needed to be. */
		.beltoft-qo__steps{flex:0 0 auto}
		.beltoft-qo__title{margin:0;font-size:16px;font-weight:600;color:var(--qo-ink);letter-spacing:-.005em}
		.beltoft-qo__label{font-size:13px;font-weight:500;color:var(--qo-ink);margin:0}
		.beltoft-qo__hint{margin:0;font-size:12px;line-height:1.5;color:var(--qo-muted)}

		/* The pad holds codes in columns, so the figures are tabular and the
		   leading is loose enough to check a long paste line by line. */
		.beltoft-qo__pad{width:100%;flex:1 1 auto;min-height:190px;padding:12px 14px;border:1px solid var(--qo-rule);
			background:var(--qo-paper);border-radius:0;resize:vertical;
			font-family:inherit;font-size:14px;line-height:1.75;font-variant-numeric:tabular-nums;
			color:#1f2a23;tab-size:12}
		.beltoft-qo__pad:focus{outline:2px solid var(--qo-ink);outline-offset:-2px;background:#fff}
		.beltoft-qo__pad::placeholder{color:#a7b0a9}

		/* Download, fill, send back really is a sequence, so it is numbered -
		   unlike the two routes above it, which are alternatives. */
		.beltoft-qo__steps{margin:0;padding:0 0 0 20px;display:flex;flex-direction:column;row-gap:14px;
			font-size:13px;line-height:1.5;color:#1f2a23}
				/* display:list-item, not flex - a flex <li> drops its marker, and the
		   numbers are the point here: they say this is a round trip, which the
		   two panels beside each other deliberately are not. */
		.beltoft-qo__steps li{display:list-item}
		.beltoft-qo__steps li::marker{color:var(--qo-muted);font-size:12px}
		/* Only the prose blocks, not the controls - a blanket display:block here
		   stretched the download button and the file picker across the column. */
		.beltoft-qo__steps .beltoft-qo__hint{display:block;margin-top:6px}

		.beltoft-qo__download{display:inline-flex;align-items:center;align-self:flex-start;height:38px;
			padding:0 16px;border:1px solid var(--qo-ink);color:var(--qo-ink);background:transparent;
			font-size:14px;font-weight:500;text-decoration:none;transition:background-color .12s,color .12s}
		.beltoft-qo__download:hover{background:var(--qo-ink);color:#fff}

		/* The native file control is an untranslated grey box that ignores every
		   other style on the page; this replaces it with a drop target that is
		   also clickable, because a buyer with the sheet open in Excel drags it
		   here far more readily than they go hunting through a file picker. */
		.beltoft-qo__file-input{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden}
		.beltoft-qo__drop{display:flex;flex-direction:column;align-items:center;justify-content:center;
			row-gap:6px;padding:22px 16px;border:1px dashed #b9c4b4;background:var(--qo-paper);
			text-align:center;cursor:pointer;transition:border-color .12s,background-color .12s}
		.beltoft-qo__drop:hover{border-color:var(--qo-ink)}
		.beltoft-qo__drop-lead{font-size:13px;font-weight:500;color:var(--qo-ink)}
		.beltoft-qo__drop-sub{font-size:12px;color:var(--qo-muted)}
		/* There is nothing to drag on a phone, so a touch device is told to tap
		   instead of being given an instruction it cannot follow. */
		.beltoft-qo__drop-touch{display:none;font-size:13px;font-weight:500;color:var(--qo-ink)}
		@media (hover:none),(pointer:coarse){
			.beltoft-qo__drop-lead,.beltoft-qo__drop-sub{display:none}
			.beltoft-qo__drop-touch{display:block}
		}
		/* Dragging over the whole panel, not just the dashed box: a file let go
		   an inch off target should still land. */
		.beltoft-qo--dragging .beltoft-qo__drop{border-color:var(--qo-ink);border-style:solid;background:#e9eee3}

		/* The attached state is deliberately loud. The old control said
		   "qo-filled.xlsx" in 13px grey beside a grey button, which read as a
		   hint rather than as "your file is ready to send". */
		.beltoft-qo__chosen{display:none;align-items:center;column-gap:12px;padding:12px 14px;
			border:1px solid var(--qo-ink);background:#eef2e8}
		.beltoft-qo--has-file .beltoft-qo__chosen{display:flex}
		.beltoft-qo--has-file .beltoft-qo__drop{display:none}
		.beltoft-qo__chosen-tick{flex:0 0 auto;width:20px;height:20px;border-radius:50%;
			background:var(--qo-ink);color:#fff;font-size:12px;line-height:20px;text-align:center}
		.beltoft-qo__chosen-text{min-width:0;flex:1 1 auto;display:flex;flex-direction:column}
		.beltoft-qo__chosen-name{font-size:13px;font-weight:600;color:var(--qo-ink);
			overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
		.beltoft-qo__chosen-size{font-size:12px;color:var(--qo-muted)}
		.beltoft-qo__chosen-clear{flex:0 0 auto;border:0;background:none;padding:4px;cursor:pointer;
			font:inherit;font-size:12px;color:var(--qo-muted);text-decoration:underline}
		.beltoft-qo__chosen-clear:hover{color:var(--qo-ink)}

		.beltoft-qo__submit{align-self:flex-start;height:44px;padding:0 28px;border:0;border-radius:0;
			background:var(--qo-ink);color:#fff;font:inherit;font-size:14px;font-weight:500;
			text-transform:uppercase;letter-spacing:.02em;cursor:pointer;transition:background-color .12s}
		.beltoft-qo__submit:hover{background:#1d3d2a}
		/* One button per panel, each sitting directly under the control it acts
		   on. A single button under both panels left the sheet route with no
		   visible next step after choosing a file - which read as nothing
		   having happened. */
		/* Deliberately NOT margin-top:auto. Pushed to the foot of an
		   equal-height panel the button sat 125px below the drop zone with
		   nothing in between, which is the same "where do I press now?" gap
		   the single button at the foot of the form had. */
		.beltoft-qo__go{padding-top:14px}
		.beltoft-qo__go .beltoft-qo__submit{align-self:stretch;width:100%}
		/* Until a file is attached the import button would be a button that
		   cannot work, so it says why instead of failing on press. */
		.beltoft-qo__submit[disabled]{background:#c3ccbe;cursor:not-allowed}
		.beltoft-qo--has-file .beltoft-qo__submit[disabled]{background:var(--qo-ink);cursor:pointer}

		.beltoft-quick-order__notices{max-width:960px}

		@media (max-width:820px){
			.beltoft-qo__routes{grid-template-columns:1fr}
			.beltoft-qo__submit{align-self:stretch;width:100%}
		}
		</style>
		<form class="beltoft-qo" method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'beltoft_quick_order', 'beltoft_quick_order_nonce' ); ?>

			<div class="beltoft-qo__routes">
				<!--
					Two routes, not two steps - a buyer uses one or the other,
					so neither is numbered and neither is presented first.
				-->
				<section class="beltoft-qo__panel">
					<h2 class="beltoft-qo__title"><?php esc_html_e( 'Paste references', 'beltoft-commerce' ); ?></h2>
					<label class="beltoft-qo__label" for="beltoft-quick-order-pad">
						<?php esc_html_e( 'One reference and quantity per line', 'beltoft-commerce' ); ?>
					</label>
					<textarea id="beltoft-quick-order-pad" name="beltoft_quick_order_pad" rows="9"
						class="beltoft-qo__pad" spellcheck="false" autocomplete="off"
						placeholder="ALL_AN227&#9;12&#10;CD_20153&#9;6&#10;5600221554068&#9;24"></textarea>
					<p class="beltoft-qo__hint">
						<?php esc_html_e( 'Accepts SKU or barcode. Separate with a tab, comma, semicolon or space.', 'beltoft-commerce' ); ?>
					</p>

					<div class="beltoft-qo__go">
						<button type="submit" name="beltoft_qo_route" value="pad" class="beltoft-qo__submit">
							<?php esc_html_e( 'Add to cart', 'beltoft-commerce' ); ?>
						</button>
					</div>
				</section>

				<section class="beltoft-qo__panel" data-qo-sheet>
					<h2 class="beltoft-qo__title"><?php esc_html_e( 'Spreadsheet', 'beltoft-commerce' ); ?></h2>
					<!-- Here the order IS a sequence, so it is numbered. -->
					<ol class="beltoft-qo__steps">
						<li>
							<a class="beltoft-qo__download" href="<?php echo esc_url( $download ); ?>">
								<?php esc_html_e( 'Download your sheet', 'beltoft-commerce' ); ?>
							</a>
							<span class="beltoft-qo__hint"><?php esc_html_e( 'With your prices and references already in it.', 'beltoft-commerce' ); ?></span>
						</li>
						<li><?php esc_html_e( 'Fill in the Quantity column.', 'beltoft-commerce' ); ?></li>
						<li>
							<?php esc_html_e( 'Bring the sheet back here.', 'beltoft-commerce' ); ?>

							<label class="beltoft-qo__drop" for="beltoft-sheet-file">
								<span class="beltoft-qo__drop-lead"><?php esc_html_e( 'Drag the sheet here', 'beltoft-commerce' ); ?></span>
								<span class="beltoft-qo__drop-sub"><?php esc_html_e( 'or click to choose it on your computer (.xlsx)', 'beltoft-commerce' ); ?></span>
								<span class="beltoft-qo__drop-touch"><?php esc_html_e( 'Tap to choose the sheet (.xlsx)', 'beltoft-commerce' ); ?></span>
							</label>

							<div class="beltoft-qo__chosen">
								<span class="beltoft-qo__chosen-tick" aria-hidden="true">&#10003;</span>
								<span class="beltoft-qo__chosen-text">
									<span class="beltoft-qo__chosen-name"></span>
									<span class="beltoft-qo__chosen-size"></span>
								</span>
								<button type="button" class="beltoft-qo__chosen-clear">
									<?php esc_html_e( 'Remove', 'beltoft-commerce' ); ?>
								</button>
							</div>

							<input type="file" id="beltoft-sheet-file" name="beltoft_sheet_file" accept=".xlsx"
								class="beltoft-qo__file-input">
						</li>
					</ol>

					<div class="beltoft-qo__go">
						<button type="submit" name="beltoft_qo_route" value="sheet" class="beltoft-qo__submit"
							data-qo-import disabled>
							<?php esc_html_e( 'Import and add to cart', 'beltoft-commerce' ); ?>
						</button>
					</div>
				</section>
			</div>
		</form>
		<script>
		(function(){
			var input = document.getElementById('beltoft-sheet-file');
			var panel = document.querySelector('[data-qo-sheet]');
			if (!input || !panel) { return; }

			var name   = panel.querySelector('.beltoft-qo__chosen-name');
			var size   = panel.querySelector('.beltoft-qo__chosen-size');
			var clear  = panel.querySelector('.beltoft-qo__chosen-clear');
			var button = panel.querySelector('[data-qo-import]');

			function human(bytes) {
				if (bytes < 1024) { return bytes + ' B'; }
				if (bytes < 1024 * 1024) { return Math.round(bytes / 1024) + ' KB'; }
				return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
			}

			function show() {
				var file = input.files && input.files[0];
				panel.classList.toggle('beltoft-qo--has-file', !!file);
				button.disabled = !file;
				if (!file) { return; }
				name.textContent = file.name;
				size.textContent = human(file.size);
			}

			input.addEventListener('change', show);

			clear.addEventListener('click', function () {
				input.value = '';
				show();
				input.focus();
			});

			// Drag and drop. Every dragover must be cancelled or the browser
			// navigates away to the file instead of dropping it here.
			['dragenter', 'dragover'].forEach(function (type) {
				panel.addEventListener(type, function (e) {
					e.preventDefault();
					panel.classList.add('beltoft-qo--dragging');
				});
			});

			['dragleave', 'dragend'].forEach(function (type) {
				panel.addEventListener(type, function (e) {
					// relatedTarget is null, or outside, when the pointer has
					// really left - without this the highlight flickers off
					// every time the cursor crosses a child element.
					if (!e.relatedTarget || !panel.contains(e.relatedTarget)) {
						panel.classList.remove('beltoft-qo--dragging');
					}
				});
			});

			panel.addEventListener('drop', function (e) {
				e.preventDefault();
				panel.classList.remove('beltoft-qo--dragging');

				var dropped = e.dataTransfer && e.dataTransfer.files;
				if (!dropped || !dropped.length) { return; }

				// DataTransfer is the only way to put a dropped file into a
				// file input, and it is what makes the normal POST carry it.
				var box = new DataTransfer();
				box.items.add(dropped[0]);
				input.files = box.files;

				show();
			});

			// A drop anywhere else on the page would otherwise open the file
			// in this tab and lose whatever the buyer had typed.
			['dragover', 'drop'].forEach(function (type) {
				window.addEventListener(type, function (e) {
					if (!panel.contains(e.target)) { e.preventDefault(); }
				});
			});

			show();
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}
}
