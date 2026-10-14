<?php
/**
 * Plugin Name: Beltoft Commerce
 * Description: B2B/B2C pricing groups, catalog audience, domain routing and VAT validation.
 * Version:     0.1.0
 * Author:      beltoft.net
 * Text Domain: beltoft-commerce
 * Domain Path: /languages
 * Requires PHP: 8.3
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
