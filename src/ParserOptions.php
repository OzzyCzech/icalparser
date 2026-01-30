<?php

namespace om;

use DateInterval;
class ParserOptions {

	public function __construct(
		/**
		 * Interval used to cap recurring events that have no defined end
		 * (RRULE without UNTIL or COUNT). This prevents infinite expansion
		 * when parsing such rules.
		 *
		 * - Format: DateInterval string (e.g. 'P3Y' for 3 years).
		 * - If set to null, recurring events will be limited by the current date.
		 *
		 * @var ?DateInterval
		 */
		public ?DateInterval $untilInterval = new DateInterval('P3Y'),

		/**
		 * Limit DTSTART/DTEND for very-old recurring events that lack UNTIL/COUNT.
		 *
		 * - null: do not modify DTSTART/DTEND; use original values.
		 * - DateInterval: if DTSTART/DTEND are older than (now - interval),
		 *   shift them forward to (now - interval) so they are not far in the past.
		 *
		 * Example:
		 * DTSTART=1970-01-01, today=2026-01-30, shiftEventsDate=P1Y -> DTSTART becomes 2025-01-01.
		 *
		 * @var ?DateInterval
		 */
		public ?DateInterval $shiftEventDates = null,

		/**
		 * Mapping of Windows timezones to IANA timezones.
		 *
		 * @var array|null
		 */
		public ?array $windowsTimezones = null,
	) {
		$this->windowsTimezones ??= require __DIR__ . '/WindowsTimezones.php';
	}
}