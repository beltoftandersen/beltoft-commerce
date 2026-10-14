<?php

namespace BeltoftCommerce\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Sent when an application is approved and the wholesale role is granted.
 *
 * This is the only email of the three that changes what the recipient can do,
 * so it says where to go next rather than just announcing a decision.
 */
class B2BApplicationApprovedEmail extends B2BApplicationEmail {

	public function __construct() {
		$this->id          = 'beltoft_b2b_application_approved';
		$this->title       = __( 'B2B application approved', 'beltoft-commerce' );
		$this->description = __( 'Sent to the customer when their partner application is approved.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_approved', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'Your application has been approved — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'Welcome, {company}', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		return __( 'Your partner application has been approved. You can now sign in and order at your account\'s prices.', 'beltoft-commerce' );
	}
}
