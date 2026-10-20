<?php

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;

/**
 * A second trade group added through pricing_groups - "config, not code" -
 * gets the same rules as the first: its own stored prices, fail-closed, the
 * trade catalogue, net prices in queries. The rest of the suite exercises
 * b2b_standard; this runs the contract for a group nobody hard-coded.
 */
class SecondGroupTest extends WP_UnitTestCase {

	private const GROUP = 'b2b_test_tier';
	private const ROLE  = 'b2b_test_role';

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		add_role( self::ROLE, 'Test tier', array( 'read' => true ) );
		add_filter( 'pricing_groups', static fn( $groups ) => array( self::GROUP => array( 'label' => 'Tier', 'roles' => array( self::ROLE ), 'prices' => 'own', 'trade' => true ) ) + (array) $groups );
		PricingContext::flush();
		CatalogAudience::flush();
	}

	public function tearDown(): void {
		remove_role( self::ROLE );
		wp_set_current_user( 0 );
		PricingContext::flush();
		CatalogAudience::flush();
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function product( ?string $audience, ?string $price ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();

		if ( $audience ) {
			Taxonomy::set_audience( $product->get_id(), $audience );
		}

		if ( null !== $price ) {
			update_post_meta( $product->get_id(), Registry::regular_key( self::GROUP ), $price );
		}

		return wc_get_product( $product->get_id() );
	}

	public function test_the_role_maps_to_the_group() {
		$user = self::factory()->user->create( array( 'role' => self::ROLE ) );

		$this->assertSame( self::GROUP, PricingContext::for_user( $user ) );
		$this->assertTrue( Registry::is_trade( self::GROUP ) );
	}

	public function test_its_own_price_is_charged_and_missing_prices_fail_closed() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		wp_set_current_user( self::factory()->user->create( array( 'role' => self::ROLE ) ) );

		$priced   = $this->product( Taxonomy::BOTH, '42' );
		$unpriced = $this->product( Taxonomy::BOTH, null );

		$this->assertSame( '42', $priced->get_price() );
		$this->assertNull( PriceResolver::resolve( $unpriced, self::GROUP ) );
		$this->assertFalse( wc_get_product( $unpriced->get_id() )->is_purchasable() );
	}

	public function test_it_sees_the_trade_catalogue() {
		$_SERVER['HTTP_HOST'] = \BeltoftCommerce\Support\Hosts::b2b(); // A trade session is priced on the trade host (§2.3, 2026-10-20).
		$user = self::factory()->user->create( array( 'role' => self::ROLE ) );

		$this->assertTrue( CatalogAudience::can_view( $user, $this->product( Taxonomy::B2B, '5' )->get_id() ) );
		$this->assertFalse( CatalogAudience::can_view( $user, $this->product( null, '5' )->get_id() ) );
	}
}
