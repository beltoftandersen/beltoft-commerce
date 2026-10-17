<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Where an application stands, and the only two ways it moves.
 *
 * STATUS IS NOT THE ROLE, and keeping them apart is the point. The role answers
 * "what may this account do" and is what every pricing and enforcement decision
 * reads; the status answers "what did we decide about this application" and is
 * what the shop reads. A rejected account always ends up with the b2b_pending
 * role, which is retail-equivalent by design (§2.2): an applicant loses nothing
 * they had, and a partner whose access is revoked loses the wholesale role.
 *
 * Deliberately NOT inferred from the role. "b2b_pending with no decision" and
 * "b2b_pending, rejected" are different things to a human and identical to a
 * role check, and the shop needs to tell them apart to know whose application
 * still needs looking at.
 */
class Status {

	public const META_STATUS   = '_b2b_status';
	public const META_REASON   = '_b2b_rejected_reason';
	public const META_DECIDED  = '_b2b_decided_at';
	public const META_APPLIED  = '_b2b_applied_at';

	public const PENDING  = 'pending';
	public const APPROVED = 'approved';
	public const REJECTED = 'rejected';

	public static function get( int $user_id ): string {
		$status = (string) get_user_meta( $user_id, self::META_STATUS, true );
		$roles  = (array) ( get_userdata( $user_id )->roles ?? array() );

		/*
		 * The role wins where the two disagree, because the role is what the
		 * account can actually do. An account holding the wholesale role is a
		 * partner - given it directly (the user editor, an import) or approved
		 * - and an 'approved' account that no longer holds it has had its
		 * access taken away, by hand in the user editor: shown as Approved it
		 * sat on retail prices, in neither queue, and Approve refused it.
		 */
		if ( in_array( 'b2b_customer', $roles, true ) ) {
			return self::APPROVED;
		}

		if ( self::APPROVED === $status ) {
			return self::REJECTED;
		}

		return in_array( $status, array( self::PENDING, self::REJECTED ), true ) ? $status : self::PENDING;
	}

	/**
	 * Keep the recorded decision in step with a role changed by hand: given
	 * the wholesale role, the account is approved; moved from it to
	 * b2b_pending, rejected.
	 *
	 * @param int      $user_id
	 * @param string   $role
	 * @param string[] $old_roles
	 */
	public static function sync_with_role( $user_id, $role, $old_roles = array() ): void {
		$user_id = (int) $user_id;

		if ( 'b2b_customer' === $role && self::APPROVED !== (string) get_user_meta( $user_id, self::META_STATUS, true ) ) {
			update_user_meta( $user_id, self::META_STATUS, self::APPROVED );
			update_user_meta( $user_id, self::META_DECIDED, gmdate( 'c' ) );
		} elseif ( 'b2b_pending' === $role && in_array( 'b2b_customer', (array) $old_roles, true ) ) {
			update_user_meta( $user_id, self::META_STATUS, self::REJECTED );
			update_user_meta( $user_id, self::META_DECIDED, gmdate( 'c' ) );
		}
	}

	public static function is_pending_decision( int $user_id ): bool {
		return self::PENDING === self::get( $user_id ) && user_can( $user_id, 'read' )
			&& in_array( 'b2b_pending', (array) ( get_userdata( $user_id )->roles ?? array() ), true );
	}

	/**
	 * Approve: this is the one place the wholesale role is granted.
	 *
	 * Only to an applicant - an account holding b2b_pending, rejected ones
	 * included so a refusal can be reversed. The bulk action is offered on
	 * every Users view and an account with no status reads as pending, so
	 * without this an approval run on the plain list replaced the role of
	 * administrators (the one running it included) and retail customers.
	 */
	public static function approve( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || self::APPROVED === self::get( $user_id ) ) {
			return false;
		}

		if ( ! in_array( 'b2b_pending', (array) $user->roles, true ) ) {
			return false;
		}

		$user->set_role( 'b2b_customer' );

		update_user_meta( $user_id, self::META_STATUS, self::APPROVED );
		update_user_meta( $user_id, self::META_DECIDED, gmdate( 'c' ) );
		delete_user_meta( $user_id, self::META_REASON );

		do_action( 'beltoft_commerce_b2b_approved', $user_id );

		return true;
	}

	/**
	 * Reject: the account ends up as b2b_pending, whatever it was.
	 *
	 * b2b_pending resolves to the retail catalog and retail prices. For an
	 * applicant that is the role they already have; for an approved partner,
	 * rejecting is how access is revoked, so b2b_customer is taken away here -
	 * otherwise they kept wholesale prices and fell out of every list. Keeping
	 * b2b_pending rather than dropping to customer also keeps the account under
	 * the rejected filter instead of disappearing into twenty-three thousand
	 * retail customers, which is what makes a reapplication or a later
	 * reversal findable.
	 *
	 * Only B2B accounts can be rejected. Because this changes the role, an
	 * administrator or retail customer caught in a bulk action on the plain
	 * Users list must be left exactly as they were.
	 */
	public static function reject( int $user_id, string $reason = '' ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || self::REJECTED === self::get( $user_id ) ) {
			return false;
		}

		$roles    = (array) $user->roles;
		$override = (string) get_user_meta( $user_id, \BeltoftCommerce\Pricing\PricingContext::OVERRIDE_META, true );

		/*
		 * A B2B account, or a plain customer holding trade terms through the
		 * per-user group. Never staff: an administrator or shop manager with
		 * the override, caught in a bulk Reject, would lose wp-admin.
		 */
		$plain_customer = '' !== $override && ! array_diff( $roles, array( 'customer', 'subscriber' ) );

		if ( ! array_intersect( $roles, array( 'b2b_pending', 'b2b_customer' ) ) && ! $plain_customer ) {
			return false;
		}

		if ( array( 'b2b_pending' ) !== array_values( $roles ) ) {
			$user->set_role( 'b2b_pending' );
		}

		// Revoking access takes the per-user group too: PricingContext reads it
		// before the role, so it kept wholesale terms after the rejection.
		delete_user_meta( $user_id, \BeltoftCommerce\Pricing\PricingContext::OVERRIDE_META );

		update_user_meta( $user_id, self::META_STATUS, self::REJECTED );
		update_user_meta( $user_id, self::META_DECIDED, gmdate( 'c' ) );

		if ( '' !== $reason ) {
			update_user_meta( $user_id, self::META_REASON, sanitize_textarea_field( $reason ) );
		}

		do_action( 'beltoft_commerce_b2b_rejected', $user_id, $reason );

		return true;
	}
}
