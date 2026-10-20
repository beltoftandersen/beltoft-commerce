<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Domain\UrlFilter;
use BeltoftCommerce\Support\Hosts;

class HostsTest extends WP_UnitTestCase {

	use TradeHosts;

	public function test_it_identifies_the_b2b_host() {
		$this->assertTrue( Hosts::is_b2b_host( Hosts::b2b() ) );
	}

	public function test_it_does_not_treat_the_retail_host_as_b2b() {
		$this->assertFalse( Hosts::is_b2b_host( Hosts::retail() ) );
	}

	/**
	 * HTTP_HOST carries the port on non-standard ports, and a raw ===
	 * comparison silently returns retail behaviour on the B2B host.
	 */
	public function test_it_ignores_port_and_case() {
		$this->assertTrue( Hosts::is_b2b_host( strtoupper( Hosts::b2b() ) . ':443' ) );
	}

	public function test_an_unknown_host_is_not_b2b() {
		$this->assertFalse( Hosts::is_b2b_host( 'example.invalid' ) );
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		remove_all_filters( 'beltoft_commerce_b2b_host' );
		parent::tearDown();
	}

	public function test_a_trailing_dot_is_the_same_host() {
		$this->assertTrue( Hosts::is_b2b_host( Hosts::b2b() . '.' ) );
	}

	/** No trade host configured: nothing is the trade host, nothing redirects there. */
	public function test_an_unconfigured_trade_host_matches_nothing() {
		add_filter( 'beltoft_commerce_b2b_host', '__return_empty_string' );

		$this->assertFalse( Hosts::is_b2b_configured() );
		$this->assertFalse( Hosts::is_b2b_host( '' ) );
		$this->assertFalse( Hosts::is_b2b_host( 'b2b.staging.mindthetrash.pt' ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertFalse( HostRoutes::should_redirect( '/encomenda-rapida/' ) );
	}

	/** Another host the install answers on is not retail either. */
	public function test_only_the_retail_host_is_retail() {
		$this->assertTrue( Hosts::is_retail_host( strtoupper( Hosts::retail() ) ) );
		$this->assertFalse( Hosts::is_retail_host( Hosts::b2b() ) );
		$this->assertFalse( Hosts::is_retail_host( 'other.example.invalid' ) );
		$this->assertFalse( Hosts::is_retail_host( '' ) );
	}

	/** Another plugin's option_home filter does not change the canonical address. */
	public function test_canonical_ignores_every_home_filter() {
		$expected = trailingslashit( Hosts::stored_home() );
		$other    = static fn() => 'https://other.example.invalid';

		add_filter( 'option_home', $other, 99 );

		$this->assertSame( $expected, UrlFilter::canonical() );
		$this->assertStringNotContainsString( 'other.example.invalid', UrlFilter::canonical() );
	}

	/* One trade host per language (spec 2026-10-20, §1.1). */

	public function test_every_configured_trade_host_is_a_trade_host() {
		$this->use_trade_hosts();

		$this->assertTrue( Hosts::is_b2b_host( Hosts::b2b() ) );
		$this->assertTrue( Hosts::is_b2b_host( 'B2B.Example.com:443' ) );
		$this->assertFalse( Hosts::is_b2b_host( self::$en_retail ) );
		$this->assertSame( array( Hosts::b2b(), self::$en_trade ), Hosts::all_b2b_hosts() );

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertSame( self::$en_trade, Hosts::current_b2b() );
		$this->assertTrue( Hosts::is_b2b_host() );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertSame( '', Hosts::current_b2b() );
	}

	public function test_each_trade_host_names_its_language() {
		$this->use_trade_hosts();

		$this->assertSame( 'pt', Hosts::b2b_language( Hosts::b2b() ) );
		$this->assertSame( 'en', Hosts::b2b_language( self::$en_trade ) );
		$this->assertSame( '', Hosts::b2b_language( Hosts::retail() ) );
		$this->assertSame( 'pt', Hosts::default_language() );
		$this->assertSame( self::$en_trade, Hosts::b2b_for( 'en' ) );
		$this->assertSame( Hosts::b2b(), Hosts::b2b_for( 'pt' ) );
	}

	public function test_a_language_without_a_trade_host_uses_the_fallback() {
		$this->use_trade_hosts();

		$this->assertSame( self::$en_trade, Hosts::b2b_for( 'de' ) );
		$this->assertSame( self::$en_trade, Hosts::b2b_for( 'es' ) );

		// A fallback with no host of its own: the default trade host.
		$this->seam( 'beltoft_commerce_config', static fn( $config ) => array_merge( $config, array( 'trade_fallback_language' => 'fr' ) ) );
		$this->assertSame( Hosts::b2b(), Hosts::b2b_for( 'de' ) );
	}

	public function test_without_a_map_everything_is_as_before() {
		$this->assertSame( array(), Hosts::b2b_hosts() );
		$this->assertSame( array( Hosts::b2b() ), Hosts::all_b2b_hosts() );
		$this->assertSame( Hosts::b2b(), Hosts::b2b_for( 'en' ) );
		$this->assertSame( '', Hosts::b2b_language() );
		$this->assertSame( '', Hosts::b2b_language( Hosts::b2b() ) );
		$this->assertSame( array(), Hosts::problems() );
		$this->assertSame( array( 'kept' => array( 'x' ) ), Hosts::zerolang_sets( array( 'kept' => array( 'x' ) ), 'pt', Hosts::retail() ) );
	}

	public function test_a_map_disagreeing_with_BELTOFT_B2B_HOST_is_a_problem() {
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => 'b2b.other.example', 'en' => 'b2b.example.com' ) );
		$this->seam( 'beltoft_commerce_language_codes', static fn() => array( 'pt', 'en' ) );
		$this->seam( 'beltoft_commerce_language_default', static fn() => 'pt' );

		$this->assertNotEmpty( preg_grep( '/BELTOFT_B2B_HOST/', Hosts::problems() ) );
	}

