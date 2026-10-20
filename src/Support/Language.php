<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * What this plugin asks of a language plugin, in one place.
 *
 * Zerolang keeps each translation of a page as its own post, linked in a
 * translation group. A translated copy of a trade page (the Spanish login
 * page, slug acceder) shares nothing with the Portuguese one (entrar) but that
 * link, so anything that decides "is this a trade page" by slug or path misses
 * every copy. The answer is to ask about the default-language page instead -
 * the anchor - which is the one the settings and the paths name.
 *
 * Optional: without Zerolang every page is its own anchor and the plugin
 * behaves exactly as before. Every call is guarded with class_exists().
 */
class Language {

	/**
	 * The default-language page a post is a translation of, or the post itself.
	 *
	 * Filterable (beltoft_commerce_default_language_post), for another
	 * language plugin and for tests.
	 */
	public static function default_sibling( int $post_id ): int {
		$anchor = $post_id;

		if ( $post_id && class_exists( '\Zerolang\Map' ) && class_exists( '\Zerolang\Languages' ) ) {
			$siblings = (array) \Zerolang\Map::siblings( 'post', $post_id );
			$default  = (string) \Zerolang\Languages::default_code();

			if ( '' !== $default && ! empty( $siblings[ $default ] ) ) {
				$anchor = (int) $siblings[ $default ];
			}
		}

		return (int) apply_filters( 'beltoft_commerce_default_language_post', $anchor, $post_id );
	}

	/**
	 * Load the translation groups of many posts at once, before asking
	 * default_sibling() about each (Zerolang otherwise asks per post).
	 *
	 * @param int[] $post_ids
	 */
	public static function prime( array $post_ids ): void {
		if ( $post_ids && class_exists( '\Zerolang\Map' ) && method_exists( '\Zerolang\Map', 'prime' ) ) {
			\Zerolang\Map::prime( 'post', array_map( 'intval', $post_ids ) );
		}
	}

	/**
	 * Query arguments that ask for every language at once (Zerolang §9a).
	 * A language plugin narrows get_posts() to the request's language
	 * otherwise; the extra query var means nothing to WordPress itself.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function every_language( array $args ): array {
		$args['zerolang_all_languages'] = true;

		return $args;
	}
}
