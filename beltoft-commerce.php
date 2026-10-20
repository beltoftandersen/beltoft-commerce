<?php
/**
 * Plugin Name: Beltoft Commerce
 * Description: B2B/B2C pricing groups, catalog audience, domain routing and VAT validation.
 * Version:     0.1.0
 * Author:      beltoft.net
 * Text Domain: beltoft-commerce
 * Domain Path: /languages
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_COMMERCE_FILE', __FILE__ );
define( 'BELTOFT_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register( function ( $class ) {
	$prefix = 'BeltoftCommerce\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = BELTOFT_COMMERCE_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

add_action( 'plugins_loaded', array( 'BeltoftCommerce\\Plugin', 'init' ) );

/*
 * The early handler (mu-plugin) registers this; an installed copy from before
 * it did is replaced on the next admin request (Support\MuPlugin), and until
 * then the plugin registers it itself.
 */
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && false === has_action( 'plugins_loaded', array( 'BeltoftCommerce\\Domain\\HostRoutes', 'maybe_redirect_on_language' ) ) ) {
	add_action( 'plugins_loaded', array( 'BeltoftCommerce\\Domain\\HostRoutes', 'maybe_redirect_on_language' ), 2 );
}

// The early host handler, roles, the price lookup table and its first fill
// (Support\Installer); the handler and the scheduled jobs go on deactivation.
register_activation_hook( __FILE__, array( 'BeltoftCommerce\\Support\\Installer', 'install' ) );
register_deactivation_hook( __FILE__, array( 'BeltoftCommerce\\Support\\Installer', 'deactivate' ) );
