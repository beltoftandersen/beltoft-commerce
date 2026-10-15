<?php

use BeltoftCommerce\Checkout\PurchaseOrder;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

class PurchaseOrderTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_POST[ PurchaseOrder::FIELD ] );
		parent::tearDown();
	}

	private function place( string $host, string $role ): WC_Order {
		$_SERVER['HTTP_HOST'] = $host;
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$_POST[ PurchaseOrder::FIELD ] = 'PO-4711';

		$order = new WC_Order();
		PurchaseOrder::attach( $order, array() );

		return $order;
	}

	public function test_a_trade_checkout_keeps_the_reference() {
		$this->assertSame( 'PO-4711', PurchaseOrder::of( $this->place( Hosts::b2b(), 'b2b_customer' ) ) );
	}

	/** The field is not offered on retail; a posted value must not reach the order. */
	public function test_a_reference_posted_to_a_retail_checkout_is_ignored() {
		$this->assertSame( '', PurchaseOrder::of( $this->place( Hosts::retail(), 'b2b_customer' ) ) );
		$this->assertSame( '', PurchaseOrder::of( $this->place( Hosts::b2b(), 'customer' ) ) );
	}
}
