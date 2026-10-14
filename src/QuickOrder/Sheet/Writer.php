<?php

namespace BeltoftCommerce\QuickOrder\Sheet;

use BeltoftCommerce\Catalog\CatalogAudience;
use BeltoftCommerce\Pricing\PriceResolver;
use BeltoftCommerce\Pricing\PricingContext;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the order sheet as a real .xlsx, without a library.
 *
 * An .xlsx is a zip of XML parts, which is why this is a few hundred lines
 * rather than a dependency. CSV was rejected because a Portuguese Excel expects
 * semicolons, needs a BOM for "Ácido", and writes decimal commas — three ways
 * for an order to arrive wrong with nothing visible to the customer.
 *
 * CELLS ARE WRITTEN AS INLINE STRINGS, which removes the need for a
 * sharedStrings part entirely. The Reader cannot make the same simplification:
 * see its docblock.
 *
 * PRICES ARE TEXT, NOT NUMERIC CELLS, and that is a decision rather than an
 * oversight. Numeric cells would need a styles.xml part and a number format,
 * and nothing in this sheet is meant to be summed — the one column a buyer
 * fills is Quantity, and the reader parses that tolerantly. They are written
 * with the store's own decimal separator so they read correctly to the person
 * looking at them.
 */
class Writer {

	public const COLUMNS = array( 'SKU', 'Produto', 'Variante', 'Stock', 'O seu preço', 'PVP', 'Quantidade' );

	/**
	 * Every orderable SKU this buyer can actually buy, priced for their group.
	 */
	public static function rows( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$group   = PricingContext::for_user( $user_id );
		$rows    = array();

		$product_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $product_ids as $product_id ) {
			if ( ! CatalogAudience::can_purchase( $user_id, $product_id ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );

					/*
					 * has_price() as well as is_purchasable(), because
					 * can_purchase() passes a variable parent when ANY child is
					 * priced for this group. Without the per-child check a
					 * variation with no group price lands in the sheet with an
					 * empty price column — a line the buyer cannot order.
					 */
					if ( $child instanceof \WC_Product && $child->is_purchasable() && PriceResolver::has_price( $child, $group ) ) {
						$rows[] = self::row( $child, $group, $product->get_name() );
					}
				}

				continue;
			}

			if ( $product->is_purchasable() && PriceResolver::has_price( $product, $group ) ) {
				$rows[] = self::row( $product, $group, $product->get_name() );
			}
		}

		return $rows;
	}

	private static function row( \WC_Product $product, string $group, string $parent_name ): array {
		$variant = $product->is_type( 'variation' )
			? wp_strip_all_tags( wc_get_formatted_variation( $product, true, false ) )
			: '';

		return array(
			'sku'     => (string) $product->get_sku(),
			'name'    => $parent_name,
			'variant' => $variant,
			'stock'   => $product->is_in_stock()
				? __( 'In stock', 'beltoft-commerce' )
				: __( 'Out of stock', 'beltoft-commerce' ),
			'price'   => wc_format_localized_price( (string) PriceResolver::resolve( $product, $group ) ),
			'rrp'     => wc_format_localized_price( (string) $product->get_regular_price( 'edit' ) ),
		);
	}

	/**
	 * @param array<int, array<string, string>> $rows
	 * @return string Raw .xlsx bytes.
	 */
	public static function build( array $rows ): string {
		$file = tempnam( sys_get_temp_dir(), 'beltoft-sheet' );

		$zip = new \ZipArchive();
		$zip->open( $file, \ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml', self::content_types() );
		$zip->addFromString( '_rels/.rels', self::root_rels() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet( $rows ) );

		$zip->close();

		$bytes = (string) file_get_contents( $file );
		unlink( $file );

		return $bytes;
	}

	private static function content_types(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';
	}

	private static function root_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private static function workbook(): string {
		// The frozen header row is declared on the sheet, not here; this part
		// only names the sheet and points at it.
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Encomenda" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private static function workbook_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';
	}

	private static function sheet( array $rows ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetViews><sheetView workbookViewId="0">'
			. '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
			. '</sheetView></sheetViews><sheetData>';

		$xml .= self::row_xml( 1, self::COLUMNS );

		$number = 1;

		foreach ( $rows as $row ) {
			$number++;
			$xml .= self::row_xml( $number, array(
				$row['sku'],
				$row['name'],
				$row['variant'],
				$row['stock'],
				$row['price'],
				$row['rrp'],
				'',
			) );
		}

		return $xml . '</sheetData></worksheet>';
	}

	/**
	 * @param string[] $values
	 */
	private static function row_xml( int $number, array $values ): string {
		$xml    = '<row r="' . $number . '">';
		$letter = 'A';

		foreach ( $values as $value ) {
			$xml .= '<c r="' . $letter . $number . '" t="inlineStr"><is><t xml:space="preserve">'
				. htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' )
				. '</t></is></c>';
			$letter++;
		}

		return $xml . '</row>';
	}
}
