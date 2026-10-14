<?php

class HarnessTest extends WP_UnitTestCase {

	public function test_wordpress_is_loaded() {
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
	}

	public function test_woocommerce_is_loaded() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
	}

	/**
	 * Safety interlock, not a formality. The WordPress test suite drops
	 * every table in the database it is pointed at.
	 */
	public function test_it_is_not_running_against_a_real_database() {
		global $wpdb;

		$this->assertStringContainsString(
			'tests',
			$wpdb->dbname,
			'Refusing to run: the test suite drops all tables and is not pointed at a test database.'
		);
		$this->assertNotSame( 'woo_staging', $wpdb->dbname );
		$this->assertNotSame( 'woo_site_a', $wpdb->dbname );
	}
}
