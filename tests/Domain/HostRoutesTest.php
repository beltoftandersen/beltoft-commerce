<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\Support\Hosts;

class HostRoutesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = Hosts::retail();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_a_b2b_path_redirects_from_retail() {
		$this->assertTrue( HostRoutes::should_redirect( '/b2b-loja/' ) );
		$this->assertTrue( HostRoutes::should_redirect( '/entrar/' ) );
	}

	public function test_a_bricks_builder_request_stays_on_the_canonical_admin_host() {
		$this->assertFalse( HostRoutes::should_redirect( '/b2b-loja/', true ) );
		$this->assertFalse( HostRoutes::should_redirect( '/entrar/', true ) );
	}

	public function test_an_ordinary_retail_path_is_untouched() {
		$this->assertFalse( HostRoutes::should_redirect( '/loja/' ) );
	}

	public function test_the_b2b_host_is_never_redirected_to_itself() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( HostRoutes::should_redirect( '/b2b-loja/' ) );
	}

	public function test_b2b_admin_and_login_requests_move_to_the_canonical_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-admin/edit.php?post_type=page&paged=2',
			HostRoutes::admin_redirect_target( '/wp-admin/edit.php?post_type=page&paged=2' )
		);
		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-login.php?action=lostpassword&source=b2b',
			HostRoutes::admin_redirect_target( '/wp-login.php?action=lostpassword&source=b2b' )
		);
		$this->assertSame(
			'https://' . Hosts::retail() . '/wp-admin',
			HostRoutes::admin_redirect_target( '/wp-admin', 'HEAD' )
		);
	}

	public function test_b2b_frontend_wordpress_endpoints_stay_on_the_requesting_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/admin-ajax.php?action=filter' ) );
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/admin-post.php?action=saved_cart' ) );
	}

	public function test_b2b_logout_stays_on_b2b_to_clear_its_host_only_cookie() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertSame(
			'',
			HostRoutes::admin_redirect_target( '/wp-login.php?action=logout&redirect_to=%2Fentrar%2F&_wpnonce=abc123' )
		);
	}

	public function test_admin_redirect_does_not_move_posts_or_retail_requests() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-login.php', 'POST' ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		$this->assertSame( '', HostRoutes::admin_redirect_target( '/wp-admin/' ) );
	}
}
