<?php
declare(strict_types=1);

namespace om\ICal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use om\ICal\Value\DateTimeValue;

/**
 * Timestamps comparable with the start of a recurring item.
 *
 * UTC and zoned items use real instants. Dates and floating times use their wall-clock
 * time written as UTC, so they recur at the same local time and are never converted
 * to a system timezone.
 *
 * @internal
 */
final readonly class TimeSpace {
	private bool $local;
	private DateTimeZone $timezone;

	public function __construct(private DateTimeValue $start) {
		$this->local = $start->isDate() || $start->isFloating();
		$this->timezone = $start->timezone() ?? new DateTimeZone('UTC');
	}

	/**
	 * DTSTART as the base of the recurrence expansion.
	 */
	public function start(): DateTimeImmutable {
		return $this->start->base();
	}

	public function toBase(DateTimeValue $value): int {
		if ($this->local) {
			return $value->wallClockAsUtc()->getTimestamp();
		}
		return $value->toDateTime(null, $this->timezone)->getTimestamp();
	}

	public function fromBase(int $timestamp): DateTimeValue {
		return $this->start->withDateTime(new DateTimeImmutable('@' . $timestamp));
	}

	/**
	 * A window boundary: an instant, or the local time of the given moment for local items.
	 */
	public function window(DateTimeInterface $moment): int {
		return $this->local
			? (new DateTimeImmutable($moment->format('Y-m-d H:i:s'), new DateTimeZone('UTC')))->getTimestamp()
			: $moment->getTimestamp();
	}

	public function overlaps(Occurrence $occurrence, ?int $from, ?int $to): bool {
		$start = $this->toBase($occurrence->start);
		$end = $this->toBase($occurrence->end);
		if ($to !== null && $start >= $to) {
			return false;
		}
		return $from === null || $end > $from || ($start === $end && $start >= $from);
	}
}
