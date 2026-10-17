<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Domain\LoginGate;
use BeltoftCommerce\Domain\SeoGuards;
use BeltoftCommerce\Support\Config;
use BeltoftCommerce\Support\Hosts;
use BeltoftCommerce\Vat\Validator;

/**
 * The site-specific settings: mindthetrash's values by default, and another
 * shop's when it sets them.
 */
class ConfigTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function set( array $values ): void {
		add_filter( 'beltoft_commerce_config', static fn( $config ) => array_merge( $config, $values ) );
	}

	public function test_the_defaults_are_mindthetrashs() {
		$this->assertSame( '/entrar/', LoginGate::login_path() );
		$this->assertSame( 'PT', Validator::home_country() );
		$this->assertContains( '/encomenda-rapida/', HostRoutes::b2b_only_paths() );
		$this->assertStringContainsString( 'User-agent: GPTBot', SeoGuards::retail_robots_rules() );
	}

	public function test_paths_are_normalised() {
		$this->set( array( 'login_path' => 'login', 'account_legacy_paths' => array( 'old-account' ) ) );

		$this->assertSame( '/login/', Config::path( 'login_path' ) );
		$this->assertSame( array( '/old-account/' ), Config::paths( 'account_legacy_paths' ) );
	}

	/** Another shop's login page is what the gate lets through, and nothing else. */
	public function test_the_gate_follows_the_login_path() {
		$this->set( array( 'login_path' => '/login/' ) );
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( LoginGate::should_gate( '/login/' ) );
		$this->assertTrue( LoginGate::should_gate( '/entrar/' ) );
		$this->assertContains( '/login/', HostRoutes::b2b_only_paths() );
	}

	public function test_the_home_country_decides_what_is_domestic() {
		$this->set( array( 'home_country' => 'es' ) );

		$this->assertSame( 'ES', Validator::home_country() );
		$this->assertSame( 'domestic', Validator::decide( 'ESB12345678', 'ES', false )->status );
	}

	/** null leaves robots.txt to WordPress and the SEO plugin. */
	public function test_robots_can_be_left_alone() {
		$this->set( array( 'retail_robots' => null ) );
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertSame( "untouched\n", SeoGuards::replace_default_robots( "untouched\n", true ) );
	}
}
