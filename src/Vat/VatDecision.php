<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of applying the §2.10 rules to a supplied VAT number.
 *
 * Display (ex- or incl-IVA) is a function of the pricing group; exemption is
 * a function of a validated VAT number. They are independent — a B2B
 * customer without a valid VAT number sees ex-IVA prices and still pays
 * Portuguese IVA.
 */
class VatDecision {

	public bool $exempt           = false;
	public bool $blocks_checkout  = false;
	public string $status         = '';
	public string $vat_number     = '';
	public string $request_identifier = '';
	public string $registered_name    = '';
	public string $error          = '';
	public int $checked_at        = 0;

	public function __construct( string $status = '', string $vat_number = '' ) {
		$this->status     = $status;
		$this->vat_number = $vat_number;
		$this->checked_at = time();
	}
}
