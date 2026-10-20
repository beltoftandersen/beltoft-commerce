<?php

use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Support\TradePath;

/**
 * The language a trade path is in, decided before any language plugin runs
 * (spec 2026-10-20, §2): from the host under separate domains, from a
 * configured prefix in a path mode.
 */
class TradePathTest extends WP_UnitTestCase {

	use TradeHosts;

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		parent::tearDown();
	}

	public function test_domain_mode_takes_the_language_from_the_host() {
		$this->use_trade_hosts();

		$this->assertSame( array( 'en', '/sign-in/' ), TradePath::split( '/sign-in/', self::$en_trade ) );
		$this->assertSame( array( 'pt', '/entrar/' ), TradePath::split( '/entrar/', Hosts::b2b() ) );
		// A host with no language of its own: the default trade language.
		$this->assertSame( array( 'pt', '/entrar/' ), TradePath::split( '/entrar/', Hosts::retail() ) );
		$this->assertSame( '', TradePath::prefix( 'en' ) );

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$this->assertSame( array( 'en', '/apply/' ), TradePath::split( '/apply/' ) );
	}

	public function test_path_mode_strips_a_configured_prefix() {
		$this->use_path_mode();

		$this->assertSame( array( 'es', '/acceder/' ), TradePath::split( '/es/acceder/', Hosts::b2b() ) );
		$this->assertSame( array( 'en', '/' ), TradePath::split( '/en/', Hosts::b2b() ) );
		$this->assertSame( array( 'en', '/' ), TradePath::split( '/en', Hosts::b2b() ) );
		$this->assertSame( '/es', TradePath::prefix( 'es' ) );
		$this->assertSame( '', TradePath::prefix( 'pt' ) );
	}

	public function test_an_unknown_segment_is_left_alone() {
		$this->use_path_mode();

		$this->assertSame( array( '', '/loja/' ), TradePath::split( '/loja/', Hosts::b2b() ) );
		$this->assertSame( array( '', '/esx/acceder/' ), TradePath::split( '/esx/acceder/', Hosts::b2b() ) );
		$this->assertSame( array( '', '/' ), TradePath::split( '/', Hosts::b2b() ) );
	}

	public function test_case_and_encoding_do_not_hide_a_prefix() {
		$this->use_path_mode();

		$this->assertSame( array( 'es', '/acceder/' ), TradePath::split( '/ES/acceder/', Hosts::b2b() ) );
		$this->assertSame( array( 'es', '/acceder/' ), TradePath::split( '/%65s/acceder/', Hosts::b2b() ) );
	}

	/** Without a language prefix configured, a path-mode language plugin supplies them once it has run. */
	public function test_the_language_plugin_supplies_prefixes_when_none_are_configured() {
		$this->seam( 'beltoft_commerce_language_path_prefixes', static fn() => array( 'es' => 'es' ) );

		$this->assertSame( array( 'es', '/acceder/' ), TradePath::split( '/es/acceder/', Hosts::b2b() ) );
	}

	/** Without a map or prefixes: no language, the path untouched - today's behaviour. */
	public function test_single_host_without_prefixes_is_as_before() {
		$this->assertSame( array( '', '/es/acceder/' ), TradePath::split( '/es/acceder/', Hosts::b2b() ) );
		$this->assertSame( '', TradePath::prefix( 'es' ) );
	}
}