	public function test_a_trade_host_equal_to_retail_is_refused() {
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => Hosts::b2b(), 'en' => Hosts::retail() ) );

		$this->assertNotContains( Hosts::retail(), Hosts::all_b2b_hosts() );
		$this->assertFalse( Hosts::is_b2b_host( Hosts::retail() ) );
		$this->assertNotEmpty( Hosts::problems() );
	}

	public function test_a_trade_host_on_a_language_domain_or_unclean_is_a_problem() {
		$this->use_trade_hosts();
		$this->assertSame( array(), Hosts::problems() );

		remove_all_filters( 'beltoft_commerce_b2b_hosts' );
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => Hosts::b2b(), 'en' => self::$en_retail, 'xx' => '10.0.0.1' ) );

		$problems = implode( "\n", Hosts::problems() );
		$this->assertStringContainsString( self::$en_retail, $problems );
		$this->assertStringContainsString( '10.0.0.1', $problems );
		$this->assertStringContainsString( 'xx', $problems );
	}

	public function test_a_fallback_language_without_a_host_is_a_problem() {
		$this->use_trade_hosts( array( 'trade_fallback_language' => 'de' ) );

		$this->assertNotEmpty( preg_grep( '/trade_fallback_language/', Hosts::problems() ) );
	}

	public function test_several_trade_hosts_in_a_path_mode_is_a_warning() {
		$this->use_trade_hosts();
		$this->seam( 'beltoft_commerce_language_separate_domains', '__return_false' );

		$this->assertNotEmpty( preg_grep( '/every language/', Hosts::problems() ) );
	}

	/** The callback Zerolang reads its trade host set from (zerolang_host_sets). */
	public function test_the_language_plugin_is_given_the_trade_set() {
		$this->use_trade_hosts();

		$sets = Hosts::zerolang_sets( array(), 'pt', Hosts::retail() );

		$this->assertSame( array( 'pt' => Hosts::b2b(), 'en' => self::$en_trade ), $sets['trade'] );
	}

	/** Retail side: the site address or a language domain, never a trade host or a stranger. */
	public function test_the_retail_side_is_the_site_address_and_the_language_domains() {
		$this->use_trade_hosts();

		$this->assertTrue( Hosts::is_retail_side( Hosts::retail() ) );
		$this->assertTrue( Hosts::is_retail_side( self::$en_retail ) );
		$this->assertTrue( Hosts::is_retail_side( self::$de_retail ) );
		$this->assertFalse( Hosts::is_retail_side( self::$en_trade ) );
		$this->assertFalse( Hosts::is_retail_side( Hosts::b2b() ) );
		$this->assertFalse( Hosts::is_retail_side( 'other.example.invalid' ) );
	}
}
