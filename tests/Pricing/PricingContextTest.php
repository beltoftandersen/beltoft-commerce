<?php

use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;

class PricingContextTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		\BeltoftCommerce\Pricing\Roles::install();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_a_guest_is_retail() {
		wp_set_current_user( 0 );

		$this->assertSame( Registry::retail_slug(), PricingContext::current() );
	}

	public function test_a_b2b_customer_gets_the_b2b_group() {
		$user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame( 'b2b_standard', PricingContext::for_user( $user ) );
	}

	public function test_a_pending_user_is_retail() {
		$user = $this->factory->user->create( array( 'role' => 'b2b_pending' ) );

		$this->assertSame( Registry::retail_slug(), PricingContext::for_user( $user ) );
	}

	public function test_current_follows_the_logged_in_user() {
		$user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $user );

		$this->assertSame( 'b2b_standard', PricingContext::current() );
	}

	public function test_a_user_meta_override_takes_precedence_over_role() {
		$user = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user, '_beltoft_pricing_group', 'b2b_standard' );

		$this->assertSame( 'b2b_standard', PricingContext::for_user( $user ) );
	}

	/**
	 * The override is user meta, so anything able to write user meta could
	 * otherwise invent a pricing group.
	 */
	public function test_an_override_naming_an_unknown_group_is_ignored() {
		$user = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user, '_beltoft_pricing_group', 'does_not_exist' );

		$this->assertSame( Registry::retail_slug(), PricingContext::for_user( $user ) );
	}

	public function test_an_override_cannot_name_retail_to_downgrade_a_b2b_user() {
		// Retail is not a registered group, so it is not a valid override.
		$user = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
		update_user_meta( $user, '_beltoft_pricing_group', Registry::retail_slug() );

		$this->assertSame( 'b2b_standard', PricingContext::for_user( $user ) );
	}

	public function test_a_deleted_user_id_is_retail() {
		$this->assertSame( Registry::retail_slug(), PricingContext::for_user( 999999 ) );
	}
}
