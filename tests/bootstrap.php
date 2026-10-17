<?php
/**
 * PHPUnit bootstrap.
 *
 * The WordPress test suite runs against its own throwaway install in
 * /tmp/wordpress, so WP_CONTENT_DIR does NOT point at the staging site.
 * Plugins are loaded from the staging tree by absolute path instead.
 *
 * WooCommerce loads first — beltoft-commerce hooks WooCommerce filters at
 * plugins_loaded and fails without it.
 */

define( 'BELTOFT_SITE_PLUGINS', '/var/www/staging.mindthetrash.pt/wp-content/plugins' );

// The hostnames, as wp-config.php sets them per environment (Support\Hosts).
define( 'BELTOFT_B2B_HOST', 'b2b.staging.mindthetrash.pt' );
define( 'BELTOFT_RETAIL_HOST', 'staging.mindthetrash.pt' );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

require_once $_tests_dir . '/includes/functions.php';

// The host mu-plugin works only while beltoft-commerce is active; the test
// install loads the plugin by path, so say it is.
tests_add_filter( 'option_active_plugins', function ( $plugins ) {
	$plugins   = (array) $plugins;
	$plugins[] = 'beltoft-commerce/beltoft-commerce.php';

	return array_values( array_unique( $plugins ) );
} );

tests_add_filter( 'muplugins_loaded', function () {
	// The early host handler the plugin ships (mu-plugin/), loaded before
	// WooCommerce as the installed copy is.
	require BELTOFT_SITE_PLUGINS . '/beltoft-commerce/mu-plugin/beltoft-commerce-host.php';

	require BELTOFT_SITE_PLUGINS . '/woocommerce/woocommerce.php';

	$plugin = BELTOFT_SITE_PLUGINS . '/beltoft-commerce/beltoft-commerce.php';

	if ( file_exists( $plugin ) ) {
		require $plugin;
	}
} );

// WooCommerce needs its schema in the throwaway test database. Its normal
// activation path never runs here.
tests_add_filter( 'setup_theme', function () {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && class_exists( 'WC_Install' ) ) {
		WC_Install::install();

		if ( is_callable( array( 'WC', 'instance' ) ) ) {
			WC();
		}
	}
} );

require $_tests_dir . '/includes/bootstrap.php';

// The plugin's own table. Created once here so every test has it, rather
// than each test class installing it and the rest emitting database errors
// from the price-invalidation chain.
\BeltoftCommerce\Pricing\LookupIndex::install();
