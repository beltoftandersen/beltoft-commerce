<?php

use BeltoftCommerce\Support\Hosts;

/**
 * One trade host per language, for tests (spec 2026-10-20, §1.1).
 *
 * The harness constants stay single-host (bootstrap.php): a test that needs
 * several trade hosts configures them through the beltoft_commerce_b2b_hosts
 * filter, and a language plugin through the beltoft_commerce_language_*
 * seams, so nothing here needs Zerolang.
 */
trait TradeHosts {

	/** The English trade host in these tests. */
	protected static string $en_trade = 'b2b.example.com';

	/** An English retail language domain. */
	protected static string $en_retail = 'example.com';

	/** A German retail language domain, a language with no trade host. */
	protected static string $de_retail = 'example.de';

	/** @var array<string,callable> filter name => callback, removed by forget_trade_hosts() */
	private array $trade_host_filters = array();

	protected function seam( string $hook, callable $callback, int $args = 1 ): void {
		add_filter( $hook, $callback, 10, $args );
		$this->trade_host_filters[] = array( $hook, $callback );
	}

	/**
	 * pt on BELTOFT_B2B_HOST, en on b2b.example.com, English trade paths,
	 * and a language plugin with pt (default), en, de and es.
	 */
	protected function use_trade_hosts( array $extra_config = array() ): void {
		$this->seam( 'beltoft_commerce_b2b_hosts', static fn() => array( 'pt' => Hosts::b2b(), 'en' => self::$en_trade ) );
		$this->seam( 'beltoft_commerce_config', static fn( $config ) => array_merge( $config, array(
			'trade_fallback_language' => 'en',
			'trade_paths'             => array(
				'en' => array(
					'login_path'          => '/sign-in/',
					'register_path'       => '/apply/',
					'account_path'        => '/account/',
					'quick_order_path'    => '/quick-order/',
					'checkout_path'       => '/checkout/',
					'lost_password_paths' => array( '/account/lost-password/' ),
				),
				'es' => array(
					'login_path'       => '/acceder/',
					'register_path'    => '/registro-de-socio/',
					'quick_order_path' => '/pedido-rapido/',
				),
			),
		), $extra_config ) );
		$this->seam( 'beltoft_commerce_language_codes', static fn() => array( 'pt', 'en', 'de', 'es' ) );
		$this->seam( 'beltoft_commerce_language_default', static fn() => 'pt' );
		$this->seam( 'beltoft_commerce_language_separate_domains', '__return_true' );
		$this->seam( 'beltoft_commerce_language_retail_host', static function ( $host, $code ) {
			return array( 'pt' => Hosts::retail(), 'en' => self::$en_retail, 'de' => self::$de_retail )[ $code ] ?? $host;
		}, 2 );
		$this->seam( 'beltoft_commerce_language_is_domain', static function ( $is, $host ) {
			return in_array( $host, array( Hosts::retail(), self::$en_retail, self::$de_retail ), true ) ? true : $is;
		}, 2 );
	}

	/** Path mode as on staging: one trade host, languages under /en/, /de/, /es/. */
	protected function use_path_mode(): void {
		$this->seam( 'beltoft_commerce_language_codes', static fn() => array( 'pt', 'en', 'de', 'es' ) );
		$this->seam( 'beltoft_commerce_language_default', static fn() => 'pt' );
		$this->seam( 'beltoft_commerce_language_separate_domains', '__return_false' );
		$this->seam( 'beltoft_commerce_config', static fn( $config ) => array_merge( $config, array(
			'path_language_prefixes' => array( 'en' => 'en', 'de' => 'de', 'es' => 'es' ),
			'trade_paths'            => array(
				'es' => array(
					'login_path'       => '/acceder/',
					'register_path'    => '/registro-de-socio/',
					'quick_order_path' => '/pedido-rapido/',
				),
			),
		) ) );
	}

	/** The language a request is in, as the language plugin would answer. */
	protected function in_language( string $code ): void {
		$this->seam( 'beltoft_commerce_language_current', static fn() => $code );
	}

	/**
	 * A translated copy: its language, its anchor, and its place in the
	 * anchor's translation group.
	 */
	protected function translation( int $anchor, int $copy, string $code ): void {
		$this->seam( 'beltoft_commerce_default_language_post', static fn( $id, $post_id ) => (int) $post_id === $copy ? $anchor : $id, 2 );
		$this->seam( 'beltoft_commerce_language_of_post', static fn( $lang, $post_id ) => (int) $post_id === $copy ? $code : $lang, 2 );
		$this->seam( 'beltoft_commerce_language_siblings', static function ( $siblings, $post_id ) use ( $anchor, $copy, $code ) {
			return in_array( (int) $post_id, array( $anchor, $copy ), true ) ? array_merge( (array) $siblings, array( 'pt' => $anchor, $code => $copy ) ) : $siblings;
		}, 2 );
	}

	protected function forget_trade_hosts(): void {
		foreach ( $this->trade_host_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}

		$this->trade_host_filters = array();
	}
}
