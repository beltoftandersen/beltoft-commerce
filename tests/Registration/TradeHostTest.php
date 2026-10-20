<?php

use BeltoftCommerce\Catalog\Taxonomy;
use BeltoftCommerce\Email\B2BApplicationEmail;
use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\TradeHost;
use BeltoftCommerce\SavedCart\Actions;
use BeltoftCommerce\Scanner\BackInStockLink;
use BeltoftCommerce\Support\Hosts;

/**
 * A partner's own trade host (user meta _beltoft_trade_host): recorded when
 * they apply or sign in on a trade host, and used for what is sent to them
 * from nowhere in particular - application emails, back-in-stock links,
 * orders with no recorded host (spec 2026-10-20, §3.2).
 */
class TradeHostTest extends WP_UnitTestCase {

	use TradeHosts;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		Taxonomy::register();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_an_application_records_the_trade_host_it_was_made_on() {
		$this->use_trade_hosts();
		$_SERVER['HTTP_HOST'] = self::$en_trade;
		$user = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );

		do_action( 'beltoft_commerce_b2b_applied', $user );

		$this->assertSame( self::$en_trade, get_user_meta( $user, TradeHost::META_KEY, true ) );
		$this->assertSame( self::$en_trade, TradeHost::for_user( $user ) );
		$this->assertSame( 'en', TradeHost::language_for_user( $user ) );
	}

	public function test_signing_in_on_a_trade_host_records_it_and_retail_does_not() {
		$this->use_trade_hosts();
		$user = get_userdata( self::factory()->user->create( array( 'role' => 'b2b_customer' ) ) );

		$_SERVER['HTTP_HOST'] = self::$en_trade;
		do_action( 'wp_login', $user->user_login, $user );
		$this->assertSame( self::$en_trade, TradeHost::for_user( $user->ID ) );

		$_SERVER['HTTP_HOST'] = Hosts::retail();
		do_action( 'wp_login', $user->user_login, $user );
		$this->assertSame( self::$en_trade, TradeHost::for_user( $user->ID ) );
	}

	/** Nothing recorded: the default trade host, in no particular language - as before. */
	public function test_without_a_record_the_default_trade_host() {
		$user = self::factory()->user->create( array( 'role' => 'b2b_customer' ) );

		$this->assertSame( Hosts::b2b(), TradeHost::for_user( $user ) );
		$this->assertSame( '', TradeHost::language_for_user( $user ) );
	}

	/** The three application emails are read in the language of the applicant's trade host (zerolang_email_language). */
	public function test_the_application_emails_use_the_trade_hosts_language() {
		$this->use_trade_hosts();
		WC()->mailer();
		$user = self::factory()->user->create( array( 'role' => 'b2b_pending' ) );
		update_user_meta( $user, TradeHost::META_KEY, self::$en_trade );

		$emails = WC()->mailer()->get_emails();
		$email  = $emails['beltoft_b2b_application_received'];
		$this->assertInstanceOf( B2BApplicationEmail::class, $email );
		$email->user = get_userdata( $user );

		$this->assertSame( 'en', apply_filters( 'zerolang_email_language', 'pt', $email, $email->user->user_email ) );

		// Another email, or an applicant with no trade host recorded, is left alone.
		$this->assertSame( 'pt', apply_filters( 'zerolang_email_language', 'pt', new WC_Email(), 'x@example.com' ) );
		delete_user_meta( $user, TradeHost::META_KEY );
		$this->assertSame( 'pt', apply_filters( 'zerolang_email_language', 'pt', $email, $email->user->user_email ) );
	}

	public function test_a_partners_back_in_stock_link_goes_to_their_own_trade_host() {
		$this->use_trade_hosts();
		self::factory()->user->create( array( 'role' => 'b2b_customer', 'user_email' => 'partner-en@example.com' ) );
		update_user_meta( get_user_by( 'email', 'partner-en@example.com' )->ID, TradeHost::META_KEY, self::$en_trade );

		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();
		Taxonomy::set_audience( $product->get_id(), Taxonomy::BOTH );

		WC()->mailer();
		$email            = new WC_Email();
		$email->recipient = 'partner-en@example.com';

		$this->assertSame( 'https://' . self::$en_trade . '/product/x/', BackInStockLink::filter( 'https://' . Hosts::retail() . '/product/x/', wc_get_product( $product->get_id() ), $email ) );
	}

	public function test_every_trade_host_is_a_safe_redirect_host() {
		$this->use_trade_hosts();

		$hosts = Actions::allow_b2b_host( array( Hosts::retail() ) );

		$this->assertContains( Hosts::b2b(), $hosts );
		$this->assertContains( self::$en_trade, $hosts );
	}
}
