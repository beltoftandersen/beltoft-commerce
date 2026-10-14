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
		$this->title       = __( 'Registo B2B recebido', 'beltoft-commerce' );
		$this->description = __( 'Enviado ao candidato assim que o registo de parceiro é submetido.', 'beltoft-commerce' );

		parent::__construct();

		add_action( 'beltoft_commerce_b2b_applied', array( $this, 'trigger' ), 10, 1 );
	}

	public function get_default_subject(): string {
		return __( 'Recebemos o seu registo — {site_title}', 'beltoft-commerce' );
	}

	public function get_default_heading(): string {
		return __( 'Recebemos o seu registo', 'beltoft-commerce' );
	}

	protected function body_copy(): string {
		return __( 'Obrigado pelo seu registo como parceiro. Vamos analisar os dados da sua empresa e enviamos uma resposta assim que a análise estiver concluída. Não é necessário fazer mais nada de momento.', 'beltoft-commerce' );
	}
}
