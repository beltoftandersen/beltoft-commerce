<?php

namespace BeltoftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Single boot entry point. Every hook this plugin registers is wired here,
 * so the whole policy surface is readable in one file.
 */
class Plugin {

	public static function init(): void {
		load_plugin_textdomain(
			'beltoft-commerce',
			false,
			dirname( plugin_basename( BELTOFT_COMMERCE_FILE ) ) . '/languages'
		);

		Support\Installer::register();
		Domain\LoginGate::register();
		// Trade pages resolved on retail by any spelling (?pagename=, ?page_id=, case, encoding).
		add_action( 'template_redirect', array( Domain\HostRoutes::class, 'maybe_redirect_resolved' ), 2 );
		Domain\LoginThrottle::register();
		Domain\SeoGuards::register();
		Domain\CacheRules::register();
		Domain\OrderHost::register();
		Domain\WebhookSource::register();
		Domain\PasswordReset::register();
		Bricks\HostCondition::register();
		Bricks\HostChrome::register();
		Frontend\LoopQuantity::register();
		Frontend\AddToCartUrl::register();
		Frontend\RelevanssiSearch::register();
		Frontend\ResellerTags::register();
		Frontend\VariationRows::register();
		Frontend\CartNewestFirst::register();
		Frontend\CheckoutResume::register();
		// Filter drawer and responsive filter layout are native Bricks elements.
		Frontend\FilterCounts::register();
		Frontend\RetailOnlyAssets::register();
		Frontend\FontDelivery::register();
		Frontend\OrderingPageAssets::register();
		Frontend\QueryResultCache::register();
		// Frontend\TwoPhaseList::register(); // DISABLED - starves the Bricks filters, see the class docblock.
		Frontend\VariationCodes::register();
		QuickOrder\Page::register();
		// The trade list's place in beltoft-scanner, through its filters only.
		Scanner\ScannerContext::register();
		Scanner\BackInStockLink::register();
		Registration\FormAction::register();
		Registration\AdminUsers::register();

		// The three application emails are WooCommerce emails, so they are
		// registered the way WooCommerce expects rather than hooked directly.
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );

		/*
		 * Force the mailer up before each trigger fires.
		 *
		 * woocommerce_email_classes only runs from WC_Emails::__construct(), and
		 * WooCommerce constructs that lazily. Our three actions are not in
		 * woocommerce_email_actions, so nothing guarantees the mailer exists when
		 * they fire - and with woocommerce_defer_transactional_emails on, WC may
		 * never call WC()->mailer() during the request at all. The email classes
		 * would then never be constructed, their listeners never registered, and
		 * do_action() would reach nobody, silently. beltoft-gift-cards hit this
		 * and defends the same way (its Plugin.php:96).
		 */
		foreach ( array( 'beltoft_commerce_b2b_applied', 'beltoft_commerce_b2b_approved', 'beltoft_commerce_b2b_rejected' ) as $trigger ) {
			add_action( $trigger, array( __CLASS__, 'ensure_mailer_loaded' ), 5 );
		}

		Hooks\Price::register();
		Hooks\VariationPrice::register();
		Pricing\SaleScheduler::register();
		Pricing\LookupIndex::register();
		Hooks\PriceQuery::register();

		// On init, not here: its labels are translated, and translating during
		// plugins_loaded logs _load_textdomain_just_in_time on every request.
		add_action( 'init', array( Catalog\Taxonomy::class, 'register' ), 5 );
		Catalog\CatalogAudience::register();
		add_action( 'woocommerce_update_product', array( Frontend\ResellerTags::class, 'flush' ) );
		Enforcement\QueryFilter::register();
		Enforcement\SingleProduct::register();
		Enforcement\Purchasable::register();
		Enforcement\CartGuard::register();
		Enforcement\StoreApiGuard::register();
		Enforcement\SearchIndex::register();
		Enforcement\FeedGuard::register();
		Enforcement\NavCounts::register();

		Account\B2BAccountPage::register();
		// Trade pages at root paths retail also uses - same option-based pattern as the two above.
		Frontend\HostPages::register();
		Account\MenuItems::register();
		Account\Reorder::register();
		SavedCart\Actions::register();
		SavedCart\Ui::register();
		SavedCart\AccountEndpoint::register();
		Checkout\B2BCheckoutPage::register();
		Checkout\PurchaseOrder::register();
		Checkout\NoCartPage::register();
		Checkout\ItemCodes::register();
		Checkout\B2BPaymentMethods::register();

		Checkout\BankDetailsGuard::register();
		Vat\Field::register();
		Vat\RateLabel::register();
		Vat\OrderEvidence::register();
		Vat\TaxDisplay::register();
		Vat\TaxBasis::register();
		Vat\Exemption::register();
		Vat\OrderTaxDisplay::register();
		Vat\AccountEndpoint::register();
		Shipping\B2BMethods::register();

		Monitoring\VatReviewQueue::register();

		/*
		 * The group sale boundary job schedules itself. There is no activation
		 * step to do it - deployment copies files - so it existed only where
		 * someone had scheduled it by hand. schedule() checks wp_next_scheduled()
		 * first, which reads the already-loaded cron option: one array lookup.
		 */
		add_action( 'init', array( Pricing\SaleScheduler::class, 'schedule' ) );

		if ( is_admin() ) {
			Admin\PriceFields::register();

			if ( ! Support\Hosts::is_b2b_configured() ) {
				add_action( 'admin_notices', array( __CLASS__, 'needs_b2b_host' ) );
			}
		}
	}

	/** The trade host is set in wp-config.php; without it there is no trade site. */
	public static function needs_b2b_host(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses(
				__( 'Beltoft Commerce has no trade site: set its hostname with <code>define( \'BELTOFT_B2B_HOST\', \'b2b.example.com\' );</code> in wp-config.php.', 'beltoft-commerce' ),
				array( 'code' => array() )
			)
		);
	}

	/**
	 * @param array $emails
	 * @return array
	 */
	public static function register_email_classes( $emails ) {
		$emails['beltoft_b2b_application_received'] = new Email\B2BApplicationReceivedEmail();
		$emails['beltoft_b2b_application_approved'] = new Email\B2BApplicationApprovedEmail();
		$emails['beltoft_b2b_application_rejected'] = new Email\B2BApplicationRejectedEmail();

		return $emails;
	}

	/**
	 * Instantiate the WooCommerce mailer so the email classes register their
	 * listeners before the trigger reaches priority 10.
	 */
	public static function ensure_mailer_loaded(): void {
		if ( function_exists( 'WC' ) && method_exists( WC(), 'mailer' ) ) {
			WC()->mailer();
		}
	}
}
