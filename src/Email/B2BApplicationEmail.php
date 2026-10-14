<?php

namespace BeltoftCommerce\Email;

use BeltoftCommerce\Registration\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for the three B2B application emails.
 *
 * WHY WC_Email AND NOT wp_mail(). These are shop mail: they must carry the
 * store's header, footer and colours, be editable under WooCommerce > Settings
 * > Emails by someone who does not write PHP, and be translatable through the
 * same path as every other WooCommerce notification. A wp_mail() call gets none
 * of that and looks like a bug report.
 *
 * Each subclass supplies its id, its default copy and the action it listens to.
 * Everything below is the part that would otherwise be written three times.
 */
abstract class B2BApplicationEmail extends \WC_Email {

	/** @var \WP_User|null */
	public $user = null;

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = BELTOFT_COMMERCE_PATH . 'templates/';

		parent::__construct();
	}

	/**
	 * @param int $user_id
	 */
	public function trigger( $user_id ): void {
		$this->setup_locale();

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			$this->restore_locale();

			return;
		}

		$this->user      = $user;
		$this->recipient = $user->user_email;

		/*
		 * WC_Email substitutes from the `placeholders` PROPERTY, merged once in
		 * the parent constructor and read by format_string()
		 * (class-wc-email.php:315,381). There is no get_placeholders() method
		 * anywhere in WooCommerce - defining one looks like the API and is
		 * never called, so every custom tag renders as literal braces and the
		 * approved applicant is greeted "Bem-vindo, {company}". The values are
		 * assigned here rather than in the constructor because they describe a
		 * specific applicant, who is not known until the trigger fires.
		 */
		$this->placeholders = array_merge(
			(array) $this->placeholders,
			self::placeholder_values( $user )
		);

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Placeholders available in the subject and heading, so the copy can be
	 * rewritten in the admin without losing the applicant's details.
	 *
	 * `{site_title}` is not included: the parent constructor already adds it,
	 * and repeating it here would silently win over WooCommerce's own value.
	 */
	private static function placeholder_values( \WP_User $user ): array {
		return array(
			'{first_name}'    => $user->first_name,
			'{company}'       => (string) get_user_meta( $user->ID, 'billing_company', true ),
			'{shop_name}'     => (string) get_user_meta( $user->ID, '_b2b_shop_name', true ),
			'{reject_reason}' => (string) get_user_meta( $user->ID, Status::META_REASON, true ),
		);
	}

	/**
	 * Attachment id or URL of the masthead these emails use, in place of the
	 * retail one. Empty falls back to WooCommerce's own setting.
	 */
	public const LOGO_OPTION = 'beltoft_commerce_b2b_email_logo';

	/** Resolved at send time, so replacing the image in the media library is enough. */
	public static function logo_url(): string {
		$value = get_option( self::LOGO_OPTION, '' );

		if ( is_numeric( $value ) ) {
			return (string) wp_get_attachment_url( (int) $value );
		}

		return (string) $value;
	}

	/**
	 * These three go to trade applicants, so they carry the trade masthead.
	 *
	 * Swapped around the render rather than by changing the global setting,
	 * which every retail email reads too. The filter is added and removed
	 * inside this one call, so nothing else sending in the same request — a
	 * customer order confirmation triggered by the same page load, say — picks
	 * up the wrong logo.
	 */
	public function get_content_html(): string {
		$logo = self::logo_url();
		$swap = static function () use ( $logo ) {
			return $logo;
		};

		if ( '' !== $logo ) {
			add_filter( 'option_woocommerce_email_header_image', $swap, 99 );
		}

		try {
			return wc_get_template_html(
				'emails/b2b-application.php',
				array(
					'email'         => $this,
					'user'          => $this->user,
					'email_heading' => $this->get_heading(),
					'body'          => $this->body_copy(),
					'sent_to_admin' => false,
					'plain_text'    => false,
				),
				'',
				$this->template_base
			);
		} finally {
			if ( '' !== $logo ) {
				remove_filter( 'option_woocommerce_email_header_image', $swap, 99 );
			}
		}
	}

	public function get_content_plain(): string {
		return wp_strip_all_tags( $this->get_heading() . "\n\n" . $this->body_copy() );
	}

	/** The one paragraph that differs between the three. */
	abstract protected function body_copy(): string;
}
