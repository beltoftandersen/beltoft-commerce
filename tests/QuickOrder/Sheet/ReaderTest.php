<?php

use BeltoftCommerce\QuickOrder\Sheet\Reader;
use BeltoftCommerce\QuickOrder\Sheet\Writer;

class ReaderTest extends WP_UnitTestCase {

	/**
	 * Round trip. This proves we can read ourselves and nothing more — see the
	 * Excel test below, which is the one that matters.
	 */
	public function test_it_reads_back_a_sheet_we_wrote() {
		$xlsx = Writer::build( array(
			array( 'sku' => 'RT-1', 'name' => 'Produto', 'variant' => '', 'stock' => 'Em stock', 'price' => '1,00', 'rrp' => '2,00' ),
		) );

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		file_put_contents( $file, $xlsx );

		$lines = Reader::read( $file );
		unlink( $file );

		// No quantity was filled in, so there is nothing to order.
		$this->assertSame( array(), $lines );
	}

	/**
	 * THE TEST THAT MATTERS.
	 *
	 * The writer emits inline strings; Excel rewrites the workbook using a
	 * shared string table the moment a customer saves. A reader that only
	 * understands our own output fails on every file a customer actually
	 * sends back, while the round-trip test above stays green.
	 */
	public function test_it_reads_a_file_saved_by_excel() {
		$fixture = __DIR__ . '/../../fixtures/excel-saved.xlsx';

		$this->assertFileExists( $fixture );

		$lines = Reader::read( $fixture );

		$this->assertSame(
			array(
				array( 'code' => 'ALL_AN227', 'quantity' => 12 ),
				array( 'code' => 'CD_20153', 'quantity' => 3 ),
				array( 'code' => 'BME-2210', 'quantity' => 5 ),
			),
			$lines,
			'Shared strings, three numeric encodings, and a row with no quantity skipped.'
		);
	}

	/**
	 * Proven by running the shipped reader against a copy of the fixture with
	 * one column inserted before Quantidade: it read the price column as the
	 * quantity and ordered a line the customer left blank. The reader must
	 * key on the SKU/Quantidade COLUMN LABELS from the header row, not on
	 * fixed column letters, so a customer inserting, deleting or reordering
	 * columns cannot silently misplace an order.
	 */
	public function test_a_reordered_column_still_reads_the_same_lines() {
		$fixture = __DIR__ . '/../../fixtures/excel-reordered.xlsx';

		$this->assertFileExists( $fixture );

		$lines = Reader::read( $fixture );

		$this->assertSame(
			array(
				array( 'code' => 'ALL_AN227', 'quantity' => 12 ),
				array( 'code' => 'CD_20153', 'quantity' => 3 ),
				array( 'code' => 'BME-2210', 'quantity' => 5 ),
			),
			$lines,
			'A column inserted before Quantidade must not shift which cell is read as the quantity.'
		);
	}

	public function test_a_sheet_missing_the_expected_header_columns_is_rejected() {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Alguma coisa</t></is></c></row></sheetData>'
			. '</worksheet>';

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::OVERWRITE );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml );
		$zip->close();

		$this->expectException( InvalidArgumentException::class );

		try {
			Reader::read( $file );
		} finally {
			unlink( $file );
		}
	}

	public function test_a_malformed_worksheet_xml_is_rejected_instead_of_returning_no_lines() {
		$file = tempnam( sys_get_temp_dir(), 'qo' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::OVERWRITE );
		// Unterminated row element: valid zip, invalid XML.
		$zip->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1">' );
		$zip->close();

		$this->expectException( InvalidArgumentException::class );

		try {
			Reader::read( $file );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Excel writes `<si><r><t>...</t></r></si>` — separate runs — the moment
	 * formatting varies within one cell. `(string) $node->t` reads as empty
	 * for that shape, which used to silently drop the line.
	 */
	public function test_a_shared_string_built_from_multiple_runs_is_read_in_full() {
		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData>'
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
			. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>4</v></c></row>'
			. '</sheetData></worksheet>';

		$shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3">'
			. '<si><t>SKU</t></si>'
			. '<si><t>Quantidade</t></si>'
			. '<si><r><t>RUN-</t></r><r><t>221</t></r></si>'
			. '</sst>';

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::OVERWRITE );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
		$zip->addFromString( 'xl/sharedStrings.xml', $shared );
		$zip->close();

		$lines = Reader::read( $file );
		unlink( $file );

		$this->assertSame(
			array( array( 'code' => 'RUN-221', 'quantity' => 4 ) ),
			$lines
		);
	}

	public function test_a_decimal_comma_quantity_is_read_as_a_whole_number() {
		$this->assertSame( 12, Reader::to_quantity( '12,0' ) );
		$this->assertSame( 12, Reader::to_quantity( '12.0' ) );
		$this->assertSame( 12, Reader::to_quantity( '12' ) );
		$this->assertSame( 0, Reader::to_quantity( '' ) );
	}

	public function test_a_file_that_is_not_a_zip_is_rejected() {
		$file = tempnam( sys_get_temp_dir(), 'qo' );
		file_put_contents( $file, 'sku;qty' );

		$this->expectException( InvalidArgumentException::class );

		try {
			Reader::read( $file );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Rows and cells without their optional r attribute, as some generators
	 * write them: every row used to land on key 0, leaving only the last.
	 */
	public function test_rows_and_cells_without_references_are_all_read() {
		$cell = static fn( $v ) => is_int( $v ) ? '<c><v>' . $v . '</v></c>' : '<c t="inlineStr"><is><t>' . $v . '</t></is></c>';
		$row  = static fn( array $vs ) => '<row>' . implode( '', array_map( $cell, $vs ) ) . '</row>';

		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. $row( array( 'SKU', 'Produto', 'Quantidade' ) )
			. $row( array( 'A-1', 'Um', 2 ) )
			. $row( array( 'B-2', 'Dois', 5 ) )
			. '</sheetData></worksheet>';

		$file = tempnam( sys_get_temp_dir(), 'qo' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::OVERWRITE );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml );
		$zip->close();

		$lines = Reader::read( $file );
		unlink( $file );

		$this->assertSame(
			array(
				array( 'code' => 'A-1', 'quantity' => 2 ),
				array( 'code' => 'B-2', 'quantity' => 5 ),
			),
			$lines
		);
	}
}
