<?php

namespace BeltoftCommerce\Admin;

use BeltoftCommerce\Pricing\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Group price fields on the product data panel and on every variation.
 *
 * Rendered by looping the registry, so a new group produces its fields with
 * no code change. Architecture §2.4.
 *
 * Prices normally arrive from an external system over the REST API as
 * ordinary meta; these fields exist so a human can inspect and correct them.
 */
class PriceFields {

	public static function register(): void {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_from_post' ) );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_from_post' ), 10, 2 );
	}

	/**
	 * @return array<int, array{key: string, label: string, type: string}>
	 */
	public static function fields_for( string $slug ): array {
		$groups = Registry::groups();
		$label  = $groups[ $slug ]['label'] ?? $slug;

		return array(
			array(
				'key'   => Registry::regular_key( $slug ),
				/* translators: %s: pricing group label */
				'label' => sprintf( __( '%s regular price', 'beltoft-commerce' ), $label ),
				'type'  => 'price',
			),
			array(
				'key'   => Registry::sale_key( $slug ),
				/* translators: %s: pricing group label */
				'label' => sprintf( __( '%s sale price', 'beltoft-commerce' ), $label ),
				'type'  => 'price',
			),
			array(
				'key'   => Registry::sale_from_key( $slug ),
				/* translators: %s: pricing group label */
				'label' => sprintf( __( '%s sale dates', 'beltoft-commerce' ), $label ),
				'type'  => 'date',
			),
			array(
				'key'   => Registry::sale_to_key( $slug ),
				/* translators: %s: pricing group label */
				'label' => sprintf( __( '%s sale dates', 'beltoft-commerce' ), $label ),
				'type'  => 'date',
			),
		);
	}

	/**
	 * @return array<string, array<int, array{key: string, label: string, type: string}>>
	 */
	public static function all_fields(): array {
		$all = array();

		foreach ( array_keys( Registry::groups() ) as $slug ) {
			/*
			 * A group priced from WooCommerce's own fields has no price meta,
			 * so offering it a price box would invite somebody to fill in a
			 * number that is then never read. The till sells at the PVP.
			 */
			if ( Registry::inherits_prices( $slug ) ) {
				continue;
			}

			$all[ $slug ] = self::fields_for( $slug );
		}

		return $all;
	}

	public static function render_simple(): void {
		global $post;

		self::render_fields( (int) $post->ID, '' );
	}

	public static function render_variation( $loop, $variation_data, $variation ): void {
		self::render_fields( (int) $variation->ID, '_' . $loop );
	}

	private static function render_fields( int $post_id, string $suffix ): void {
		foreach ( self::all_fields() as $fields ) {
			$dates = array();

			foreach ( $fields as $field ) {
				if ( 'date' === $field['type'] ) {
					// Held back and rendered as a pair - see date_pair().
					$dates[] = $field;
					continue;
				}

				woocommerce_wp_text_input( array(
					'id'          => $field['key'] . $suffix,
					'name'        => $field['key'] . $suffix,
					'value'       => (string) get_post_meta( $post_id, $field['key'], true ),
					'label'       => $field['label'],
					'data_type'   => 'price',
					'class'       => 'short',
					'desc_tip'    => true,
					'description' => __( 'Stored value. Nothing is derived from the retail price.', 'beltoft-commerce' ),
				) );
			}

			if ( 2 === count( $dates ) ) {
				self::date_pair( $post_id, $suffix, $dates[0], $dates[1] );
			}
		}
	}

	/**
	 * The two sale dates, in one row, the way WooCommerce draws its own.
	 *
	 * WHY NOT TWO woocommerce_wp_text_input CALLS. The calendar is not ours:
	 * WooCommerce binds jQuery UI to `$('.sale_price_dates_fields').find('input')`
	 * (meta-boxes-product.js), so the class has to be on a wrapper for the
	 * picker to appear at all - which is why these were plain text boxes.
	 *
	 * And the wrapper has to hold BOTH inputs. On selecting a date WooCommerce
	 * reaches for the other end of the range with `.prev()` and sets it as the
	 * min or max. Given one input per wrapper that `.prev()` is the label, and
	 * calling a datepicker method on a label throws. Rendering the pair the way
	 * WooCommerce does means its script finds what it expects, and the range
	 * clamps itself: the end cannot precede the start.
	 *
	 * Both date fields carry the same label because they are one control with
	 * two ends. It used to be built by string-replacing "sale start" into "sale
	 * dates", which quietly stopped working the moment either string was
	 * translated - the field kept the English wording it was patched from.
	 *
	 * @param array{key: string, label: string, type: string} $from
	 * @param array{key: string, label: string, type: string} $to
	 */
	private static function date_pair( int $post_id, string $suffix, array $from, array $to ): void {
		$pattern = apply_filters(
			'woocommerce_date_input_html_pattern',
			'[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])'
		);

		?>
		<p class="form-field sale_price_dates_fields">
			<label for="<?php echo esc_attr( $from['key'] . $suffix ); ?>"><?php echo esc_html( $from['label'] ); ?></label>
			<?php foreach ( array( $from, $to ) as $i => $field ) : ?>
				<input
					type="text"
					class="short"
					name="<?php echo esc_attr( $field['key'] . $suffix ); ?>"
					id="<?php echo esc_attr( $field['key'] . $suffix ); ?>"
					value="<?php echo esc_attr( self::date_value( $post_id, $field['key'] ) ); ?>"
					placeholder="<?php echo esc_attr( 0 === $i ? _x( 'From&hellip;', 'placeholder', 'woocommerce' ) . ' YYYY-MM-DD' : _x( 'To&hellip;', 'placeholder', 'woocommerce' ) . ' YYYY-MM-DD' ); ?>"
					maxlength="10"
					pattern="<?php echo esc_attr( $pattern ); ?>"
				/>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * A stored sale date as the field shows it: the shop's own calendar day.
	 *
	 * The meta holds a timestamp. Showing it raw put "1792800000" in a field
	 * whose pattern only accepts YYYY-MM-DD, and strtotime() cannot read that
	 * back on the next save.
	 */
	private static function date_value( int $post_id, string $key ): string {
		$stored = get_post_meta( $post_id, $key, true );

		return is_numeric( $stored ) && (int) $stored > 0 ? wp_date( 'Y-m-d', (int) $stored ) : '';
	}

	/**
	 * A typed sale date as a timestamp, read in the shop's timezone.
	 *
	 * "From" starts at the beginning of its day and "to" runs to the end of
	 * its own, as WooCommerce's sale dates do. strtotime() alone gave 00:00
	 * UTC, so a sale ended at the start of its last day instead of the end.
	 *
	 * @return string The timestamp, or '' when the date cannot be read.
	 */
	private static function date_timestamp( string $raw, bool $end_of_day ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', trim( $raw ), wp_timezone() );

		if ( ! $day ) {
			return '';
		}

		return (string) ( $end_of_day ? $day->setTime( 23, 59, 59 ) : $day )->getTimestamp();
	}

	public static function save_from_post( $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before firing these actions.
		self::save_group_prices( (int) $post_id, wp_unslash( $_POST ) );
	}

	/**
	 * One variation of the many WooCommerce saves in a single request.
	 *
	 * Its fields carry the _<loop> suffix of its own row. Without $loop the
	 * save used to pick the lowest-numbered row present, so saving several
	 * variations at once wrote the first one's prices to all of them.
	 *
	 * @param int $variation_id
	 * @param int $loop
	 */
	public static function save_variation_from_post( $variation_id, $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before firing this action.
		self::save_group_prices( (int) $variation_id, wp_unslash( $_POST ), (int) $loop );
	}

	/**
	 * Only keys the registry derives are honoured, so a crafted POST cannot
	 * write arbitrary meta.
	 *
	 * An empty value DELETES the meta rather than storing an empty string,
	 * because fail-closed resolution depends on the key being absent.
	 *
	 * @param int|null $loop The variation row whose _<loop> fields to read;
	 *                       null for a simple product's unsuffixed fields.
	 */
	public static function save_group_prices( int $post_id, array $input, ?int $loop = null ): void {
		foreach ( self::all_fields() as $slug => $fields ) {
			foreach ( $fields as $field ) {
				$key        = $field['key'];
				$posted_key = null === $loop ? $key : $key . '_' . $loop;

				if ( ! array_key_exists( $posted_key, $input ) ) {
					continue;
				}

				$raw = (string) $input[ $posted_key ];

				if ( '' === trim( $raw ) ) {
					delete_post_meta( $post_id, $key );
					continue;
				}

				$value = 'price' === $field['type']
					? wc_format_decimal( $raw )
					: self::date_timestamp( $raw, Registry::sale_to_key( $slug ) === $key );

				// An unreadable date keeps what was there. Deleting an end
				// date would quietly make the sale run forever.
				if ( '' === $value ) {
					continue;
				}

				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
