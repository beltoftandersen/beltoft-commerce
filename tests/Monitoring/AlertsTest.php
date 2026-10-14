<?php

use BeltoftCommerce\Monitoring\Alerts;

class AlertsTest extends WP_UnitTestCase {

	private array $sent = array();

	public function setUp(): void {
		parent::setUp();
		$this->sent = array();
		add_filter( 'beltoft_commerce_send_missing_price_alert', function ( $send, $body ) {
			$this->sent[] = $body;

			return false; // never actually send from the suite
		}, 10, 2 );
	}

	public function tearDown(): void {
		remove_all_filters( 'beltoft_commerce_send_missing_price_alert' );
		delete_transient( 'beltoft_commerce_missing_price_notice' );
		parent::tearDown();
	}

	/**
	 * "Some products are missing prices" is not actionable — §2.5 requires
	 * the SKU.
	 */
	public function test_the_alert_names_each_sku() {
		Alerts::missing_prices( array( 'b2b_standard' => array( 123 => 'SKU-ABC', 456 => 'SKU-DEF' ) ) );

		$this->assertNotEmpty( $this->sent );
		$this->assertStringContainsString( 'SKU-ABC', $this->sent[0] );
		$this->assertStringContainsString( 'SKU-DEF', $this->sent[0] );
		$this->assertStringContainsString( '#123', $this->sent[0] );
	}

	public function test_no_alert_is_raised_for_a_clean_scan() {
		Alerts::missing_prices( array( 'b2b_standard' => array() ) );

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * The recipient must come from configuration, never from a customer
	 * record — this install carries a live customer list.
	 */
	public function test_the_recipient_comes_from_configuration() {
		update_option( Alerts::RECIPIENT_OPTION, 'finance@example.test' );

		$this->assertSame( 'finance@example.test', Alerts::recipient() );

		delete_option( Alerts::RECIPIENT_OPTION );

		$this->assertSame( get_option( 'admin_email' ), Alerts::recipient() );
	}
}
