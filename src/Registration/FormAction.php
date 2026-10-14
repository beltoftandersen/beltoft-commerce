<?php

namespace BeltoftCommerce\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * The Bricks form action that submits a B2B application.
 *
 * WHY A CUSTOM ACTION AND NOT BRICKS' OWN "registration". Bricks can create a
 * WordPress user from a form, but it maps an email, a password and a role - it
 * has no idea that "Distrito" is billing_state or that the NIF has to be
 * validated before an account exists. Routing the submission here keeps the
 * form itself a normal Bricks form that anyone can restyle in the builder,
 * while the fourteen fields land where Fields:: says they land.
 *
 * REGISTERED BEFORE THE FORM REFERENCES IT. Bricks only accepts a custom action
 * key whose bricks/form/action/{key} hook already exists, so this class is
 * registered on init regardless of whether any form uses it yet.
 */
class FormAction {

	public const ACTION = 'beltoft_b2b_register';

	public static function register(): void {
		add_action( 'bricks/form/action/' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @param object $form Bricks form instance.
	 */
	public static function handle( $form ): void {
		// See RegistrationLimit: the form was an unlimited mail relay.
		if ( ! RegistrationLimit::allows() ) {
			$form->set_result( array(
				'action'  => self::ACTION,
				'type'    => 'error',
				'message' => __( 'Too many requests from this connection. Please try again later.', 'beltoft-commerce' ),
			) );

			return;
		}

		RegistrationLimit::record_attempt();

		$fields   = $form->get_fields();
		$settings = $form->get_settings();

		/*
		 * Bricks submits every field as form-field-{id}, so the form must say
		 * which of its fields is which application field. The mapping lives in
		 * the form settings under our own key rather than being guessed from
		 * labels - a label is written for a human and gets rewritten.
		 */
		$map = isset( $settings['beltoftB2bFieldMap'] ) ? (array) $settings['beltoftB2bFieldMap'] : array();

		if ( empty( $map ) ) {
			$map = self::map_by_field_name( $form );
		}

		$input = array();

		foreach ( $map as $app_key => $field_id ) {
			$input[ $app_key ] = $fields[ 'form-field-' . $field_id ] ?? '';
		}

		$result = Registrar::create( $input );

		if ( is_wp_error( $result ) ) {
			$form->set_result( array(
				'action'  => self::ACTION,
				'type'    => 'error',
				'message' => $result->get_error_message(),
			) );

			return;
		}

		RegistrationLimit::record_application();

		$form->set_result( array(
			'action'  => self::ACTION,
			'type'    => 'success',
			'message' => __( 'Recebemos o seu registo. Enviámos-lhe um email e entraremos em contacto após a análise.', 'beltoft-commerce' ),
		) );
	}

	/**
	 * Fall back to matching on the field's `name` attribute.
	 *
	 * Lets a form be wired by naming each field after its application key -
	 * name="nif", name="shop_name" - which is far easier to get right in the
	 * builder than copying six-character field ids into a settings array.
	 */
	private static function map_by_field_name( $form ): array {
		$settings = $form->get_settings();
		$map      = array();
		$known    = array_keys( Fields::all() );

		foreach ( (array) ( $settings['fields'] ?? array() ) as $field ) {
			$name = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';

			if ( '' !== $name && in_array( $name, $known, true ) && isset( $field['id'] ) ) {
				$map[ $name ] = $field['id'];
			}
		}

		return $map;
	}
}
