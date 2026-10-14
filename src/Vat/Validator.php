<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * The §2.10 decision table.
 *
 * | Case                     | Behaviour                                   |
 * |--------------------------|---------------------------------------------|
 * | Field empty              | Consumer sale, IVA charged                  |
 * | Country prefix PT        | Domestic — no VIES call, no exemption, IVA  |
 * | Non-PT EU, billing in    | Checkout blocked, no VIES call: reverse     |
 * | another country          | charge belongs to the number's own country  |
 * | Non-PT EU, VIES valid    | Reverse charge, evidence stored             |
 * | Non-PT EU, VIES invalid  | Checkout blocked, translatable error        |
 * | VIES unreachable/timeout | Order proceeds, IVA charged, flagged        |
 * | Non-EU                   | Out of scope here; handled as export        |
 */
class Validator {

	public const HOME_COUNTRY = 'PT';

	/** EU member state codes. */
	private const EU = array(
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
		'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
		'PT', 'RO', 'SE', 'SI', 'SK',
	);

	/**
	 * @param bool $allow_remote Passed through to ViesClient. False means
	 *                           "answer from what we already know" — used
	 *                           where a blocking call would sit in the way
	 *                           of rendering, such as cart total
	 *                           calculation. See Exemption.
	 */
	public static function decide( string $vat_number, string $billing_country, bool $allow_remote = true ): VatDecision {
		$raw    = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $vat_number ) );
		$decision = new VatDecision( '', $raw );

		if ( '' === $raw ) {
			// A normal consumer sale.
			return $decision;
		}

		$country = self::country_of( $raw, $billing_country );
		$number  = self::number_of( $raw, $country );

		// Portuguese numbers are NEVER sent to VIES. A domestic sale is a
		// domestic sale, and a valid PT number is not a zero-rating trigger.
		if ( self::HOME_COUNTRY === $country ) {
			$decision->status = 'domestic';

			return $decision;
		}

		if ( ! in_array( $country, self::EU, true ) ) {
			// Out of scope here; handled as an export.
			return $decision;
		}

		/*
		 * A Spanish number with a Portuguese (or missing) billing address is
		 * not a Spanish business buying from Portugal, whatever VIES says
		 * about the number. Billing must be the number's own member state.
		 */
		if ( strtoupper( trim( $billing_country ) ) !== $country ) {
			$decision->status          = 'country_mismatch';
			$decision->blocks_checkout = true;

			return $decision;
		}

		$result = ViesClient::check( $country, $number, $allow_remote );

		$decision->request_identifier = $result->request_identifier;
		$decision->registered_name    = $result->registered_name;
		$decision->checked_at         = $result->checked_at;

		if ( $result->is_valid() ) {
			$decision->status = 'valid';
			$decision->exempt = true;

			return $decision;
		}

		if ( $result->is_unreachable() ) {
			// A timeout is not an invalid number. Blocking checkout because
			// a member state endpoint is down is a real revenue cost.
			$decision->status = 'unverified';
			$decision->error  = $result->error;

			return $decision;
		}

		$decision->status          = 'invalid';
		$decision->blocks_checkout = true;

		return $decision;
	}

	private static function country_of( string $raw, string $billing_country ): string {
		$prefix = substr( $raw, 0, 2 );

		if ( in_array( $prefix, self::EU, true ) ) {
			return $prefix;
		}

		return strtoupper( substr( (string) $billing_country, 0, 2 ) );
	}

	private static function number_of( string $raw, string $country ): string {
		return 0 === strpos( $raw, $country ) ? substr( $raw, 2 ) : $raw;
	}
}
