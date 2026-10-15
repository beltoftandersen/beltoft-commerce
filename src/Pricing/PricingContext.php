<?php

namespace BeltoftCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * User → pricing group.
 *
 * Resolution runs User → PricingContext → group → PriceResolver. This class
 * answers only "which commercial terms apply", never "what may this user
 * access" — that is CatalogAudience. See architecture §2.2.
 */
class PricingContext {

	public const OVERRIDE_META = '_beltoft_pricing_group';

	public static function current(): string {
		return self::for_user( get_current_user_id() );
	}

	public static function for_user( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! $user_id ) {
			return Registry::retail_slug();
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return Registry::retail_slug();
		}

		// An explicit per-user group beats role mapping, for the one
		// customer who needs different terms without inventing a role.
		// Validated against the registry: this is user meta, and anything
		// able to write user meta could otherwise invent a group.
		$override = get_user_meta( $user_id, self::OVERRIDE_META, true );

		if ( is_string( $override ) && '' !== $override && Registry::has( $override ) ) {
			return $override;
		}

		return Roles::group_for_roles( (array) $user->roles );
	}

	public static function is_retail( ?int $user_id = null ): bool {
		return Registry::retail_slug() === self::for_user( $user_id );
	}
}
