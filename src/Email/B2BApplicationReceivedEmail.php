<?php

namespace BeltoftCommerce\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Sent the moment an application is submitted.
 *
 * Its job is to stop the applicant wondering whether the form worked, and to
 * set the expectation that a human decides next. It deliberately promises no
 * timescale, because the shop has not committed to one.
 */
class B2BApplicationReceivedEmail extends B2BApplicationEmail {

	public function __construct() {
		$this->id          = 'beltoft_b2b_application_received';
		$this->title       = __( 'B2B application received', 'beltoft-commerce' );
		$this->description = __( 'Sent to the applicant as soon as the partner application is submitted.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_applied', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'We have received your application — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'We have received your application', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		return __( 'Thank you for applying as a partner. We will review your company\'s details and send you an answer once the review is complete. There is nothing else you need to do for now.', 'beltoft-commerce' );
	}
}
