<?php

namespace BeltoftCommerce\Bricks;

use BeltoftCommerce\Support\Hosts;

defined( 'ABSPATH' ) || exit;

/**
 * A Bricks template condition keyed on the request host.
 *
 * Bricks conditions cover post type, terms and user role, but not hostname,
 * and the retail/B2B template switch needs one. Because host controls
 * presentation only, this is the sole place hostname may appear in
 * application logic. See architecture §2.3 and §2.11.
 */
class HostCondition {

	private const KEY = 'beltoft_host';

	public static function register(): void {
		add_filter( 'bricks/conditions/options', array( __CLASS__, 'register_option' ) );
		add_filter( 'bricks/conditions/result', array( __CLASS__, 'result' ), 10, 3 );
	}

	public static function register_option( $options ) {
		$options   = is_array( $options ) ? $options : array();
		$options[] = array(
			'key'     => self::KEY,
			'label'   => __( 'Host (retail / B2B)', 'beltoft-commerce' ),
			'group'   => 'other',
			'compare' => array(
				'type'        => 'select',
				'options'     => array(
					'==' => esc_html__( 'is', 'beltoft-commerce' ),
					'!=' => esc_html__( 'is not', 'beltoft-commerce' ),
				),
				'placeholder' => esc_html__( 'is', 'beltoft-commerce' ),
			),
			'value'   => array(
				'type'        => 'select',
				'options'     => array(
					'retail' => __( 'Retail host', 'beltoft-commerce' ),
					'b2b'    => __( 'B2B host', 'beltoft-commerce' ),
				),
				'placeholder' => __( 'Select host', 'beltoft-commerce' ),
			),
		);

		return $options;
	}

	public static function evaluate( array $condition ): bool {
		$value = $condition['value'] ?? '';

		if ( 'b2b' === $value ) {
			return Hosts::is_b2b_host();
		}

		// The retail host itself; another host the install answers on is neither.
		if ( 'retail' === $value ) {
			return Hosts::is_retail_host();
		}

		return false;
	}

	public static function result( $result, $condition_key, $condition ) {
		if ( self::KEY !== $condition_key ) {
			return $result;
		}

		$matches = self::evaluate( (array) $condition );

		return ( '!=' === ( $condition['compare'] ?? '==' ) ) ? ! $matches : $matches;
	}
}
