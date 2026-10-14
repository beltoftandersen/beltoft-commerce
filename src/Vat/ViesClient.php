<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * VIES REST consultation.
 *
 * No key or registration is required. Our own VAT number is passed as the
 * requester, which makes VIES return a `requestIdentifier` — the
 * consultation number. It is the only field that cannot be reconstructed
 * after the fact, so it is captured on every check. Architecture §2.10.
 *
 * VIES is a federation of member state services and individual countries go
 * offline regularly. A transport failure, a timeout, a non-2xx response or
 * an unrecognised body shape all yield `unreachable`, never `invalid` — the
 * difference decides whether a customer's checkout is blocked.
 */
class ViesClient {

	private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

	private const CACHE_PREFIX = 'beltoft_vies_';
	private const CACHE_TTL    = DAY_IN_SECONDS;

	/**
	 * Our own VAT number, used as the requester so VIES returns a
	 * consultation number. Configurable rather than a literal.
	 */
	public static function our_vat_number(): string {
		return (string) apply_filters(
			'beltoft_commerce_own_vat_number',
			(string) get_option( 'beltoft_commerce_own_vat_number', '' )
		);
	}

	/**
	 * @param bool $allow_remote When false, answer from cache or report the
	 *                           service as unreachable, but never make the
	 *                           HTTP call. Cart totals are recalculated on
	 *                           nearly every request, and a member state
	 *                           endpoint taking its full 10s timeout inside
	 *                           that path would stall the page. An
	 *                           unreachable answer is not an exemption, so
	 *                           declining to ask fails closed.
	 */
	public static function check( string $country, string $number, bool $allow_remote = true ): ViesResult {
		$country = strtoupper( trim( $country ) );
		$number  = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', trim( $number ) ) );

		$cache_key = self::CACHE_PREFIX . $country . '_' . $number;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return new ViesResult(
				$cached['status'],
				$cached['request_identifier'] ?? '',
				$cached['registered_name'] ?? '',
				'',
				(int) ( $cached['checked_at'] ?? 0 )
			);
		}

		if ( ! $allow_remote ) {
			return new ViesResult( 'unreachable', '', '', 'not checked in this context' );
		}

		$body = array(
			'countryCode' => $country,
			'vatNumber'   => $number,
		);

		$own = self::our_vat_number();

		if ( '' !== $own ) {
			$body['requesterMemberStateCode'] = substr( $own, 0, 2 );
			$body['requesterNumber']          = substr( $own, 2 );
		}

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			// Transport failure. NOT invalid.
			return new ViesResult( 'unreachable', '', '', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new ViesResult( 'unreachable', '', '', 'HTTP ' . $code );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! array_key_exists( 'valid', $data ) ) {
			// An unrecognised body shape means VIES changed or errored.
			// Treating it as invalid would block every EU B2B checkout.
			return new ViesResult( 'unreachable', '', '', 'unrecognised response' );
		}

		$result = new ViesResult(
			$data['valid'] ? 'valid' : 'invalid',
			(string) ( $data['requestIdentifier'] ?? '' ),
			(string) ( $data['name'] ?? '' )
		);

		// Only definitive answers are cached. Caching an outage would
		// extend it well past the actual downtime.
		set_transient( $cache_key, array(
			'status'             => $result->status,
			'request_identifier' => $result->request_identifier,
			'registered_name'    => $result->registered_name,
			'checked_at'         => $result->checked_at,
		), self::CACHE_TTL );

		return $result;
	}
}
