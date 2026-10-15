<?php

/**
 * The cached ordering-list rows carry prices, so a price change has to drop
 * them - otherwise the list shows the old price while the cart charges the
 * new one until the cache's TTL runs out.
 */
class QueryResultCacheTest extends WP_UnitTestCase {

	/** The tests below put the request on the trade host; it must not outlive them. */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function version(): string {
		BeltoftCommerce\Frontend\QueryResultCache::commit();

		return (string) get_option( 'beltoft_commerce_rows_version', '' );
	}

	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Group prices arrive by import as plain meta writes, never through the
	 * product save that already invalidates the rows.
	 */
	public function test_a_group_price_written_as_meta_drops_the_cached_rows() {
		$id     = $this->product();
		$before = $this->version();

		update_post_meta( $id, '_price_group_b2b_standard', '6' );

		$this->assertNotSame( $before, $this->version() );
	}

	/** A sale window opening or closing is a price change with no write behind it. */
	public function test_a_group_sale_boundary_drops_the_cached_rows() {
		$id     = $this->product();
		$before = $this->version();

		do_action( 'beltoft_commerce_group_price_changed', $id, 0 );

		$this->assertNotSame( $before, $this->version() );
	}

	/** The body Bricks posts for the trade list, as bricks.min.js builds it. */
	private function body( array $changes = array() ): array {
		return array_merge( array(
			'postId'               => 191348,
			'queryElementId'       => 'abc123',
			'previewTemplateId'    => 0,
			'originalQueryVars'    => '[]',
			'pageFilters'          => false,
			'filters'              => array(),
			'infinitePage'         => 1,
			'selectedFilters'      => array(),
			'afTags'               => array(),
			'nonce'                => 'aaaa',
			'baseUrl'              => 'https://b2b.example/loja/',
			'lang'                 => false,
			'mainQueryId'          => false,
			'activeSearchTemplate' => false,
			'urlParams'            => array(),
		), $changes );
	}

	private function key( array $body ): string {
		$request = new WP_REST_Request( 'POST', '/bricks/v1/query_result' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		$key = new ReflectionMethod( BeltoftCommerce\Frontend\QueryResultCache::class, 'key' );
		$key->setAccessible( true );

		return $key->invoke( null, $request );
	}

	/** The nonce is per session; keying on it would make the cache per person. */
	public function test_requests_differing_only_in_the_nonce_share_an_entry() {
		$_SERVER['HTTP_HOST'] = BeltoftCommerce\Support\Hosts::b2b();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$this->assertNotSame( '', $this->key( $this->body() ) );
		$this->assertSame( $this->key( $this->body() ), $this->key( $this->body( array( 'nonce' => 'bbbb' ) ) ) );
	}

	/**
	 * Every other field can change the rows Bricks renders: URL parameters
	 * drive element conditions and {url_parameter} tags, a search template
	 * replaces the query, a component id picks the element. Leaving any out of
	 * the key served one buyer the rows rendered for another - or let a crafted
	 * request poison the entry everyone in the group reads.
	 */
	public function test_every_other_field_separates_entries() {
		$_SERVER['HTTP_HOST'] = BeltoftCommerce\Support\Hosts::b2b();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$base = $this->key( $this->body() );

		foreach ( array(
			'urlParams'            => array( 'marca' => 'x' ),
			'afTags'               => array( 'tag' ),
			'mainQueryId'          => 'zzz999',
			'activeSearchTemplate' => 42,
			'componentId'          => 'cmp001',
			'previewTemplateId'    => 7,
		) as $field => $value ) {
			$this->assertNotSame( $base, $this->key( $this->body( array( $field => $value ) ) ), $field );
		}
	}

	/** A term change on a product drops the rows; on anything else it does not. */
	public function test_only_a_products_terms_drop_the_rows() {
		$before = $this->version();
		wp_set_object_terms( self::factory()->post->create(), 'qualquer', 'post_tag' );
		$this->assertSame( $before, $this->version(), 'a post' );

		wp_set_object_terms( $this->product(), 'marca-x', 'product_tag' );
		$this->assertNotSame( $before, $this->version(), 'a product' );
	}

	/** Many changes in one request are one write. */
	public function test_changes_in_one_request_are_one_write() {
		$writes = 0;
		$count  = static function ( $option ) use ( &$writes ) {
			if ( 'beltoft_commerce_rows_version' === $option ) {
				$writes++;
			}
		};
		$this->version();
		add_action( 'updated_option', $count );
		add_action( 'added_option', $count );

		BeltoftCommerce\Frontend\QueryResultCache::bump();
		BeltoftCommerce\Frontend\QueryResultCache::bump();
		BeltoftCommerce\Frontend\QueryResultCache::bump();
		BeltoftCommerce\Frontend\QueryResultCache::commit();

		$this->assertSame( 1, $writes );
	}
}
