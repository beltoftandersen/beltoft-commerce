<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Where an application stands, and the only two ways it moves.
 *
 * STATUS IS NOT THE ROLE, and keeping them apart is the point. The role answers
 * "what may this account do" and is what every pricing and enforcement decision
 * reads; the status answers "what did we decide about this application" and is
 * what the shop reads. A rejected applicant keeps the b2b_pending role, which is
 * retail-equivalent by design (§2.2), so rejection removes nothing they had and
 * grants nothing they should not have - it is a decision recorded, not a
 * punishment applied.
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

		return in_array( $status, array( self::PENDING, self::APPROVED, self::REJECTED ), true )
			? $status
			: self::PENDING;
	}

	public static function is_pending_decision( int $user_id ): bool {
		return self::PENDING === self::get( $user_id ) && user_can( $user_id, 'read' )
			&& in_array( 'b2b_pending', (array) ( get_userdata( $user_id )->roles ?? array() ), true );
	}

	/**
	 * Approve: this is the one place the wholesale role is granted.
	 */
	public static function approve( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || self::APPROVED === self::get( $user_id ) ) {
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
	 * Reject: the role does not change.
	 *
	 * b2b_pending already resolves to the retail catalog and retail prices, so
	 * there is nothing to take away. Leaving the role alone also means the
	 * account stays visible under the pending filter instead of disappearing
	 * into twenty-three thousand retail customers, which is what makes a
	 * reapplication or a later reversal findable.
	 */
	public static function reject( int $user_id, string $reason = '' ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user || self::REJECTED === self::get( $user_id ) ) {
			return false;
		}

		update_user_meta( $user_id, self::META_STATUS, self::REJECTED );
		update_user_meta( $user_id, self::META_DECIDED, gmdate( 'c' ) );

		if ( '' !== $reason ) {
			update_user_meta( $user_id, self::META_REASON, sanitize_textarea_field( $reason ) );
		}

		do_action( 'beltoft_commerce_b2b_rejected', $user_id, $reason );

		return true;
	}
}
