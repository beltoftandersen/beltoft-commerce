<?php

use BeltoftCommerce\Monitoring\MissingPriceScan;
use BeltoftCommerce\Pricing\SaleScheduler;

/**
 * The plugin's recurring jobs are scheduled by the plugin itself.
 *
 * Nothing called their schedule() methods: there is no activation hook, and
 * deployment copies files rather than activating anything. The sale boundary
 * job ran on staging only because somebody scheduled it by hand, and the
 * missing-price scan - the §2.5 alert naming SKUs without a group price - had
 * never run at all.
 */
class ScheduledJobsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		wp_clear_scheduled_hook( MissingPriceScan::HOOK );
		wp_clear_scheduled_hook( SaleScheduler::BOUNDARY_HOOK );
	}

	/**
	 * What an ordinary request's init does for this plugin. Only this plugin's
	 * callbacks: firing all of init again re-registers WooCommerce's blocks.
	 */
	private function run_init(): void {
		foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];

				if ( is_array( $fn ) && is_string( $fn[0] ) && 0 === strpos( $fn[0], 'BeltoftCommerce\\' ) ) {
					call_user_func( $fn );
				}
			}
		}
	}

	public function test_the_missing_price_scan_is_scheduled_by_an_ordinary_request() {
		$this->run_init();

		$this->assertNotFalse( wp_next_scheduled( MissingPriceScan::HOOK ) );
	}

	public function test_the_group_sale_boundary_job_is_scheduled_by_an_ordinary_request() {
		$this->run_init();

		$this->assertNotFalse( wp_next_scheduled( SaleScheduler::BOUNDARY_HOOK ) );
	}

	/** Scheduling is idempotent: a request never adds a second event. */
	public function test_requests_do_not_pile_up_events() {
		$this->run_init();
		$this->run_init();

		$events = array_filter(
			_get_cron_array(),
			fn( $hooks ) => isset( $hooks[ MissingPriceScan::HOOK ] )
		);

		$this->assertCount( 1, $events );
	}
}
