<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the early host handler (mu-plugin/beltoft-commerce-host.php) in
 * wp-content/mu-plugins, where it has to be to run before other plugins.
 *
 * It used to live only in the site repo, so installing the plugin elsewhere
 * gave a trade host with no early gate and no page-cache opt-out. Now the
 * plugin ships it and copies it into place: on activation, and on any admin
 * request where the installed copy differs from the shipped one (an update
 * brought a new version). Deactivation removes it, if it is still ours.
 *
 * When mu-plugins is not writable, administrators get a notice saying which
 * file to copy where.
 */
class MuPlugin {

	public const FILE = 'beltoft-commerce-host.php';

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function source(): string {
		return BELTOFT_COMMERCE_PATH . 'mu-plugin/' . self::FILE;
	}

	public static function target(): string {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	public static function is_current(): bool {
		return is_readable( self::target() ) && md5_file( self::target() ) === md5_file( self::source() );
	}

	public static function maybe_install(): void {
		if ( self::is_current() ) {
			return;
		}

		if ( ! self::install() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	public static function install(): bool {
		if ( ! is_readable( self::source() ) ) {
			return false;
		}

		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}

		// Through a temporary file and a rename, so no request ever loads half a file.
		$temp = self::target() . '.' . wp_generate_password( 8, false ) . '.tmp';

		if ( ! @copy( self::source(), $temp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failure is reported by the notice.
			return false;
		}

		if ( ! @rename( $temp, self::target() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		return true;
	}

	/** On deactivation: remove the installed copy, unless someone has changed it. */
	public static function remove(): void {
		if ( self::is_current() ) {
			@unlink( self::target() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: source file path, 2: target directory */
				__( 'Beltoft Commerce could not install its early host handler. Copy %1$s into %2$s.', 'beltoft-commerce' ),
				self::source(),
				WPMU_PLUGIN_DIR
			) )
		);
	}
}
