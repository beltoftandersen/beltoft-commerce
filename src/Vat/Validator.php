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

	/** The shop's own country (Config 'home_country'): a number from here is a domestic sale. */
	public static function home_country(): string {
		return strtoupper( (string) \BeltoftCommerce\Support\Config::get( 'home_country' ) );
	}

	/**
	 * EU member state codes - and XI, Northern Ireland, whose businesses buy
	 * goods under EU VAT rules (Windsor Framework) and which VIES checks.
	 */
	private const EU = array(
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
		'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
		'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
	);

	/**
	 * Whether a number from the shop's own country passes that country's
	 * check. Only Portugal's algorithm (PtNif) is known; for another home
	 * country this accepts the number rather than refusing every domestic
	 * applicant with a Portuguese test.
	 */
	public static function domestic_number_ok( string $number ): bool {
		return 'PT' !== self::home_country() || PtNif::is_valid( $number );
	}

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
		if ( self::home_country() === $country ) {
			$decision->status = 'domestic';

			return $decision;
		}

		if ( ! in_array( $country, self::EU, true ) ) {
			// Outside the EU: no reverse charge here. Whether a sale outside
			// the EU is an export is not decided by this plugin.
			return $decision;
		}

		/*
		 * A Spanish number with a Portuguese (or missing) billing address is
		 * not a Spanish business buying from Portugal, whatever VIES says
		 * about the number. Billing must be the number's own member state.
		 */
		if ( self::billing_country_of( $billing_country, $country ) !== $country ) {
			$decision->status          = 'country_mismatch';
			$decision->blocks_checkout = true;

			return $decision;
		}

		$result = ViesClient::check( self::vies_code( $country ), $number, $allow_remote );

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

	/**
	 * Greek VAT numbers carry EL, while billing addresses carry the ISO code
	 * GR. Both mean Greece here; VIES is asked about EL (see vies_code()).
	 */
	private const GREECE_VAT_PREFIX = 'EL';

	private static function country_of( string $raw, string $billing_country ): string {
		$prefix = substr( $raw, 0, 2 );

		if ( self::GREECE_VAT_PREFIX === $prefix ) {
			return 'GR';
		}

		if ( in_array( $prefix, self::EU, true ) ) {
			return $prefix;
		}

		return strtoupper( substr( (string) $billing_country, 0, 2 ) );
	}

	/**
	 * The billing country as compared with the number's: WooCommerce has no
	 * Northern Ireland, so an XI number is billed in GB.
	 */
	private static function billing_country_of( string $billing_country, string $number_country ): string {
		$billing = strtoupper( trim( $billing_country ) );

		return ( 'XI' === $number_country && 'GB' === $billing ) ? 'XI' : $billing;
	}

	private static function number_of( string $raw, string $country ): string {
		$prefixes = 'GR' === $country ? array( 'GR', self::GREECE_VAT_PREFIX ) : array( $country );

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $raw, $prefix ) ) {
				return substr( $raw, 2 );
			}
		}

		return $raw;
	}

	/** The member state code VIES knows: EL for Greece, the ISO code otherwise. */
	private static function vies_code( string $country ): string {
		return 'GR' === $country ? self::GREECE_VAT_PREFIX : $country;
	}
}
