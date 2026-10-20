<?php
/**
 * Plugin Name: Beltoft Commerce — early host handling
 * Description: Gates the trade host and keeps generated URLs on the requested host before other plugins load. Installed and updated by Beltoft Commerce; does nothing while that plugin is inactive.
 *
 * SHIPPED WITH BELTOFT COMMERCE. The source is the plugin's
 * mu-plugin/beltoft-commerce-host.php; Support\MuPlugin copies it here on
 * activation and after an update, and removes it on deactivation. Edit it
 * there, not here - a copy edited here is replaced.
 *
 * A thin stub: the logic lives in the plugin so it can be unit tested. Only
 * the early registration has to happen here, because WooCommerce builds URLs
 * during its own load and page-cache plugins serve cached files during
 * theirs - a filter added at plugins_loaded is already too late. See
 * architecture §2.1 and §2.3.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Only while beltoft-commerce is active. This file loads the plugin's classes
 * by path, so with the plugin deactivated it kept gating, redirecting and
 * unloading plugins on the trade host while pricing, catalogue rules and VAT
 * handling were gone - trade buyers shopping at retail prices, silently.
 *
 * The plugin's folder is whatever it was installed as (a zip from GitHub is
 * beltoft-commerce-main): Support\MuPlugin records its basename.
 */
$beltoft_basename = (string) get_option( 'beltoft_commerce_basename', 'beltoft-commerce/beltoft-commerce.php' );

if ( ! in_array( $beltoft_basename, (array) get_option( 'active_plugins', array() ), true ) ) {
	return;
}

$beltoft_plugins = defined( 'BELTOFT_SITE_PLUGINS' ) ? BELTOFT_SITE_PLUGINS : WP_PLUGIN_DIR;

foreach ( array( 'Support/Config', 'Support/TradePath', 'Support/Hosts', 'Domain/UrlFilter', 'Domain/LoginGate', 'Domain/HostRoutes', 'Domain/PluginPolicy' ) as $beltoft_class ) {
	$beltoft_file = $beltoft_plugins . '/' . dirname( $beltoft_basename ) . '/src/' . $beltoft_class . '.php';

	if ( is_readable( $beltoft_file ) ) {
		require_once $beltoft_file;
	}
}

if ( class_exists( 'BeltoftCommerce\\Domain\\UrlFilter' ) ) {
	BeltoftCommerce\Domain\UrlFilter::register();
}

/*
 * One trade host per language (BELTOFT_B2B_HOSTS): the trade hosts are
 * Zerolang's "trade" host set, so a link to another language on a trade host
 * goes to that language's trade host, never to a retail domain. Here because
 * Zerolang decides a request's language while its own file loads, before any
 * ordinary plugin could add the filter. It reads it only under separate
 * domains; without the map nothing is added and nothing changes.
 */
if ( class_exists( 'BeltoftCommerce\\Support\\Hosts' ) && BeltoftCommerce\Support\Hosts::b2b_hosts() ) {
	add_filter( 'zerolang_host_sets', array( 'BeltoftCommerce\\Support\\Hosts', 'zerolang_sets' ), 10, 3 );
}

/*
 * Decided before WordPress reads the active plugin list, which is why it lives
 * here rather than in the plugin's own bootstrap - by the time beltoft-commerce
 * loads, every other plugin has already loaded too.
 */
if ( class_exists( 'BeltoftCommerce\\Domain\\PluginPolicy' ) ) {
	BeltoftCommerce\Domain\PluginPolicy::register();
}

/*
 * Never for WP-CLI: a command run with --url=<trade host> was answered with
 * a redirect and an exit - it printed nothing, ran nothing and still exited 0.
 */
$beltoft_cli = defined( 'WP_CLI' ) && WP_CLI;

/*
 * Gate the trade host BEFORE any plugin loads.
 *
 * Page-cache plugins serve a cached file during their own load. WP Fastest
 * Cache keys its cache by path, not by host, so without this the trade host
 * serves the retail catalog from cache and never reaches the authoritative
 * gate. Verified: with a warm cache and this removed, https://b2b.<host>/loja/
 * returned 200 with the full retail catalog.
 */
if ( ! $beltoft_cli && class_exists( 'BeltoftCommerce\\Domain\\LoginGate' ) ) {
	BeltoftCommerce\Domain\LoginGate::maybe_redirect_early();
}

/*
 * Send the trade-only paths to the trade host before anything loads.
 *
 * Cheapest place it can possibly happen: a header and an exit, with no
 * WooCommerce, no product query and no Bricks render performed and then thrown
 * away - and nothing of the sort entering the retail page cache.
 */
if ( ! $beltoft_cli && class_exists( 'BeltoftCommerce\\Domain\\HostRoutes' ) ) {
	BeltoftCommerce\Domain\HostRoutes::maybe_redirect_early();

	/*
	 * And once the language plugin has decided the request's language: a
	 * retail language domain (mindthetrash.com) and a language-prefixed path
	 * (/es/acceder/) can only be sent to their language's trade host then.
	 * Priority 2: after Zerolang's path-mode resolve at 1, before anything
	 * queries or renders.
	 */
	add_action( 'plugins_loaded', array( 'BeltoftCommerce\\Domain\\HostRoutes', 'maybe_redirect_on_language' ), 2 );
}

/*
 * The trade host must never be page-cached: logged-out requests are a login
 * page and logged-in requests are authenticated, so no cacheable state exists
 * there (architecture §2.9). Set here because page-cache plugins decide whether
 * to serve a cached file long before plugins_loaded; without it WP Fastest
 * Cache served the retail page on the trade hostname - identical bytes, retail
 * URLs, PHP never ran.
 */
if ( class_exists( 'BeltoftCommerce\\Support\\Hosts' ) && BeltoftCommerce\Support\Hosts::is_b2b_host() ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
}

unset( $beltoft_basename, $beltoft_plugins, $beltoft_class, $beltoft_file, $beltoft_cli );
