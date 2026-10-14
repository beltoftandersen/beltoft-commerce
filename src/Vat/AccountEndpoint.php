<?php

namespace BeltoftCommerce\Vat;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * Fiscal data as a My Account section.
 *
 * A partner supplies their VAT number once, on the application form, and until
 * now had nowhere to look at it again. Correcting a typo meant going through
 * checkout, which is the wrong place to discover that the number you have been
 * trading under all year has a digit missing.
 *
 * NOTE: adding an endpoint adds a rewrite rule. Rules are flushed once, guarded
 * by an option, rather than on every request - flushing on init is a well-known
 * way to make a site slow for reasons nobody can find later. Follows
 * SavedCart\AccountEndpoint, which does the same thing for the same reason.
 *
 * WHY THE NUMBER IS RE-CHECKED ON EVERY SAVE rather than trusted from last
 * time: a company can be deregistered, and VIES answers about today. The
 * decision, the VIES request identifier and the timestamp are stored beside the
 * number so the evidence is the evidence for that number, not for whatever was
 * there before.
 */
class AccountEndpoint {

	public const ENDPOINT = 'dados-fiscais';

	/** Capped so one determined partner cannot grow a row without limit. */
	public const HISTORY_KEY = '_vat_number_history';
	private const HISTORY_MAX = 20;

