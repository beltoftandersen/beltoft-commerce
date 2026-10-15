<?php

namespace BeltoftCommerce\QuickOrder\Sheet;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a filled-in order sheet.
 *
 * THIS CANNOT BE AS SIMPLE AS THE WRITER, and that asymmetry is the whole
 * design. The Writer emits inline strings and needs no shared string table.
 * The moment a customer opens the file and saves it, Excel rewrites the entire
 * workbook USING a shared string table — so a reader that only understands what
 * we wrote fails on every file a customer actually sends back.
 *
 * It therefore handles both, and the test that proves it uses a fixture saved
 * by Excel. A round-trip test against our own output would stay green while
 * the feature was completely broken.
 *
 * KEYED ON THE SKU COLUMN, not on row position, so a customer may sort, filter
 * or delete rows before filling the sheet in. The column LETTERS themselves
 * are not trusted either — a customer who inserts a column shifts everything
 * after it, so the letters we key on are read from the header row, not
 * hard-coded.
 */
class Reader {

	public const MAX_ROWS = 2000;

	// A 2,000-row sheet is well under 1 MB; this only exists to stop a
	// crafted zip from inflating a huge part into memory before MAX_ROWS
	// can apply.
	private const MAX_PART_BYTES = 40 * 1024 * 1024;

	/**
	 * @return array<int, array{code: string, quantity: int}>
	 */
	public static function read( string $path ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new InvalidArgumentException( __( 'The file is not a valid .xlsx.', 'beltoft-commerce' ) );
		}

		$sheet = self::read_part( $zip, 'xl/worksheets/sheet1.xml', true );
		$share = self::read_part( $zip, 'xl/sharedStrings.xml', false );

		$zip->close();

		/*
		 * A malformed sheet1.xml inside an otherwise valid zip makes XMLReader
		 * emit warnings and simply stop, which used to return zero rows with
		 * no exception — a silent no-op the customer could not diagnose.
		 * Collecting libxml errors instead of letting them become PHP
		 * warnings turns that into a reported failure.
		 */
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		try {
			$strings = null === $share ? array() : self::shared_strings( $share );
			$parsed  = self::rows( $sheet, $strings );

			if ( libxml_get_errors() ) {
				throw new InvalidArgumentException( __( 'The sheet is damaged. Download a new sheet.', 'beltoft-commerce' ) );
			}
		} catch ( InvalidArgumentException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// SimpleXMLElement throws its own Exception on malformed XML,
			// bypassing libxml_get_errors() entirely.
			throw new InvalidArgumentException( __( 'The sheet is damaged. Download a new sheet.', 'beltoft-commerce' ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return self::lines( $parsed );
	}

	/**
	 * Stats the part before extracting it, so a hostile zip cannot inflate a
	 * huge part into a PHP string just to have MAX_ROWS discard most of it.
	 */
	private static function read_part( \ZipArchive $zip, string $name, bool $required ): ?string {
		$stat = $zip->statName( $name );

		if ( ! $stat ) {
			if ( $required ) {
				$zip->close();

				throw new InvalidArgumentException( __( 'The file is not a valid .xlsx.', 'beltoft-commerce' ) );
			}

			return null;
		}

		if ( $stat['size'] > self::MAX_PART_BYTES ) {
			$zip->close();

			throw new InvalidArgumentException( __( 'The sheet is too large.', 'beltoft-commerce' ) );
		}

		$contents = $zip->getFromName( $name );

		if ( false === $contents ) {
			if ( $required ) {
				$zip->close();

				throw new InvalidArgumentException( __( 'The file is not a valid .xlsx.', 'beltoft-commerce' ) );
			}

			return null;
		}

		return $contents;
	}

	/**
	 * A shared string built from a single run is `<si><t>...</t></si>`, but
	 * Excel writes `<si><r><t>...</t></r><r><t>...</t></r></si>` — several
	 * runs — the moment formatting varies within one cell (e.g. part of a SKU
	 * pasted in a different font). `(string) $node->t` is empty for that
	 * shape, which silently turns the cell into an empty string. Concatenate
	 * every `t` node under the `si`, regardless of how many runs there are.
	 *
	 * @return string[]
	 */
	private static function shared_strings( string $xml ): array {
		$strings = array();
		$reader  = new \XMLReader();
		$reader->XML( $xml );

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
				$node      = new \SimpleXMLElement( $reader->readOuterXml() );
				$strings[] = implode( '', array_map( 'strval', $node->xpath( './/*[local-name()="t"]' ) ) );
			}
		}

		$reader->close();

		return $strings;
	}

