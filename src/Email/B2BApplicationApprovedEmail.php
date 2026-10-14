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
		$this->title       = __( 'Registo B2B aprovado', 'beltoft-commerce' );
		$this->description = __( 'Enviado ao cliente quando o registo de parceiro é aprovado.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_approved', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'O seu registo foi aprovado — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'Bem-vindo, {company}', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		return __( 'O seu registo como parceiro foi aprovado. Já pode iniciar sessão e encomendar com os preços da sua conta.', 'beltoft-commerce' );
	}
}
