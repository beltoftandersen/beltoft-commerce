<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\Roles;

class StoreApiGuardTest extends WP_UnitTestCase {

	private int $retail_only;
	private int $b2b_only;
	private int $both;
	private int $b2b_user;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		do_action( 'rest_api_init' );

		$this->retail_only = $this->make( null );
		$this->b2b_only    = $this->make( Taxonomy::B2B );
		$this->both        = $this->make( Taxonomy::BOTH );
		$this->b2b_user    = $this->factory->user->create( array( 'role' => 'b2b_customer' ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function make( ?string $audience ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		update_post_meta( $product->get_id(), '_price_group_b2b_standard', '60' );

		return $product->get_id();
	}

	private function listing_ids(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );
		$data     = json_decode( wp_json_encode( $response->get_data() ), true );

		return array_map( 'intval', wp_list_pluck( (array) $data, 'id' ) );
	}

	private function fetch_status( int $id ): int {
		return rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $id ) )->get_status();
	}

	public function test_listing_excludes_out_of_audience_items_for_b2b() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );
		$ids = $this->listing_ids();

		$this->assertNotContains( $this->retail_only, $ids );
		$this->assertContains( $this->both, $ids );
	}

	public function test_listing_excludes_b2b_only_items_for_a_guest() {
		wp_set_current_user( 0 );
		$ids = $this->listing_ids();

		$this->assertNotContains( $this->b2b_only, $ids );
		$this->assertContains( $this->retail_only, $ids );
	}

	/**
	 * Listing filters are the easy half. Reachability is where the leak
	 * lives — a direct fetch by ID must be denied too. Architecture §2.8.
	 */
	public function test_a_direct_fetch_of_a_restricted_product_is_denied_for_b2b() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( 404, $this->fetch_status( $this->retail_only ) );
	}

	public function test_a_direct_fetch_of_a_b2b_only_product_is_denied_for_a_guest() {
		wp_set_current_user( 0 );

		$this->assertSame( 404, $this->fetch_status( $this->b2b_only ) );
	}

	private function fetch_slug_status( int $id, string $suffix ): int {
		$slug = get_post_field( 'post_name', $id );

		return rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $slug . $suffix ) )->get_status();
	}

	/**
	 * WooCommerce's slug route accepts any non-space characters and runs them
	 * through sanitize_title(), so "slug/" and "slug." still resolve to the
	 * product. The guard's own identifier pattern did not match them.
	 */
	public function test_a_direct_fetch_by_slug_is_denied_whatever_is_appended() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( $this->b2b_user );

		foreach ( array( '', '/', '.' ) as $suffix ) {
			$this->assertSame( 404, $this->fetch_slug_status( $this->retail_only, $suffix ), var_export( $suffix, true ) );
		}
	}

	public function test_a_guest_cannot_fetch_a_b2b_only_product_by_a_decorated_slug() {
		wp_set_current_user( 0 );

		$this->assertSame( 404, $this->fetch_slug_status( $this->b2b_only, '.' ) );
	}

	public function test_a_permitted_fetch_by_slug_still_works() {
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( 200, $this->fetch_slug_status( $this->both, '' ) );
	}

	public function test_a_permitted_direct_fetch_still_works() {
		wp_set_current_user( $this->b2b_user );

		$this->assertSame( 200, $this->fetch_status( $this->both ) );
	}
}
