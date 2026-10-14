<?php

use BeltoftCommerce\QuickOrder\Report;

class ReportTest extends WP_UnitTestCase {

	public function test_it_records_successes_and_rejections_separately() {
		$report = new Report();
		$report->add_success( 'ALL_AN227', 123, 12 );
		$report->add_rejection( 'XYZ-001', Report::UNKNOWN );

		$this->assertCount( 1, $report->successes() );
		$this->assertCount( 1, $report->rejections() );
		$this->assertTrue( $report->has_rejections() );
	}

	public function test_a_success_keeps_the_code_product_and_quantity() {
		$report = new Report();
		$report->add_success( 'ALL_AN227', 123, 12 );

		$this->assertSame(
			array( 'code' => 'ALL_AN227', 'product_id' => 123, 'quantity' => 12 ),
			$report->successes()[0]
		);
	}

	/**
	 * The screen groups rejections by reason rather than listing two hundred
	 * rows, so the grouping belongs here and not in the template.
	 */
	public function test_rejections_group_by_reason() {
		$report = new Report();
		$report->add_rejection( 'A', Report::UNKNOWN );
		$report->add_rejection( 'B', Report::UNKNOWN );
		$report->add_rejection( 'C', Report::OUT_OF_STOCK );

		$grouped = $report->rejections_by_reason();

		$this->assertSame( array( 'A', 'B' ), $grouped[ Report::UNKNOWN ] );
		$this->assertSame( array( 'C' ), $grouped[ Report::OUT_OF_STOCK ] );
	}

	/**
	 * NOT_AVAILABLE and NO_PRICE are distinct in code and deliberately
	 * identical on screen: telling a customer "this exists but you may not
	 * see it" leaks the B2B-only catalog (spec 4).
	 */
	public function test_unavailable_reasons_share_one_customer_message() {
		$this->assertSame(
			Report::message_for( Report::NOT_AVAILABLE ),
			Report::message_for( Report::NO_PRICE )
		);
		$this->assertNotSame(
			Report::message_for( Report::UNKNOWN ),
			Report::message_for( Report::OUT_OF_STOCK )
		);
	}

	/**
	 * The third argument is optional so every existing call site -- and every
	 * existing test -- keeps working unchanged.
	 */
	public function test_add_rejection_still_works_with_only_a_code_and_a_reason() {
		$report = new Report();
		$report->add_rejection( 'XYZ-001', Report::UNKNOWN );

		$this->assertCount( 1, $report->rejections() );
		$this->assertSame( Report::UNKNOWN, $report->rejections()[0]['reason'] );
	}

	/**
	 * I2 / Ruling 11: a NOT_AVAILABLE line and a NO_PRICE line share a
	 * customer-facing message on purpose (spec 4). Grouping by REASON prints
	 * that shared sentence twice, over two different code lists, which
	 * re-distinguishes exactly what sharing the wording was meant to hide.
	 * Grouping by MESSAGE collapses them into one notice.
	 */
	public function test_rejections_group_by_message_not_by_reason() {
		$report = new Report();
		$report->add_rejection( 'A', Report::NOT_AVAILABLE );
		$report->add_rejection( 'B', Report::NO_PRICE );
		$report->add_rejection( 'C', Report::OUT_OF_STOCK );

		$grouped = $report->rejections_by_message();

		$shared = Report::message_for( Report::NOT_AVAILABLE );

		$this->assertSame( array( 'A', 'B' ), $grouped[ $shared ] );
		$this->assertSame( array( 'C' ), $grouped[ Report::message_for( Report::OUT_OF_STOCK ) ] );
	}

	/**
	 * A detail -- e.g. WooCommerce's own "9 remaining" -- overrides the
	 * generic reason message for that one line, and lines sharing the exact
	 * same detail text group together.
	 */
	public function test_a_detail_overrides_the_generic_message_and_groups_on_itself() {
		$report = new Report();
		$report->add_rejection( 'STOCK-1', Report::REFUSED, 'Restam 9 unidades.' );
		$report->add_rejection( 'STOCK-2', Report::REFUSED, 'Restam 9 unidades.' );
		$report->add_rejection( 'STOCK-3', Report::REFUSED, 'Restam 2 unidades.' );

		$grouped = $report->rejections_by_message();

		$this->assertSame( array( 'STOCK-1', 'STOCK-2' ), $grouped['Restam 9 unidades.'] );
		$this->assertSame( array( 'STOCK-3' ), $grouped['Restam 2 unidades.'] );
		$this->assertArrayNotHasKey( Report::message_for( Report::REFUSED ), $grouped );
	}

	public function test_rejections_by_reason_is_unaffected_by_the_detail_override() {
		$report = new Report();
		$report->add_rejection( 'STOCK-1', Report::REFUSED, 'Restam 9 unidades.' );

		$grouped = $report->rejections_by_reason();

		$this->assertSame( array( 'STOCK-1' ), $grouped[ Report::REFUSED ] );
	}
}
