<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Enforcement\BricksGuard;
use BeltoftCommerce\Pricing\Roles;

/** The quick-view popup endpoint renders only what the visitor may see. */
class BricksGuardTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function product( ?string $audience, string $status = 'publish' ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( $status );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		return $product->get_id();
	}

	private function popup( array $body ) {
		$request = new WP_REST_Request( 'POST', '/bricks/v1/load_popup_content' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( array( 'popupId' => 1, 'nonce' => 'x' ), $body ) ) );

		return BricksGuard::guard( null, null, $request );
	}

	public function test_a_guest_cannot_render_a_b2b_only_product() {
		$id = $this->product( Taxonomy::B2B );

		$this->assertWPError( $this->popup( array( 'popupContextType' => 'post', 'popupContextId' => $id ) ) );
		$this->assertWPError( $this->popup( array( 'postId' => $id ) ) );
		$this->assertWPError( $this->popup( array( 'popupLoopId' => 'abc:def:0:' . $id ) ) );
	}

	public function test_a_private_product_is_not_rendered_for_a_guest() {
		$this->assertWPError( $this->popup( array( 'popupContextId' => $this->product( Taxonomy::BOTH, 'private' ) ) ) );
	}

	public function test_a_trade_session_cannot_render_a_retail_only_product() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertWPError( $this->popup( array( 'popupContextId' => $this->product( null ) ) ) );
		$this->assertNull( $this->popup( array( 'popupContextId' => $this->product( Taxonomy::BOTH ) ) ) );
	}

	public function test_a_visible_product_and_other_routes_are_untouched() {
		$this->assertNull( $this->popup( array( 'popupContextId' => $this->product( null ) ) ) );

		$other = new WP_REST_Request( 'POST', '/bricks/v1/query_result' );
		$this->assertNull( BricksGuard::guard( null, null, $other ) );
	}
}
