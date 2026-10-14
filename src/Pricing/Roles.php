<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Roles and their mapping to pricing groups.
 *
 * Roles answer "what may this user access?"; pricing groups answer "which
 * commercial terms apply?". They happen to line up on day one but are kept
 * separate so the WordPress role system does not become the commercial
 * pricing model. See architecture §2.2.
 */
class Roles {

	public static function install(): void {
		// b2b_pending has exactly the customer capability set. It is a
		// retail customer who applied, nothing more.
		$customer = get_role( 'customer' );
		$caps     = $customer ? $customer->capabilities : array( 'read' => true );

		/*
		 * "APPLICANT", NOT "PENDING APPROVAL", and the distinction is the whole
		 * reason for the rename.
		 *
		 * A rejected applicant KEEPS this role - Registration\Status explains
		 * why: rejection does not touch the account, because b2b_pending is
		 * retail-equivalent and the alternative is deleting or downgrading
		 * somebody who is still a perfectly good retail customer. So the role
		 * holds everyone who applied and was not approved, which is pending
		 * AND rejected, and calling it "pending approval" said something untrue
		 * about the rejected ones.
		 *
		 * It also read as a second, disagreeing copy of the Users screen's
		 * "B2B por aprovar" filter, which counts only applications still
		 * awaiting a decision. Two links, two numbers, both apparently about
		 * pending applications. The role describes what the account IS; that
		 * filter is the queue of work. Naming them apart is what makes the
		 * different counts legible instead of alarming.
		 */
		$applicant = __( 'B2B (applicant)', 'beltoft-commerce' );

		/*
		 * All three go through the same path, so the Users screen does not end
		 * up half translated. These names are written into the wp_user_roles
		 * option when the role is created and never revisited, so a role made
		 * before the translations were loaded keeps the English source forever
		 * - which is how "B2B (pending approval)" came to sit beside a
		 * Portuguese filter link in a Portuguese admin.
		 */
		self::ensure( 'b2b_pending', $applicant, $caps );
		self::ensure( 'b2b_customer', __( 'B2B Customer', 'beltoft-commerce' ), $caps );

		// The till operator. Same capabilities as a customer - the role exists
		// to select a pricing group and a stock pool, not to grant anything.
		self::ensure( 'pos_operator', __( 'POS', 'beltoft-commerce' ), $caps );
	}

	/**
	 * Create the role, or correct its name if it is already there.
	 *
	 * @param array<string,bool> $caps
	 */
	private static function ensure( string $slug, string $label, array $caps ): void {
		if ( get_role( $slug ) ) {
			self::rename( $slug, $label );

			return;
		}

		add_role( $slug, $label, $caps );
	}

	/**
	 * Change what an existing role is called, leaving everything else alone.
	 *
	 * add_role() only ever creates - it returns null for a role that already
	 * exists and changes nothing - and remove_role() would take the
	 * capabilities and every user's membership with it. The display name lives
	 * in the wp_user_roles option beside the capabilities, so it is edited
	 * there, in place.
	 *
	 * WRITES UNCONDITIONALLY, and the obvious optimisation is why.
	 *
	 * Skipping the write when the name already matches sounds free, but the
	 * name it compares is wp_roles()'s - a global object held for the life of
	 * the request - while the name that matters is the one in the option. Let
	 * those two diverge and the guard reads the in-memory copy, finds it
	 * already correct, and never repairs the stored one: the function declines
	 * to do the only thing it exists for, silently, precisely when it is
	 * needed. Its test caught exactly that, because the test suite rolls the
	 * database back between tests and keeps the global.
	 *
	 * install() is not a hot path - nothing calls it on a normal request - so
	 * the option write costs nothing worth protecting.
	 */
	private static function rename( string $slug, string $label ): void {
		$roles = wp_roles();

		if ( ! isset( $roles->roles[ $slug ] ) ) {
			return;
		}

		$roles->roles[ $slug ]['name'] = $label;
		$roles->role_names[ $slug ]    = $label;

		update_option( $roles->role_key, $roles->roles );
	}

	/**
	 * Explicit, exhaustive mapping driven by the registry.
	 *
	 * NEVER pattern-match a role name here. A check like
	 * str_starts_with( $role, 'b2b' ) silently grants an unapproved signup
	 * wholesale pricing, and that is the bug §2.2 says will not be caught
	 * in review. Only a role listed against a group in the registry maps to
	 * that group; everything else is retail.
	 *
	 * @param string[] $roles
	 */
	public static function group_for_roles( array $roles ): string {
		foreach ( Registry::groups() as $slug => $group ) {
			foreach ( (array) ( $group['roles'] ?? array() ) as $mapped_role ) {
				if ( in_array( $mapped_role, $roles, true ) ) {
					return (string) $slug;
				}
			}
		}

		return Registry::retail_slug();
	}
}
