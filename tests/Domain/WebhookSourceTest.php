<?php

use BeltoftCommerce\Domain\UrlFilter;
use BeltoftCommerce\Domain\WebhookSource;
use BeltoftCommerce\Support\Hosts;

class WebhookSourceTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		UrlFilter::register();
	}

	public function tearDown(): void {
		UrlFilter::resume();
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	private function delivery( string $source ): array {
		return array(
			'method'  => 'POST',
			'body'    => '{"id":1}',
			'headers' => array(
				'Content-Type'             => 'application/json',
				'X-WC-Webhook-Source'      => $source,
				'X-WC-Webhook-Signature'   => 'unchanged',
			),
		);
	}

	/**
	 * The whole point: one shop, one name for it.
	 *
	 * The canonical value is read BEFORE the host is set, because reading it
	 * afterwards goes through UrlFilter and hands back the trade host - which
	 * is the very confusion this class exists to end, and it caught this test
	 * out first time round.
	 */
	public function test_a_trade_source_is_rewritten_to_the_canonical_host() {
		$canonical = trailingslashit( get_option( 'home' ) );

		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$out = WebhookSource::pin( $this->delivery( 'https://' . Hosts::b2b() . '/' ), 'https://beltoft.example/hook' );

		$this->assertSame( $canonical, $out['headers']['X-WC-Webhook-Source'] );
		$this->assertStringNotContainsString( Hosts::b2b(), $out['headers']['X-WC-Webhook-Source'] );
	}

	public function test_the_canonical_host_is_left_as_it_is() {
		$canonical = trailingslashit( get_option( 'home' ) );

		$out = WebhookSource::pin( $this->delivery( $canonical ), 'https://beltoft.example/hook' );

		$this->assertSame( $canonical, $out['headers']['X-WC-Webhook-Source'] );
	}

	/**
	 * The signature is generated from the body, so the header may be rewritten
	 * - but nothing else about the request may be.
	 */
	public function test_nothing_else_about_the_delivery_is_touched() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$in  = $this->delivery( 'https://' . Hosts::b2b() . '/' );
		$out = WebhookSource::pin( $in, 'https://beltoft.example/hook' );

		$this->assertSame( $in['body'], $out['body'] );
		$this->assertSame( 'unchanged', $out['headers']['X-WC-Webhook-Signature'] );
		$this->assertSame( 'application/json', $out['headers']['Content-Type'] );
	}

	/** Every outgoing HTTP request passes through this filter. */
	public function test_requests_that_are_not_webhooks_pass_straight_through() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$other = array( 'method' => 'GET', 'headers' => array( 'Accept' => 'application/json' ) );

		$this->assertSame( $other, WebhookSource::pin( $other, 'https://api.example/thing' ) );
		$this->assertSame( 'nonsense', WebhookSource::pin( 'nonsense', '' ) );
	}

	/** It has to work from inside queue processing, where UrlFilter is already suspended. */
	public function test_it_works_while_the_queue_is_running() {
		$canonical = trailingslashit( get_option( 'home' ) );

		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		UrlFilter::suspend();

		$out = WebhookSource::pin( $this->delivery( 'https://' . Hosts::b2b() . '/' ), '' );

		$this->assertSame( $canonical, $out['headers']['X-WC-Webhook-Source'] );
	}
}
