<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Approving applications on the Users screen the shop already uses.
 *
 * WHY HERE AND NOT A NEW SCREEN. A dedicated review page is one more place to
 * remember to look, and the thing that actually gets checked is the screen
 * people already open. This adds a filter link with a count, the three columns
 * needed to make a decision, and two bulk actions - so approving five
 * applications is five ticks and one dropdown.
 *
 * The count in the filter link is the discovery mechanism: an application that
 * arrives while nobody is looking still shows up as a number the next time
 * anyone opens Users.
 */
class AdminUsers {

	private const FILTER_KEY = 'beltoft_b2b_status';

	public static function register(): void {
		add_filter( 'views_users', array( __CLASS__, 'add_view' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'filter_query' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_panel' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_panel' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	/**
	 * Pending is "no decision", not "the word pending".
	 *
	 * Status::get() treats anything it does not recognise as pending, and this
	 * has to agree with it or the two disagree about the same account. Matching
	 * the literal string meant an account whose role a shop manager set to
	 * b2b_pending by hand - an obvious thing to do from this very screen -
	 * showed "pending" on its profile and appeared in neither the count nor the
	 * filter. Asking instead for "not approved and not rejected" also catches
	 * a status left behind by an older version or a half-finished import, which
	 * the missing-meta test alone would not. The count is the discovery
	 * mechanism; an applicant it cannot see is an applicant nobody looks at.
	 */
	private static function pending_meta_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => Status::META_STATUS,
				'value'   => array( Status::APPROVED, Status::REJECTED ),
				'compare' => 'NOT IN',
			),
			array(
				'key'     => Status::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Which role a decision leaves an account in.
	 *
	 * Approving is the one place the wholesale role is granted
	 * (Status::approve), so an approved account is a b2b_customer and looking
	 * for it under b2b_pending finds nothing - which is exactly what the
	 * approved filter did, silently, for as long as nothing linked to it.
	 */
	private static function role_for( string $status ): string {
		return Status::APPROVED === $status ? 'b2b_customer' : 'b2b_pending';
	}

	private static function meta_query_for( string $status ): array {
		return Status::PENDING === $status
			? self::pending_meta_query()
			: array(
				array(
					'key'   => Status::META_STATUS,
					'value' => $status,
				),
			);
	}

	private static function count_for( string $status ): int {
		$q = new \WP_User_Query( array(
			'role'        => self::role_for( $status ),
			'meta_query'  => self::meta_query_for( $status ),
			'fields'      => 'ID',
			'number'      => -1,
			'count_total' => true,
		) );

		return (int) $q->get_total();
	}

	/**
	 * The B2B links on the Users screen: the queue, and the refusals.
	 *
	 * WordPress adds one link per role, which answers "what may this account
	 * do". For B2B that is the wrong question twice over. The b2b_pending role
	 * holds applications AND refusals - a rejected applicant keeps it, because
	 * rejection deliberately leaves the account alone - so its link was a
	 * single number covering two unrelated piles of work, sitting next to this
	 * class's own pending count and disagreeing with it. Two links, two
	 * numbers, both apparently about pending applications.
	 *
	 * So that role link is removed and replaced by the two questions a person
	 * actually has: who is waiting for an answer, and who was turned down.
	 * Approved needs no link of its own - approving grants the b2b_customer
	 * role, so WordPress's own "Cliente B2B" link is that list.
	 *
	 * The role itself is untouched and still appears on profiles and in the
	 * role dropdown; only the filter link goes.
	 */
	public static function add_view( $views ) {
		unset( $views['b2b_pending'] );

		// phpcs:ignore WordPress.Security.NonceVerification -- reading a list filter.
		$active = isset( $_GET[ self::FILTER_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_KEY ] ) ) : '';

		/*
		 * WordPress decides which link is current by looking at `role`, sees
		 * nothing it knows, and marks "All" - so with one of these filters on,
		 * two links were highlighted at once and the bar said the list was both
		 * everybody and a subset. It cannot be taught about this query arg, so
		 * the highlight is taken off the others instead.
		 */
		if ( in_array( $active, array( Status::PENDING, Status::APPROVED, Status::REJECTED ), true ) ) {
			foreach ( $views as $key => $html ) {
				$views[ $key ] = is_string( $html ) ? str_replace( ' class="current"', '', $html ) : $html;
			}
		}

		$links = array(
			Status::PENDING  => __( 'B2B por aprovar', 'beltoft-commerce' ),
			Status::REJECTED => __( 'B2B rejeitados', 'beltoft-commerce' ),
		);

		foreach ( $links as $status => $label ) {
			$views[ 'beltoft_b2b_' . $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( self::FILTER_KEY, $status, admin_url( 'users.php' ) ) ),
				$active === $status ? ' class="current"' : '',
				esc_html( $label ),
				self::count_for( $status )
			);
		}

		return $views;
	}

	/**
	 * Narrow the Users list, and nothing else.
	 *
	 * pre_get_users fires for EVERY WP_User_Query, not just the list table's.
	 * Unguarded, this rewrote `role` and `meta_query` on any user query built
	 * while the filter link was active - including this class's own
	 * count_for(), and any author or customer lookup a plugin renders on
	 * the same screen. The pagenow check plus the re-entry flag keep it to the
	 * one query it is meant to shape.
	 */
	public static function filter_query( $query ): void {
		static $applied = false;

		if ( $applied || ! is_admin() || ! isset( $_GET[ self::FILTER_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! isset( $GLOBALS['pagenow'] ) || 'users.php' !== $GLOBALS['pagenow'] ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET[ self::FILTER_KEY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// An unrecognised value would otherwise produce a silently empty list.
		if ( ! in_array( $status, array( Status::PENDING, Status::APPROVED, Status::REJECTED ), true ) ) {
			return;
		}

		$applied = true;

		$query->set( 'role', self::role_for( $status ) );
		$query->set( 'meta_query', self::meta_query_for( $status ) );
	}

	public static function columns( $columns ) {
		$columns['beltoft_b2b'] = __( 'Registo B2B', 'beltoft-commerce' );

		return $columns;
	}

	public static function column_value( $output, $column, $user_id ) {
		if ( 'beltoft_b2b' !== $column ) {
			return $output;
		}

		$roles = (array) ( get_userdata( $user_id )->roles ?? array() );

		if ( ! array_intersect( $roles, array( 'b2b_pending', 'b2b_customer' ) ) ) {
			return '—';
		}

		$company = (string) get_user_meta( $user_id, 'billing_company', true );
		$nif     = (string) get_user_meta( $user_id, \BeltoftCommerce\Vat\Field::META_KEY, true );
		$state   = (string) get_user_meta( $user_id, 'billing_state', true );
		$status  = Status::get( (int) $user_id );

		$labels = array(
			Status::PENDING  => __( 'Por aprovar', 'beltoft-commerce' ),
			Status::APPROVED => __( 'Aprovado', 'beltoft-commerce' ),
			Status::REJECTED => __( 'Rejeitado', 'beltoft-commerce' ),
		);

		$lines = array( '<strong>' . esc_html( $labels[ $status ] ?? $status ) . '</strong>' );

		if ( '' !== $company ) {
			$lines[] = esc_html( $company );
		}

		if ( '' !== $nif ) {
			$lines[] = esc_html__( 'NIF', 'beltoft-commerce' ) . ' ' . esc_html( $nif );
		}

		if ( '' !== $state ) {
			$lines[] = esc_html( $state );
		}

		return implode( '<br>', $lines );
	}

	public static function bulk_actions( $actions ) {
		$actions['beltoft_b2b_approve'] = __( 'Aprovar registo B2B', 'beltoft-commerce' );
		$actions['beltoft_b2b_reject']  = __( 'Rejeitar registo B2B', 'beltoft-commerce' );

		return $actions;
	}

	/**
	 * WordPress has already checked the nonce and the screen capability before
	 * this runs, but not that the acting user may edit these particular
	 * accounts - so that is checked per user rather than once for the batch.
	 */
	public static function handle_bulk( $redirect, $action, $user_ids ) {
		if ( ! in_array( $action, array( 'beltoft_b2b_approve', 'beltoft_b2b_reject' ), true ) ) {
			return $redirect;
		}

		$done = 0;

		foreach ( (array) $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				continue;
			}

			$changed = 'beltoft_b2b_approve' === $action
				? Status::approve( $user_id )
				: Status::reject( $user_id );

			if ( $changed ) {
				$done++;
			}
		}

		return add_query_arg( array(
			'beltoft_b2b_done'   => $done,
			'beltoft_b2b_action' => 'beltoft_b2b_approve' === $action ? 'approve' : 'reject',
		), $redirect );
	}

	public static function notice(): void {
		if ( ! isset( $_GET['beltoft_b2b_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$done   = (int) $_GET['beltoft_b2b_done']; // phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_GET['beltoft_b2b_action'] ) ? sanitize_key( wp_unslash( $_GET['beltoft_b2b_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$message = 'approve' === $action
			/* translators: %d: number of accounts. */
			? sprintf( _n( '%d registo aprovado.', '%d registos aprovados.', $done, 'beltoft-commerce' ), $done )
			/* translators: %d: number of accounts. */
			: sprintf( _n( '%d registo rejeitado.', '%d registos rejeitados.', $done, 'beltoft-commerce' ), $done );

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Persist the two bespoke fields.
	 *
	 * A rendered input with no save handler is the worst kind of admin bug: the
	 * form submits, the page reloads, the old value is back, and nothing
	 * anywhere says why. WordPress verifies the profile nonce before these
	 * hooks fire; the capability is checked here because the hook itself does
	 * not.
	 */
	public static function save_profile( $user_id ): void {
		if ( ! current_user_can( 'edit_user', (int) $user_id ) ) {
			return;
		}

		if ( isset( $_POST['beltoft_b2b_shop_name'] ) ) {
			update_user_meta( $user_id, '_b2b_shop_name', sanitize_text_field( wp_unslash( $_POST['beltoft_b2b_shop_name'] ) ) );
		}

		if ( isset( $_POST['beltoft_b2b_company_info'] ) ) {
			update_user_meta( $user_id, '_b2b_company_info', sanitize_textarea_field( wp_unslash( $_POST['beltoft_b2b_company_info'] ) ) );
		}
	}

	/**
	 * The three values with no WooCommerce home of their own.
	 *
	 * Everything else the applicant submitted is a billing field, so it is
	 * already on this screen in WooCommerce's own Customer panel - repeating it
	 * here would create a second place to edit an address and a way for the two
	 * to disagree.
	 */
	public static function profile_panel( $user ): void {
		$roles = (array) $user->roles;

		if ( ! array_intersect( $roles, array( 'b2b_pending', 'b2b_customer' ) ) ) {
			return;
		}

		$can_edit = current_user_can( 'edit_user', $user->ID );
		?>
		<h2><?php esc_html_e( 'Registo B2B', 'beltoft-commerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="beltoft_b2b_shop_name"><?php esc_html_e( 'Nome da loja', 'beltoft-commerce' ); ?></label></th>
				<td><input type="text" name="beltoft_b2b_shop_name" id="beltoft_b2b_shop_name" class="regular-text"
					value="<?php echo esc_attr( (string) get_user_meta( $user->ID, '_b2b_shop_name', true ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
			</tr>
			<tr>
				<th><label for="beltoft_b2b_company_info"><?php esc_html_e( 'Informação da empresa', 'beltoft-commerce' ); ?></label></th>
				<td><textarea name="beltoft_b2b_company_info" id="beltoft_b2b_company_info" rows="4" class="large-text" <?php disabled( ! $can_edit ); ?>><?php
					echo esc_textarea( (string) get_user_meta( $user->ID, '_b2b_company_info', true ) );
				?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Estado', 'beltoft-commerce' ); ?></th>
				<td>
					<p><?php echo esc_html( Status::get( (int) $user->ID ) ); ?></p>
					<?php $reason = (string) get_user_meta( $user->ID, Status::META_REASON, true ); ?>
					<?php if ( '' !== $reason ) : ?>
						<p class="description"><?php echo esc_html( $reason ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
}
