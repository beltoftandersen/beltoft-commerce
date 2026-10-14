<?php

namespace BeltoftCommerce\Vat;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of a single VIES consultation.
 *
 * `status` is one of valid | invalid | unreachable. Anything that is not an
 * explicit valid or invalid answer is unreachable — never invalid.
 */
class ViesResult {

	public string $status;
	public string $request_identifier;
	public string $registered_name;
	public int $checked_at;
	public string $error;

	public function __construct(
		string $status,
		string $request_identifier = '',
		string $registered_name = '',
		string $error = '',
		int $checked_at = 0
	) {
		$this->status             = $status;
		$this->request_identifier = $request_identifier;
		$this->registered_name    = $registered_name;
		$this->error              = $error;
		/*
		 * The moment the ANSWER was obtained, not the moment it was read.
		 *
		 * Answers are cached for a day. Stamping now on a cache hit would pair
		 * today's date with a consultation number issued up to 24 hours earlier,
		 * and the date and the number would stop describing the same
		 * consultation - which is the one thing the audit trail is for.
		 */
		$this->checked_at         = $checked_at > 0 ? $checked_at : time();
	}

	public function is_valid(): bool {
		return 'valid' === $this->status;
	}

	public function is_unreachable(): bool {
		return 'unreachable' === $this->status;
	}
}
