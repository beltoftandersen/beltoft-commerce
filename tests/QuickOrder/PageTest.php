<?php

use BeltoftCommerce\Domain\HostRoutes;
use BeltoftCommerce\QuickOrder\Page;

class PageTest extends WP_UnitTestCase {

	/**
	 * The page is B2B-only like every other B2B surface, and HostRoutes is
	 * what keeps it off the retail host. Deriving the path from Page::PATH
	 * rather than repeating the string is what stops the two disagreeing.
	 */
	public function test_the_page_is_a_b2b_only_path() {
		$this->assertContains( Page::PATH, HostRoutes::b2b_only_paths() );
	}

	public function test_the_pad_parses_pasted_lines() {
		$lines = Page::parse_pad( "ALL_AN227\t12\nBME-2210;6\nQO-3, 4\nQO-4   9" );

		$this->assertSame(
			array(
				array( 'code' => 'ALL_AN227', 'quantity' => 12 ),
				array( 'code' => 'BME-2210', 'quantity' => 6 ),
				array( 'code' => 'QO-3', 'quantity' => 4 ),
				array( 'code' => 'QO-4', 'quantity' => 9 ),
			),
			$lines
		);
	}

	/**
	 * A paste out of Excel arrives tab-separated; a paste out of an email does
	 * not. A line with no quantity is a line someone is still typing.
	 */
	public function test_a_line_without_a_quantity_is_ignored() {
		$this->assertSame( array(), Page::parse_pad( "ALL_AN227\n\n   \n" ) );
	}

	/**
	 * A Portuguese decimal-comma quantity ("12,0") used to split into THREE
	 * parts on the separator regex, leaving a trailing "0" as the quantity
	 * and dropping the line with no report at all -- the one number format
	 * Reader::to_quantity() goes out of its way to support.
	 */
	public function test_a_portuguese_decimal_comma_quantity_is_not_treated_as_a_separator() {
		$this->assertSame(
			array(
				array( 'code' => 'ALL_AN227', 'quantity' => 12 ),
			),
			Page::parse_pad( "ALL_AN227\t12,0" )
		);
	}

	/**
	 * A real separating comma (code, quantity typed by hand) must keep
	 * working alongside the decimal-comma fix above.
	 */
	public function test_a_comma_separated_pair_still_splits_on_the_comma() {
		$this->assertSame(
			array(
				array( 'code' => 'QO-3', 'quantity' => 4 ),
			),
			Page::parse_pad( 'QO-3, 4' )
		);
	}

	/**
	 * The report must survive the redirect and appear once.
	 *
	 * This is the regression test for the worst failure this page had: the cart
	 * changed and the page said nothing at all. WooCommerce's notice session does
	 * not carry across the redirect here -- the session row holds no wc_notices
	 * key by the time the redirected GET renders -- and Bricks erases the queue
	 * early besides, so the report is stashed in a transient instead.
	 */
	public function test_the_shortcode_renders_a_stashed_report_and_then_forgets_it() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		set_transient(
			'beltoft_quick_order_report_' . $user_id,
			array(
				'success' => array( array( 'notice' => '2 linhas adicionadas ao carrinho.' ) ),
				'error'   => array( array( 'notice' => 'Referência desconhecida. ZZZ-1' ) ),
			),
			300
		);

		$first = Page::shortcode();

		$this->assertStringContainsString( '2 linhas adicionadas ao carrinho.', $first );
		$this->assertStringContainsString( 'ZZZ-1', $first );

		// Rendered once: a refresh must not replay a report for an order that
		// was already placed.
		$this->assertFalse( get_transient( 'beltoft_quick_order_report_' . $user_id ) );
		$this->assertStringNotContainsString( '2 linhas adicionadas ao carrinho.', Page::shortcode() );
	}

	/**
	 * A report belongs to the customer who submitted it.
	 */
	public function test_one_customers_report_is_not_shown_to_another() {
		$mine   = self::factory()->user->create();
		$theirs = self::factory()->user->create();

		set_transient( 'beltoft_quick_order_report_' . $theirs, array( 'success' => array( array( 'notice' => 'Segredo alheio.' ) ) ), 300 );

		wp_set_current_user( $mine );

		$this->assertStringNotContainsString( 'Segredo alheio.', Page::shortcode() );

		delete_transient( 'beltoft_quick_order_report_' . $theirs );
	}

	/**
	 * Each route carries its own button, and the button names the route.
	 *
	 * This is the contract maybe_handle_post() reads to decide which of the two
	 * panels the buyer actually used. Before it existed, one button at the foot
	 * of the form served both and the handler guessed from what was present.
	 */
	public function test_each_route_submits_with_its_own_button() {
		wp_set_current_user( self::factory()->user->create() );

		$html = Page::shortcode();

		$this->assertStringContainsString( 'name="beltoft_qo_route" value="pad"', $html );
		$this->assertStringContainsString( 'name="beltoft_qo_route" value="sheet"', $html );
	}

	/**
	 * The import button lives inside the spreadsheet panel, after the file
	 * control - not at the foot of the form.
	 *
	 * A buyer who attached a sheet and saw no next step beside it reported the
	 * page as "not doing anything". Position is the fix, so position is what
	 * this asserts: the import button must come after the file input, and the
	 * form must close before it would have room for a shared button.
	 */
	public function test_the_import_button_follows_the_file_control() {
		wp_set_current_user( self::factory()->user->create() );

		$html = Page::shortcode();

		$file   = strpos( $html, 'name="beltoft_sheet_file"' );
		$import = strpos( $html, 'value="sheet"' );

		$this->assertIsInt( $file );
		$this->assertIsInt( $import );
		$this->assertGreaterThan( $file, $import, 'The import button must render after the file input.' );
	}

	/**
	 * The drop target and the attached-file readout are both present, because
	 * the script that fills them in looks them up by these class names.
	 */
	public function test_the_sheet_panel_offers_a_drop_target_and_reports_the_attachment() {
		wp_set_current_user( self::factory()->user->create() );

		$html = Page::shortcode();

		$this->assertStringContainsString( 'beltoft-qo__drop', $html );
		$this->assertStringContainsString( 'beltoft-qo__chosen-name', $html );
		$this->assertStringContainsString( 'beltoft-qo__chosen-size', $html );
		$this->assertStringContainsString( 'beltoft-qo__chosen-clear', $html );
		$this->assertStringContainsString( 'data-qo-sheet', $html );
	}

	/**
	 * Nothing to import until something is attached, so the button starts
	 * disabled and the script enables it on the first file.
	 */
	public function test_the_import_button_starts_disabled() {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertMatchesRegularExpression( '/data-qo-import\s+disabled/', Page::shortcode() );
	}

	/**
	 * Only .xlsx is readable, and the picker should say so rather than let a
	 * buyer choose a .csv and find out after the upload.
	 */
	public function test_the_picker_asks_for_xlsx() {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertStringContainsString( 'accept=".xlsx"', Page::shortcode() );
	}
}
