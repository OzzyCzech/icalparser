<?php
declare(strict_types=1);

namespace om;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Configuration of {@see IcalParser}.
 *
 * @deprecated 5.0, removed in 5.5 at the latest; configure om\ICal::parser() instead, see UPGRADING.md
 */
class ParserOptions {
	public function __construct(
		/**
		 * Interval used to cap recurring events that have no defined end
		 * (RRULE without UNTIL or COUNT). This prevents infinite expansion
		 * when parsing such rules.
		 *
		 * - Format: DateInterval (e.g. new DateInterval('P3Y') for 3 years).
		 * - If set to null, recurring events will be limited by the current date.
		 */
		public ?DateInterval $untilInterval = new DateInterval('P3Y'),

		/**
		 * Skip old occurrences of recurring events that lack UNTIL/COUNT.
		 *
		 * - null: keep all occurrences since DTSTART.
		 * - DateInterval: occurrences older than (now - interval) are skipped,
		 *   so a daily event starting in 1970 does not produce decades of history.
		 *
		 * Example: DTSTART=1970-01-01, today=2026-01-30, shiftEventDates=P1Y -> the first
		 * returned occurrence is on or after 2025-01-30.
		 */
		public ?DateInterval $shiftEventDates = null,

		/**
		 * Mapping of Windows timezones to IANA timezones.
		 *
		 * @var array<string, string>|null
		 */
		public ?array $windowsTimezones = null,

		/**
		 * The moment used as "now" for the limits above; null means the current time.
		 * Pass a fixed date to get reproducible results (for example in tests).
		 */
		public ?DateTimeInterface $now = null,

		/**
		 * Maximal number of occurrences expanded for a single event.
		 * Longer series are truncated (or rejected in strict mode).
		 */
		public int $maxOccurrences = 100000,

		/**
		 * When true, invalid recurrence rules and series exceeding maxOccurrences throw
		 * an exception. When false (default), an invalid RRULE is ignored, so the event
		 * keeps its DTSTART (plus RDATE), and a too long series is truncated.
		 */
		public bool $strict = false,
	) {
		if ($maxOccurrences < 1) {
			throw new InvalidArgumentException('maxOccurrences must be positive.');
		}
		$this->windowsTimezones ??= require __DIR__ . '/WindowsTimezones.php';
	}

	public function now(): DateTimeImmutable {
		return $this->now === null ? new DateTimeImmutable() : DateTimeImmutable::createFromInterface($this->now);
	}
}
