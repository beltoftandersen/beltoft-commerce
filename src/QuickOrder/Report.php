<?php

namespace BeltoftCommerce\QuickOrder;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one quick-order submission.
 *
 * Kept as its own object because both entry points and the template all need
 * the same answer, and because grouping rejections is a display decision that
 * would otherwise be re-solved in each template.
 */
class Report {

	public const UNKNOWN       = 'unknown';
	public const NOT_AVAILABLE = 'not_available';
	public const NO_PRICE      = 'no_price';
	public const OUT_OF_STOCK  = 'out_of_stock';
	public const REFUSED       = 'refused';

	/** @var array<int, array{code: string, product_id: int, quantity: int}> */
	private array $successes = array();

	/** @var array<int, array{code: string, reason: string, detail: ?string}> */
	private array $rejections = array();

	public function add_success( string $code, int $product_id, int $quantity ): void {
		$this->successes[] = array(
			'code'       => $code,
			'product_id' => $product_id,
			'quantity'   => $quantity,
		);
	}

	/**
	 * $detail overrides the customer-facing message for THIS line only — for
	 * example the exact reason WooCommerce's own cart refused it ("restante
	 * 9"), or a message borrowed from another reason to keep two different
	 * causes from reading as two different sentences on screen. The reason
	 * itself is always kept as given, for logs and tests.
	 */
	public function add_rejection( string $code, string $reason, ?string $detail = null ): void {
		$this->rejections[] = array(
			'code'   => $code,
			'reason' => $reason,
			'detail' => $detail,
		);
	}

	public function successes(): array {
		return $this->successes;
	}

	public function rejections(): array {
		return $this->rejections;
	}

	public function has_rejections(): bool {
		return ! empty( $this->rejections );
	}

	/**
	 * @return array<string, string[]> Reason => codes.
	 */
	public function rejections_by_reason(): array {
		$grouped = array();

		foreach ( $this->rejections as $rejection ) {
			$grouped[ $rejection['reason'] ][] = $rejection['code'];
		}

		return $grouped;
	}

	/**
	 * Grouped by what the customer actually reads, not by the internal
	 * reason code. Two lines rejected for different reasons that happen to
	 * share a customer-facing message (NOT_AVAILABLE and NO_PRICE, by
	 * design) must collapse into ONE notice — printing the same sentence
	 * twice over two different code lists would re-distinguish exactly what
	 * sharing the message was meant to hide.
	 *
	 * @return array<string, string[]> Customer-facing message => codes.
	 */
	public function rejections_by_message(): array {
		$grouped = array();

		foreach ( $this->rejections as $rejection ) {
			$message = ! empty( $rejection['detail'] ) ? $rejection['detail'] : self::message_for( $rejection['reason'] );

			$grouped[ $message ][] = $rejection['code'];
		}

		return $grouped;
	}

	/**
	 * NOT_AVAILABLE and NO_PRICE share a message on purpose.
	 *
	 * Distinguishing them would tell a customer that a product exists which
	 * they are not permitted to see, which is the B2B-only catalog leaking one
	 * SKU at a time. The distinction is kept in the code because the two mean
	 * different things to whoever is debugging.
	 */
	public static function message_for( string $reason ): string {
		switch ( $reason ) {
			case self::UNKNOWN:
				return __( 'Referência desconhecida.', 'beltoft-commerce' );
			case self::OUT_OF_STOCK:
				return __( 'Sem stock de momento.', 'beltoft-commerce' );
			case self::REFUSED:
				return __( 'Não foi possível adicionar esta linha.', 'beltoft-commerce' );
			case self::NOT_AVAILABLE:
			case self::NO_PRICE:
			default:
				return __( 'Não disponível na sua conta.', 'beltoft-commerce' );
		}
	}
}