	private const NONCE = 'beltoft_vat_account';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
	}

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( ! get_option( 'beltoft_vat_account_endpoint_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'beltoft_vat_account_endpoint_flushed', '1', false );
		}
	}

	/**
	 * @param array $vars
	 * @return array
	 */
	public static function query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Trade only.
	 *
	 * A retail customer's NIF is asked for at checkout by the NIF plugin and
	 * belongs to the order, not to a standing account relationship. A partner's
	 * number decides whether every invoice they get carries IVA, which is worth
	 * a page of its own.
	 *
	 * @param array $items
	 * @return array
	 */
	public static function menu_item( $items ) {
		if ( ! Hosts::is_b2b_host() || ! is_array( $items ) ) {
			return $items;
		}

		$new = array();

		foreach ( $items as $key => $label ) {
			// Above Edit account, which is the other "my details" page.
			if ( 'edit-account' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Dados fiscais', 'beltoft-commerce' );
			}

			$new[ $key ] = $label;
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Dados fiscais', 'beltoft-commerce' );
		}

		return $new;
	}

	private static function billing_country( int $user_id ): string {
		$country = (string) get_user_meta( $user_id, 'billing_country', true );

		return '' !== $country ? $country : Validator::HOME_COUNTRY;
	}

	/**
	 * Trade host only, checked here and in content().
	 *
	 * Putting the gate on the menu item alone left the page reachable by URL on
	 * retail: the rewrite endpoint is registered on both hosts, so any
	 * logged-in customer could open it and set the _vat_number meta that
	 * Exemption reads at woocommerce_before_calculate_totals - granting
	 * themselves reverse charge on a consumer order.
	 */
	private static function host_allows(): bool {
		return Hosts::is_b2b_host();
	}

	/**
	 * Post, then redirect.
	 *
	 * Rendering the result of a POST straight into the endpoint would leave a
	 * refresh re-submitting it, which on this page means a second VIES call and
	 * a duplicate history row for a change nobody made.
	 */
	public static function handle_post(): void {
		if ( ! isset( $_POST['beltoft_vat_account_submit'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! is_account_page() || ! self::host_allows() ) {
			return;
		}

		global $wp_query;

		if ( ! isset( $wp_query->query_vars[ self::ENDPOINT ] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$user_id   = get_current_user_id();
		$submitted = isset( $_POST['beltoft_vat_number'] )
			? sanitize_text_field( wp_unslash( $_POST['beltoft_vat_number'] ) )
			: '';

		$previous = (string) get_user_meta( $user_id, Field::META_KEY, true );
		$decision = Validator::decide( $submitted, self::billing_country( $user_id ) );
		$result   = AccountUpdate::from_decision( $decision, $submitted );

		if ( AccountUpdate::REFUSED === $result['outcome'] ) {
			wc_add_notice( self::refusal_message( $result['reason'] ), 'error' );
		} else {
			self::store( $user_id, $decision, $result, $previous );
			wc_add_notice( self::success_message( $result, $decision ), 'success' );
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	private static function store( int $user_id, VatDecision $decision, array $result, string $previous ): void {
		$stored = AccountUpdate::CLEARED === $result['outcome'] ? '' : $decision->vat_number;

		Evidence::store( $user_id, $stored, $decision, $result['status'] );

		$history = get_user_meta( $user_id, self::HISTORY_KEY, true );
		$history = is_array( $history ) ? $history : array();

		$history[] = array(
			'from'       => $previous,
			'to'         => $stored,
			'status'     => $result['status'],
			'changed_at' => gmdate( 'c' ),
			'changed_by' => $user_id,
		);

		update_user_meta( $user_id, self::HISTORY_KEY, array_slice( $history, -self::HISTORY_MAX ) );
	}

	private static function refusal_message( string $reason ): string {
		if ( 'unreadable' === $reason ) {
			return __( 'Não foi possível ler um número no que escreveu. O número anterior não foi alterado.', 'beltoft-commerce' );
		}

		if ( 'pt_check_digit' === $reason ) {
			return __( 'O número de contribuinte indicado não é válido. O número anterior não foi alterado.', 'beltoft-commerce' );
		}

		return __( 'Este número de IVA não foi reconhecido pelo VIES. O número anterior não foi alterado.', 'beltoft-commerce' );
	}

	private static function success_message( array $result, VatDecision $decision ): string {
		if ( AccountUpdate::CLEARED === $result['outcome'] ) {
			return __( 'Número de IVA removido da sua conta.', 'beltoft-commerce' );
		}

		if ( 'unverified' === $result['status'] ) {
			return __( 'Número guardado. Não foi possível contactar o VIES neste momento, por isso ainda não está confirmado.', 'beltoft-commerce' );
		}

		if ( 'valid' === $result['status'] ) {
			return __( 'Número de IVA confirmado pelo VIES e guardado.', 'beltoft-commerce' );
		}

		if ( '' === $result['status'] ) {
			return __( 'Número guardado. Não é um número de IVA da União Europeia, por isso não há autoliquidação.', 'beltoft-commerce' );
		}

		return __( 'Número de contribuinte guardado.', 'beltoft-commerce' );
	}

	/**
	 * Human wording for a stored status.
	 *
	 * 'domestic' is the one worth reading carefully: it means the number was
	 * never sent to VIES, because a Portuguese number is not a zero-rating
	 * question. Saying "confirmado" there would claim something nobody checked.
	 */
	private static function status_line( string $status, bool $has_number = false ): string {
		/*
		 * Validator returns an empty status for two different things: no number
		 * at all, and a number from outside the EU, which is out of scope for
		 * VIES and handled as an export at checkout. Reading both as "no
		 * number" told a Swiss partner their number was saved and then printed
		 * "Sem número de IVA associado" directly above it.
		 */
		if ( '' === $status && $has_number ) {
			return __( 'Número registado. Não é um número de IVA da União Europeia, por isso as faturas levam IVA à taxa portuguesa.', 'beltoft-commerce' );
		}

		switch ( $status ) {
			case 'valid':
				return __( 'Confirmado pelo VIES. As suas faturas são emitidas com autoliquidação, sem IVA português.', 'beltoft-commerce' );
			case 'domestic':
				return __( 'Número português. Não é enviado ao VIES e as suas faturas levam IVA à taxa portuguesa.', 'beltoft-commerce' );
			case 'unverified':
				return __( 'Ainda não confirmado — o VIES não respondeu da última vez. Guarde novamente mais tarde para tentar outra vez.', 'beltoft-commerce' );
			case 'invalid':
				return __( 'O VIES não reconheceu este número.', 'beltoft-commerce' );
			default:
				return __( 'Sem número de IVA associado. As suas faturas levam IVA à taxa portuguesa.', 'beltoft-commerce' );
		}
	}

	/**
	 * Echoed, not passed through wp_kses_post().
	 *
	 * That allowlist is for untrusted post content: it strips <form> and
	 * <input> outright, which on this page would leave a heading, no form and
	 * nothing to say anything was wrong. The markup is ours and every value in
	 * it is escaped where it is written.
	 */
	public static function content(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! self::host_allows() ) {
			return;
		}

		$number   = (string) get_user_meta( $user_id, Field::META_KEY, true );
		$status   = (string) get_user_meta( $user_id, OrderEvidence::CHECK_STATUS, true );
		$name     = (string) get_user_meta( $user_id, OrderEvidence::REGISTERED, true );
		$checked  = (string) get_user_meta( $user_id, OrderEvidence::REQUEST_DATE, true );

		echo '<h2>' . esc_html__( 'Dados fiscais', 'beltoft-commerce' ) . '</h2>';

		echo '<p>' . esc_html__( 'O número que usamos para emitir as suas faturas. Alterá-lo aqui verifica-o outra vez.', 'beltoft-commerce' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Estado:', 'beltoft-commerce' ) . '</strong> ' . esc_html( self::status_line( $status, '' !== $number ) ) . '</p>';

		if ( '' !== $name ) {
			echo '<p><strong>' . esc_html__( 'Registado como:', 'beltoft-commerce' ) . '</strong> ' . esc_html( $name ) . '</p>';
		}

		if ( '' !== $checked ) {
			$timestamp = strtotime( $checked );

			if ( $timestamp ) {
				echo '<p><strong>' . esc_html__( 'Verificado em:', 'beltoft-commerce' ) . '</strong> '
					. esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $timestamp ) ) . '</p>';
			}
		}

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE );

		echo '<p class="form-row form-row-wide">';
		echo '<label for="beltoft_vat_number">' . esc_html__( 'Número de contribuinte (NIF/NIPC) ou número de IVA europeu', 'beltoft-commerce' ) . '</label>';
		echo '<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="beltoft_vat_number" id="beltoft_vat_number" autocomplete="off" value="' . esc_attr( $number ) . '">';
		echo '</p>';

		echo '<p>';
		echo '<button type="submit" class="woocommerce-Button button" name="beltoft_vat_account_submit" value="1">'
			. esc_html__( 'Guardar e verificar', 'beltoft-commerce' ) . '</button>';
		echo '</p>';

		echo '<p><small>' . esc_html__( 'Deixe em branco se não tiver número de IVA. Um número de outro país da União Europeia é verificado no VIES da Comissão Europeia.', 'beltoft-commerce' ) . '</small></p>';

		echo '</form>';
	}
}
