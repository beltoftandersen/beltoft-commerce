<?php

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;

/**
 * The till lives in beltoft-pos. Commerce keeps no till group, role, stock
 * pool or checkout rules of its own - two plugins doing it counted every
 * sale twice.
 */
class TillLeftCommerceTest extends WP_UnitTestCase {

	public function test_commerce_registers_no_till_pricing_group() {
		$this->assertArrayNotHasKey( 'pos', Registry::groups() );
		$this->assertFalse( defined( Registry::class . '::POS' ) );
	}

	/** beltoft-pos owns the pos_operator role; commerce must not (re)create it. */
	public function test_commerce_does_not_create_the_till_role() {
		remove_role( 'pos_operator' );

		Roles::install();

		$this->assertNull( get_role( 'pos_operator' ) );
		$this->assertNotNull( get_role( 'b2b_customer' ) );
	}

	/** To commerce a till account is a shopper at retail prices. */
	public function test_a_till_account_resolves_to_retail_in_commerce() {
		add_role( 'pos_operator', 'POS', array( 'read' => true ) );
		$till = self::factory()->user->create( array( 'role' => 'pos_operator' ) );

		$this->assertSame( Registry::retail_slug(), PricingContext::for_user( $till ) );
	}

	public function test_the_till_classes_are_gone() {
		foreach ( array( 'Pos\\Checkout', 'Pos\\Tenders', 'Pos\\Tender', 'Pos\\PaymentMethods', 'Pos\\ListColumns', 'Stock\\GroupStock', 'Stock\\AdminField', 'Stock\\Recount' ) as $class ) {
			$this->assertFalse( class_exists( 'BeltoftCommerce\\' . $class ), $class );
		}
	}
}
