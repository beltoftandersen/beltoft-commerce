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

	public function test_a_clean_month_reports_zero() {
		$this->order_with_status( 'valid' );

		$this->assertSame( 0, VatReviewQueue::month_end_report( gmdate( 'Y-m' ) )['count'] );
	}
}
