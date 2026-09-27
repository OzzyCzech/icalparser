<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;
use Stringable;

/**
 * PERIOD value (RFC 5545, section 3.3.9): start and end, or start and duration.
 */
final readonly class Period implements Stringable {

	public function __construct(
		public DateTimeValue $start,
		public DateTimeValue $end,
		public ?DateInterval $duration = null,
	) {
	}

	public function __toString(): string {
		return $this->start . '/' . ($this->duration !== null ? Duration::format($this->duration) : $this->end);
	}
}