	/**
	 * The header row is found by CONTENT ('SKU' and 'Quantidade' labels), not
	 * by position — a customer who inserts a title row above the header would
	 * otherwise have that title row skipped instead of the real header, and
	 * the real header would be parsed as an order line. Once the header is
	 * found, every OTHER row keeps only the two cells the caller needs
	 * instead of every cell in the row, which also caps memory on a wide
	 * adversarial sheet.
	 *
	 * @param string[] $strings
	 * @return array{rows: array<int, array<string, string>>, sku: string, quantity: string}
	 */
	private static function rows( string $xml, array $strings ): array {
		$rows   = array();
		$map    = null;
		$number = 0;
		$reader = new \XMLReader();
		$reader->XML( $xml );

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) {
				continue;
			}

			$row = new \SimpleXMLElement( $reader->readOuterXml() );

			/*
			 * The r attribute is optional in the format and some generators
			 * leave it out. Keyed on it alone, every such row was row 0 and
			 * only the sheet's last line survived. Without it, a row is the
			 * one after the previous.
			 */
			$number = isset( $row['r'] ) ? (int) $row['r'] : $number + 1;

			if ( null === $map ) {
				$header = self::cell_values( $row, $strings, null );
				$found  = array_flip( array_map( 'trim', $header ) );

				if ( isset( $found['SKU'], $found['Quantidade'] ) ) {
					$map = array(
						'sku'      => $found['SKU'],
						'quantity' => $found['Quantidade'],
					);
				}

				// Never a data row: either the header itself, or a title row
				// written above it.
				continue;
			}

			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}

			$rows[ $number ] = self::cell_values( $row, $strings, array( $map['sku'], $map['quantity'] ) );
		}

		$reader->close();

		if ( null === $map ) {
			throw new InvalidArgumentException( __( 'The sheet does not have the SKU and Quantity columns. Download a new sheet.', 'beltoft-commerce' ) );
		}

		return array(
			'rows'     => $rows,
			'sku'      => $map['sku'],
			'quantity' => $map['quantity'],
		);
	}

	/**
	 * @param string[]     $strings
	 * @param string[]|null $letters Only these columns, or every column when null.
	 * @return array<string, string> Column letter => value.
	 */
	private static function cell_values( \SimpleXMLElement $row, array $strings, ?array $letters ): array {
		$cells  = array();
		$column = 0;

		foreach ( $row->c as $cell ) {
			// A cell without its r reference is the column after the previous one.
			$column = isset( $cell['r'] ) ? self::column_number( preg_replace( '/\d/', '', (string) $cell['r'] ) ) : $column + 1;
			$letter = self::column_letter( $column );

			if ( null !== $letters && ! in_array( $letter, $letters, true ) ) {
				continue;
			}

			$type = (string) $cell['t'];

			if ( 's' === $type ) {
				// A shared-string cell holds an index, not a value.
				$index            = (int) $cell->v;
				$cells[ $letter ] = $strings[ $index ] ?? '';
			} elseif ( 'inlineStr' === $type ) {
				$cells[ $letter ] = (string) $cell->is->t;
			} else {
				// No type attribute at all is how Excel writes a number.
				$cells[ $letter ] = (string) $cell->v;
			}
		}

		return $cells;
	}

	/** "A" => 1, "AA" => 27. */
	private static function column_number( string $letters ): int {
		$number = 0;

		foreach ( str_split( strtoupper( $letters ) ) as $char ) {
			$number = $number * 26 + ( ord( $char ) - 64 );
		}

		return $number;
	}

	/** 1 => "A", 27 => "AA". */
	private static function column_letter( int $number ): string {
		$letters = '';

		while ( $number > 0 ) {
			$mod     = ( $number - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$number  = intdiv( $number - $mod - 1, 26 );
		}

		return $letters;
	}

	/**
	 * @param array{rows: array<int, array<string, string>>, sku: string, quantity: string} $parsed
	 * @return array<int, array{code: string, quantity: int}>
	 */
	private static function lines( array $parsed ): array {
		$lines = array();

		foreach ( $parsed['rows'] as $cells ) {
			$code     = trim( $cells[ $parsed['sku'] ] ?? '' );
			$quantity = self::to_quantity( $cells[ $parsed['quantity'] ] ?? '' );

			if ( '' === $code || $quantity < 1 ) {
				continue;
			}

			$lines[] = array(
				'code'     => $code,
				'quantity' => $quantity,
			);
		}

		return $lines;
	}

	/**
	 * Excel may write 12, 12.0 or 12,0 for the same typed number.
	 */
	public static function to_quantity( string $raw ): int {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return 0;
		}

		return (int) round( (float) str_replace( ',', '.', $raw ) );
	}
}
