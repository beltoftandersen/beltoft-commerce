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
		$this->title       = __( 'Registo B2B não aprovado', 'beltoft-commerce' );
		$this->description = __( 'Enviado ao candidato quando o registo de parceiro não é aprovado.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_rejected', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'Sobre o seu registo — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'O seu registo não foi aprovado', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		$copy = __( 'Analisámos o seu registo como parceiro e não foi possível aprová-lo de momento. A sua conta continua ativa e pode comprar normalmente na loja.', 'beltoft-commerce' );

		$reason = $this->user ? (string) get_user_meta( $this->user->ID, Status::META_REASON, true ) : '';

		if ( '' !== $reason ) {
			$copy .= "\n\n" . sprintf(
				/* translators: %s: the reason recorded by the shop. */
				__( 'Motivo: %s', 'beltoft-commerce' ),
				$reason
			);
		}

		return $copy;
	}
}
