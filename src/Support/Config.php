<?php

namespace BeltoftCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The site-specific settings, in one place.
 *
 * The plugin was written for mindthetrash, and its paths, template slugs,
 * home country and so on were literals scattered through the code. They are
 * collected here with those values as defaults, so mindthetrash needs no
 * configuration and another shop changes what differs:
 *
 *     define( 'BELTOFT_COMMERCE_CONFIG', array(
 *         'login_path'   => '/login/',
 *         'home_country' => 'ES',
 *     ) );
 *
 * A wp-config constant, because the host mu-plugin reads paths before any
 * plugin or theme loads (see Hosts). Code that runs later may also use the
 * beltoft_commerce_config filter. Hostnames are not here: they have their own
 * constants (Hosts).
 *
 * Loaded by the mu-plugin by path, so it must not depend on the autoloader or
 * on any other plugin class.
 */
class Config {

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			// The trade host's public pages: logged-out visitors reach these only.
			'login_path'            => '/entrar/',
			'register_path'         => '/registo/',

			// WooCommerce's lost-password endpoint, on the retail account page and
			// on the trade one (and its old address, for links already mailed).
			'lost_password_paths'   => array(
				'/a-minha-conta/recuperar-palavra-passe/',
				'/conta/recuperar-palavra-passe/',
				'/conta-b2b/recuperar-palavra-passe/',
			),

			// Trade-only pages, which redirect to the trade host from anywhere else.
			'account_path'          => '/conta/',
			'account_legacy_paths'  => array( '/conta-b2b/' ),
			'quick_order_path'      => '/encomenda-rapida/',
			'checkout_legacy_paths' => array( '/finalizar-b2b/' ),

			// Where the trade checkout is served on the trade host (a HostPages key).
			'checkout_path'         => '/finalizar/',

			// Path prefix of the trade copies' physical slugs (HostPages), e.g. /b2b-loja/.
			'trade_page_prefix'     => '/b2b-',

			// The HostPages key of the trade ordering list.
			'ordering_page'         => 'loja',

			// Bricks templates used as the trade host's header and footer, by slug.
			'header_template'       => 'b2b-cabecalho',
			'footer_template'       => 'b2b-rodape',

			// The shop's own country: a VAT number from here is a domestic sale.
			'home_country'          => 'PT',

			// Plugin folders not loaded on trade-host page views (PluginPolicy).
			'unload_on_trade'       => array(
				'super-socializer/',
				'woo-smart-wishlist/',
				'wpc-composite-products/',
				'pymntpl-paypal-woocommerce/',
				'multibanco-ifthen-software-gateway-for-woocommerce/',
				'beltoft-loyalty-rewards/',
				'beltoft-loyalty-rewards-pro/',
				'duracelltomi-google-tag-manager/',
				'pressidium-cookie-consent/',
				'woocommerce-subscriptions/',
				'meta-box/',
				'beltoft-gift-cards/',
				'woo-cancel-abandoned-order/',
			),

			/*
			 * The retail host's robots.txt rules, one line each, or null to leave
			 * robots.txt to WordPress and the SEO plugin. These are mindthetrash's,
			 * from its old physical file; the SEO plugin adds the Sitemap line.
			 */
			'retail_robots'         => array_merge(
				array(
					'User-agent: *',
					'Disallow: /wp-admin/',
					'Disallow: /*add-to-cart=*',
					'Disallow: /*?add-to-cart=*',
					'Disallow: /*add_to_wishlist=*',
					'Disallow: /wishlist/WOOSW/',
					'Disallow: /loja/produto-categorias-*',
					'Disallow: /loja/produto-marcas-*',
					'Allow: /wp-admin/admin-ajax.php',
					'',
				),
				array_map(
					static fn( $agent ) => 'User-agent: ' . $agent,
					array(
						'CCBot', 'Scrapy', 'ChatGPT-User', 'GPTBot', 'Google-Extended', 'Applebot-Extended',
						'anthropic-ai', 'ClaudeBot', 'Omgilibot', 'Omgili', 'FacebookBot', 'Diffbot', 'Bytespider',
						'ImagesiftBot', 'PerplexityBot', 'cohere-ai', 'Meta-ExternalAgent', 'Meta-ExternalFetcher',
						'Timpibot', 'barkrowler', 'Amazonbot',
					)
				),
				array( 'Disallow: /' )
			),
		);
	}

	/**
	 * One setting: the default, then the wp-config constant, then the filter.
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		$config = self::defaults();

		if ( defined( 'BELTOFT_COMMERCE_CONFIG' ) && is_array( BELTOFT_COMMERCE_CONFIG ) ) {
			$config = array_merge( $config, BELTOFT_COMMERCE_CONFIG );
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * @param array<string,mixed> $config All settings, defaults and wp-config applied.
			 */
			$config = (array) apply_filters( 'beltoft_commerce_config', $config );
		}

		return $config[ $key ] ?? null;
	}

	/** A path setting, always with leading and trailing slash. */
	public static function path( string $key ): string {
		return '/' . trim( (string) self::get( $key ), '/' ) . '/';
	}

	/** @return string[] A list-of-paths setting, each with leading and trailing slash. */
	public static function paths( string $key ): array {
		return array_values( array_map(
			static fn( $path ) => '/' . trim( (string) $path, '/' ) . '/',
			(array) self::get( $key )
		) );
	}
}
