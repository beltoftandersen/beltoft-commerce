<?php

use BeltoftCommerce\Monitoring\VatReviewQueue;
use BeltoftCommerce\Vat\OrderEvidence;

class VatReviewQueueTest extends WP_UnitTestCase {

	private function order_with_status( string $status ): WC_Order {
		$order = new WC_Order();
		$order->update_meta_data( OrderEvidence::CHECK_STATUS, $status );
		$order->save();

		return $order;
	}

	public function test_unverified_orders_are_listable() {
		$this->order_with_status( 'unverified' );
		$this->order_with_status( 'valid' );

		$this->assertCount( 1, VatReviewQueue::pending() );
	}

	public function test_a_valid_order_is_not_in_the_queue() {
		$this->order_with_status( 'valid' );
		$this->order_with_status( 'domestic' );

		$this->assertCount( 0, VatReviewQueue::pending() );
	}

	public function test_the_month_end_report_counts_unresolved_orders() {
		$this->order_with_status( 'unverified' );

		$report = VatReviewQueue::month_end_report( gmdate( 'Y-m' ) );

		$this->assertSame( gmdate( 'Y-m' ), $report['month'] );
		$this->assertGreaterThanOrEqual( 1, $report['count'] );
		$this->assertNotEmpty( $report['orders'] );
	}

	private function unverified_at( string $local_time ): int {
		$order = $this->order_with_status( 'unverified' );
		$order->set_date_created( ( new DateTimeImmutable( $local_time, wp_timezone() ) )->getTimestamp() );
		$order->save();

		return $order->get_id();
	}

	private function report_ids( string $month ): array {
		return array_column( VatReviewQueue::month_end_report( $month )['orders'], 'id' );
	}

	/**
	 * The range ended at the 1st of the next month, inclusive, so that day's
	 * orders were in two months' reports - and the bounds were UTC, not the
	 * shop's day.
	 */
	public function test_each_order_is_in_exactly_one_month_in_shop_time() {
		update_option( 'timezone_string', 'Europe/Lisbon' );

		$last_day  = $this->unverified_at( '2026-09-30 23:30:00' );
		$first_day = $this->unverified_at( '2026-10-01 00:30:00' );

		$september = $this->report_ids( '2026-09' );
		$october   = $this->report_ids( '2026-10' );

		$this->assertContains( $last_day, $september );
		$this->assertNotContains( $first_day, $september );
		$this->assertContains( $first_day, $october );
		$this->assertNotContains( $last_day, $october );
	}

	public function test_a_clean_month_reports_zero() {
		$this->order_with_status( 'valid' );

		$this->assertSame( 0, VatReviewQueue::month_end_report( gmdate( 'Y-m' ) )['count'] );
	}
}
