<?php

use BeltoftCommerce\Domain\SeoGuards;
use BeltoftCommerce\Support\Hosts;

class SeoGuardsTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_robots_txt_disallows_everything_on_b2b() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertStringContainsString(
			'Disallow: /',
			SeoGuards::filter_robots_txt( "User-agent: *\n", true )
		);
	}

	public function test_robots_txt_is_untouched_on_retail() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$original             = "User-agent: *\nDisallow: /wp-admin/\n";

		$this->assertSame( $original, SeoGuards::filter_robots_txt( $original, true ) );
	}

	public function test_canonical_points_at_retail_from_the_b2b_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$canonical = SeoGuards::filter_canonical( 'https://' . Hosts::b2b() . '/produto/abc/' );

		$this->assertStringContainsString( Hosts::retail(), $canonical );
		$this->assertStringNotContainsString( Hosts::b2b(), $canonical );
	}

	public function test_canonical_is_untouched_on_retail() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$url                  = 'https://' . Hosts::retail() . '/produto/abc/';

		$this->assertSame( $url, SeoGuards::filter_canonical( $url ) );
	}

	public function test_sitemaps_are_disabled_on_b2b_only() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->assertFalse( SeoGuards::disable_sitemaps_on_b2b( true ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertTrue( SeoGuards::disable_sitemaps_on_b2b( true ) );
	}
}
