<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * The B2B application form, defined once.
 *
 * Every consumer reads this: the Bricks form action that creates the account,
 * the validator, the admin columns and the user-profile panel. A field added
 * here appears in all of them, and one added anywhere else appears in exactly
 * one of them and is silently dropped by the rest.
 *
 * WHERE THE VALUES GO. Almost everything maps onto a WooCommerce billing field
 * rather than bespoke meta. That is not tidiness - it is what makes the first
 * checkout prefill itself, what puts the data in the Customer panel the shop
 * already knows how to edit, and what keeps the address in the one place
 * WooCommerce reads when it calculates tax and shipping. Only three values have
 * no WooCommerce equivalent, and only those three are stored as our own meta.
 */
class Fields {

	/**
	 * @return array<string, array{label: string, type: string, required: bool, meta: string, autocomplete?: string, options?: string}>
	 */
	public static function all(): array {
		return array(
			'email'        => array(
				'label'    => __( 'Email', 'beltoft-commerce' ),
				'type'     => 'email',
				'required' => true,
				'meta'     => 'user_email',
			),
			'company'      => array(
				'label'    => __( 'Company name', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'billing_company',
			),
			'nif'          => array(
				'label'    => __( 'Tax number (NIF/NIPC)', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				/*
				 * Vat\Field::META_KEY - this project's own key, and the same one
				 * the checkout VAT field reads and writes, so a B2B customer
				 * supplies the number once.
				 *
				 * DELIBERATELY NOT billing_nif. Three legacy VAT keys exist on
				 * this install from earlier eras of the store - billing_nif
				 * (7,769 non-empty), billing_VAT_code (7,343) and billing_vat
				 * (343) - and they are largely disjoint populations that
																disagree for 127 users. Reconciling
				 * twelve thousand retail records is a retail problem with its
				 * own decisions to make; B2B accounts are created by this form
				 * and have no legacy value to reconcile with, so they start
				 * clean on one key.
				 *
				 * Consequence worth knowing: the separate NIF checkout plugin
				 * keeps its own billing_nif field, so a B2B customer may still
				 * see that one unfilled at checkout. Mirroring a bare 9-digit PT
				 * number into billing_nif on save would close that, and is one
				 * line whenever it is wanted.
				 */
				'meta'     => \BeltoftCommerce\Vat\Field::META_KEY,
			),
			'first_name'   => array(
				'label'    => __( 'First name', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'first_name',
			),
			'last_name'    => array(
				'label'    => __( 'Last name', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'last_name',
			),
			'address'      => array(
				'label'    => __( 'Address', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'billing_address_1',
			),
			'postcode'     => array(
				'label'    => __( 'Postcode', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'billing_postcode',
			),
			'city'         => array(
				'label'    => __( 'Town / City', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				'meta'     => 'billing_city',
			),
			'country'      => array(
				'label'    => __( 'Country', 'beltoft-commerce' ),
				'type'     => 'select',
				'required' => true,
				'meta'     => 'billing_country',
				'options'  => 'countries',
			),
			'state'        => array(
				'label'    => __( 'District', 'beltoft-commerce' ),
				'type'     => 'select',
				'required' => true,
				/*
				 * A native WooCommerce state, not a list of our own. WooCommerce
				 * already knows the 20 Portuguese districts, so shipping zones and
				 * the address form agree with this field for free.
				 */
				'meta'     => 'billing_state',
				'options'  => 'states',
			),
			'phone'        => array(
				'label'    => __( 'Phone', 'beltoft-commerce' ),
				'type'     => 'tel',
				'required' => true,
				'meta'     => 'billing_phone',
			),
			'company_info' => array(
				'label'    => __( 'Company information', 'beltoft-commerce' ),
				'type'     => 'textarea',
				'required' => true,
				'meta'     => '_b2b_company_info',
			),
			'shop_name'    => array(
				'label'    => __( 'Shop name', 'beltoft-commerce' ),
				'type'     => 'text',
				'required' => true,
				/*
				 * Kept apart from billing_company on purpose: the legal entity on
				 * the invoice and the name over the door are often different, and
				 * collapsing them loses the one the shop actually recognises.
				 */
				'meta'     => '_b2b_shop_name',
			),
			'website'      => array(
				'label'    => __( 'Website URL', 'beltoft-commerce' ),
				'type'     => 'url',
				'required' => false,
				'meta'     => 'user_url',
			),
			'password'     => array(
				'label'    => __( 'Password', 'beltoft-commerce' ),
				'type'     => 'password',
				'required' => true,
				'meta'     => 'user_pass',
			),
		);
	}

	/** Fields stored as our own user meta rather than a WooCommerce one. */
	public static function own_meta_keys(): array {
		return array( '_b2b_shop_name', '_b2b_company_info' );
	}

	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}
}
