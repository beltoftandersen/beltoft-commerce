<?php

use BeltoftCommerce\Frontend\HostPages;
use BeltoftCommerce\Frontend\OrderingPage;
use BeltoftCommerce\Frontend\QuickOrderPagePathShim;
use BeltoftCommerce\Support\Hosts;

class QuickOrderPagePathShimTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( HostPages::OPTION );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$this->go_to( '/' );
		parent::tearDown();
	}

	/** One answer for "where do I send someone" - a link cannot go two places. */
	public function test_the_ordering_path_is_loja() {
		$this->assertSame( '/loja/', QuickOrderPagePathShim::ordering_path() );
		$this->assertSame( array( '/loja/' ), QuickOrderPagePathShim::ordering_paths() );
	}


	public function test_it_recognises_the_configured_page_and_nothing_else() {
		$page  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'trade-list' ) );
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'contacto' ) );
		update_option( HostPages::OPTION, array( OrderingPage::slug() => $page ) );

		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( QuickOrderPagePathShim::is_ordering_page(), 'the configured page' );

		$this->go_to( get_permalink( $other ) );
		$this->assertFalse( QuickOrderPagePathShim::is_ordering_page(), 'another page' );
	}

	/** Without a configured page there is no ordering page, on any request. */
	public function test_nothing_is_the_ordering_page_when_none_is_configured() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'trade-list' ) );
		$this->go_to( get_permalink( $page ) );

		$this->assertFalse( QuickOrderPagePathShim::is_ordering_page() );
	}
}
