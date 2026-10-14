<?php

use BeltoftCommerce\Checkout\B2BCheckoutPage;

class B2BCheckoutPageLegacyTest extends WP_UnitTestCase {

	/** Order e-mails already sent carry these links; they must land, key and all. */
	public function test_the_old_checkout_path_forwards_with_endpoint_and_key() {
		$this->assertSame( home_url( '/finalizar/' ), B2BCheckoutPage::legacy_target( '/finalizar-b2b/' ) );
		$this->assertSame(
			home_url( '/finalizar/encomenda-recebida/191411/?key=wc_order_x' ),
			B2BCheckoutPage::legacy_target( '/finalizar-b2b/encomenda-recebida/191411/?key=wc_order_x' )
		);
	}

	public function test_other_paths_do_not_forward() {
		foreach ( array( '/finalizar/', '/loja/', '/finalizar-b2b-x/' ) as $uri ) {
			$this->assertNull( B2BCheckoutPage::legacy_target( $uri ), $uri );
		}
	}
}
