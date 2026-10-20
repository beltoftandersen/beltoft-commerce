<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Domain\CacheRules;
use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Pricing\PricingContext;
use BeltoftCommerce\Pricing\Registry;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Support\Hosts;

require_once __DIR__ . '/RouteInventory.php';

/**
 * §5, per host (spec 2026-10-20, §6.3): with one trade host per language, the
 * exposure contract holds on every trade host, on the site address and on a
 * retail language domain - no group price, no group price key, for a guest,
 * a retail customer or an applicant, wherever they ask.
 *
 * The positive control (a partner sees the group price on every trade host)
 * keeps the contract from passing because the price is shown nowhere.
 */
class TradeHostsExposureTest extends WP_UnitTestCase {

	use TradeHosts;

	private int $product_id;

	/** @var array<string,string> group slug => its sentinel price */
	private array $group_prices = array();

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
		do_action( 'rest_api_init' );
		$this->use_trade_hosts();

		$product = new WC_Product_Simple();
		$product->set_name( 'Sentinel soap' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->set_stock_status( 'instock' );
		$product->save();
		$this->product_id = $product->get_id();
		Taxonomy::set_audience( $this->product_id, Taxonomy::BOTH );

		// A distinctive price per registered group, from the registry.
		$n = 0;
		foreach ( array_keys( Registry::groups() ) as $slug ) {
			$price = (string) ( 7770 + $n );
			update_post_meta( $this->product_id, Registry::regular_key( $slug ), $price );
			$this->group_prices[ $slug ] = $price;
			$n++;
		}

		wc_delete_product_transients( $this->product_id );
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Every trade host, the site address and a retail language domain, by name (providers run before setUp). */
	private static function host( string $name ): string {
		$hosts = array(
			'trade-pt'    => Hosts::b2b(),
			'trade-en'    => self::$en_trade,
			'site'        => Hosts::retail(),
			'retail-lang' => self::$en_retail,
		);

		return $hosts[ $name ];
	}

	/** @return string[] The trade hosts, as the configuration names them. */
	private function trade_hosts(): array {
		$hosts = Hosts::all_b2b_hosts();
		$this->assertCount( 2, $hosts, 'The fixture must have two trade hosts.' );

		return $hosts;
	}

	public static function hosts_and_contexts(): array {
		$cases = array();

		foreach ( array( 'trade-pt', 'trade-en', 'site', 'retail-lang' ) as $host ) {
			foreach ( array( 'guest', 'customer', 'b2b_pending' ) as $context ) {
				$cases[ $host . ' as ' . $context ] = array( $host, $context );
			}
		}

		return $cases;
	}

	private function apply_context( string $context ): void {
		wp_set_current_user( 'guest' === $context ? 0 : self::factory()->user->create( array( 'role' => $context ) ) );
	}

	private function resolve( string $route ): string {
		return str_replace(
			array( '(?P<id>[\d]+)', '(?P<slug>[\S]+)' ),
			array( (string) $this->product_id, wc_get_product( $this->product_id )->get_slug() ),
			$route
		);
	}

	private function body( string $route ): string {
		return (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data() );
	}

	/** @dataProvider hosts_and_contexts */
	public function test_no_group_price_leaks_from_any_rest_route_on_any_host( string $host, string $context ) {
		$_SERVER['HTTP_HOST'] = self::host( $host );
		$this->apply_context( $context );

		foreach ( RouteInventory::rest() as $route ) {
			$path = $this->resolve( $route );
			$body = $this->body( $path );

			foreach ( $this->group_prices as $slug => $price ) {
				$this->assertStringNotContainsString( $price, $body, "Group price for {$slug} leaked in {$path} on {$host} as {$context}" );
			}

			foreach ( Registry::all_price_keys() as $key ) {
				$this->assertStringNotContainsString( $key, $body, "Meta key {$key} leaked in {$path} on {$host} as {$context}" );
			}
		}
	}

	/** A logged-out visitor reaches nothing of the catalogue on any trade host, by any spelling. */
	public function test_every_trade_host_gates_every_catalogue_path() {
		$product = (string) wp_parse_url( get_permalink( $this->product_id ), PHP_URL_PATH );
		$uris    = array( '/', '/loja/', '/shop/', '/b2b-loja/', '/b2b-shop/', $product, '/?post_type=product', '/?s=soap', '/feed/', '/?p=' . $this->product_id, '/wp-json/wc/store/v1/products', '/?rest_route=/wc/store/v1/products', '/sign-in/?pagename=b2b-loja', '/entrar/?product=' . $this->product_id );

		foreach ( $this->trade_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			foreach ( $uris as $uri ) {
				$query = array();
				parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
				$path = LoginGate::effective_path( $uri );

				$this->assertTrue( LoginGate::should_gate_early( $path, false, $query ), "{$uri} on {$host}: early gate" );
				$this->assertTrue( LoginGate::should_gate( $path, $query ), "{$uri} on {$host}: late gate" );
			}
		}
	}

	public function test_no_trade_host_response_is_cacheable() {
		foreach ( $this->trade_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			$this->assertStringContainsString( 'no-store', (string) CacheRules::cache_control(), $host );
			$this->assertStringContainsString( 'private', (string) CacheRules::cache_control(), $host );
		}

		foreach ( array( Hosts::retail(), self::$en_retail ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertNull( CacheRules::cache_control(), $host );
		}
	}

	public function test_rest_is_401_for_a_guest_on_every_trade_host() {
		wp_set_current_user( 0 );

		foreach ( $this->trade_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			foreach ( RouteInventory::rest() as $route ) {
				$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', $this->resolve( $route ) ) )->get_status(), "{$route} on {$host}" );
			}
		}
	}

	/** The control: a partner is quoted their group's price on every trade host - and on no retail host. */
	public function test_a_b2b_customer_sees_the_group_price_on_every_trade_host() {
		$partner = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );
		wp_set_current_user( $partner );
		$sentinel = $this->group_prices[ PricingContext::for_user( $partner ) ] ?? '';
		$this->assertNotSame( '', $sentinel, 'The fixture partner must have a group with its own prices.' );

		foreach ( $this->trade_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			$this->assertSame( $sentinel, (string) wc_get_product( $this->product_id )->get_price(), $host );
			$this->assertStringContainsString( $sentinel, $this->body( '/wc/store/v1/products/' . $this->product_id ), $host );
		}

		foreach ( array( Hosts::retail(), self::$en_retail ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			$this->assertStringNotContainsString( $sentinel, $this->body( '/wc/store/v1/products/' . $this->product_id ), $host );
		}
	}
}
