<?php
declare(strict_types=1);

namespace om\ICal\Parser;

use Stringable;

/**
 * A problem the permissive parser repaired or skipped.
 */
final readonly class ParseWarning implements Stringable {
	/**
	 * @param string $code stable identifier, e.g. "syntax.invalid-line"
	 */
	public function __construct(
		public string $code,
		public string $message,
		public ?int $line = null,
		public ?string $property = null,
	) {
	}

	public function __toString(): string {
		return ($this->line !== null ? "line $this->line: " : '') . $this->message . " [$this->code]";
	}
}
