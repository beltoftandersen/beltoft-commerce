<?php

namespace BeltoftCommerce\SavedCart;

defined( 'ABSPATH' ) || exit;

/**
 * Where a partner's saved orders live.
 *
 * A trade customer orders the same forty lines every few weeks. Rebuilding that
 * basket by hand each time is the single most tedious thing about ordering here,
 * so a basket can be kept, named and brought back.
 *
 * A table rather than user meta: these are lists that get listed, counted and
 * eventually looked at by somebody other than their owner - a sales contact
 * asking "what does this customer usually order". A serialised blob in usermeta
 * answers none of those questions without loading every user.
 */
class Table {

	private const VERSION_OPTION = 'beltoft_saved_carts_db_version';
	private const VERSION        = '1';

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'beltoft_saved_carts';
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * `items` is JSON rather than a second table of rows. A saved basket is
		 * only ever read and written whole - there is no query that asks which
		 * saved baskets contain a product - and one row per basket keeps saving
		 * a forty-line order a single insert.
		 */
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(120) NOT NULL DEFAULT '',
				items longtext NOT NULL,
				item_count smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY updated_at (updated_at)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
