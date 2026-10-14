<?php

namespace BeltoftCommerce\Email;

use BeltoftCommerce\Registration\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Sent when an application is not approved.
 *
 * The account is untouched - b2b_pending is retail-equivalent - so the copy
 * says what remains true rather than implying the account is gone. If a reason
 * was recorded it is included, because "no" without a reason produces a reply
 * asking for one.
 */
class B2BApplicationRejectedEmail extends B2BApplicationEmail {

	public function __construct() {
		$this->id          = 'beltoft_b2b_application_rejected';
		$this->title       = __( 'B2B application not approved', 'beltoft-commerce' );
		$this->description = __( 'Sent to the applicant when the partner application is not approved.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_rejected', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'About your application — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your application was not approved', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		$copy = __( 'We have reviewed your partner application and could not approve it at this time. Your account remains active and you can shop in the store as usual.', 'beltoft-commerce' );

		$reason = $this->user ? (string) get_user_meta( $this->user->ID, Status::META_REASON, true ) : '';

		if ( '' !== $reason ) {
			$copy .= "\n\n" . sprintf(
				/* translators: %s: the reason recorded by the shop. */
				__( 'Reason: %s', 'beltoft-commerce' ),
				$reason
			);
		}

		return $copy;
	}
}
