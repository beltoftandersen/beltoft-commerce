<?php

namespace BeltoftCommerce\Support;

use BeltoftCommerce\Pricing\LookupIndex;
use BeltoftCommerce\Pricing\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin needs in the database, created by the plugin itself.
 *
 * The roles and the price lookup table used to exist only where someone had
 * run the install by hand (wp eval). On an install without them the trade
 * roles did not exist and every trade price sort or filter joined a missing
 * table. Runs on activation and, keyed on a version option, on the first
 * admin request after a deploy - deployment copies files and activates
 * nothing, so admin_init is the step that always happens.
 *
 * The lookup table is filled by a one-off cron job, not here: it walks every
 * product, and an admin page load is no place for that.
 */
class Installer {

	public const VERSION_OPTION = 'beltoft_commerce_db_version';

	/** Bump when install() gains a step. */
	public const VERSION = '1';

	public const BACKFILL_HOOK = 'beltoft_commerce_lookup_backfill';

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( self::BACKFILL_HOOK, array( LookupIndex::class, 'backfill' ) );
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		Roles::install();
		LookupIndex::install();

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
