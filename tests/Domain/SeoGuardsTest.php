<?php

use BeltoftCommerce\Domain\SeoGuards;
use BeltoftCommerce\Support\Hosts;

class SeoGuardsTest extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	/**
	 * Slim SEO owns robots.txt: it runs at 9999 and adds its rules and the
	 * Sitemap line. Nothing here may run after it and replace its output -
	 * on either host. The trade host stays out of the index through its
	 * noindex header and meta tag, which a crawler must be allowed to read.
	 */
	public function test_nothing_runs_after_slim_seo_on_robots_txt() {
		global $wp_filter;

		foreach ( $wp_filter['robots_txt']->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];

				if ( is_array( $fn ) && SeoGuards::class === ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) ) {
					$this->assertLessThan( 9999, $priority, 'SeoGuards::' . $fn[1] . ' runs after Slim SEO' );
				}
			}
		}
	}

	public function test_a_slim_seo_sitemap_line_survives_on_the_trade_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$slim_seo_output      = "User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://" . Hosts::b2b() . "/sitemap.xml\n";

		$this->assertStringContainsString( 'Sitemap: https://' . Hosts::b2b() . '/sitemap.xml', apply_filters( 'robots_txt', $slim_seo_output, true ) );
	}

	/** De-indexing the trade host does not depend on robots.txt. */
	public function test_the_trade_host_still_sends_noindex() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		ob_start();
		SeoGuards::print_noindex();

		$this->assertStringContainsString( 'noindex, nofollow', ob_get_clean() );
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

	/** Everything production's physical robots.txt says, so deleting it changes nothing. */
	public function test_the_retail_rules_match_production() {
		$rules = SeoGuards::retail_robots_rules();

		foreach ( array( 'Disallow: /loja/produto-categorias-*', 'Disallow: /loja/produto-marcas-*', 'User-agent: GPTBot', 'User-agent: ClaudeBot', 'User-agent: Amazonbot' ) as $line ) {
			$this->assertStringContainsString( $line . "\n", $rules );
		}

		$this->assertStringEndsWith( "User-agent: Amazonbot\nDisallow: /\n", $rules );
		$this->assertStringNotContainsString( 'Sitemap:', $rules, 'Slim SEO adds it' );
	}
}
