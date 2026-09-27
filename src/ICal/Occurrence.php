<?php
declare(strict_types=1);

namespace om\ICal;

use DateTimeImmutable;
use DateTimeZone;
use om\ICal\Value\DateTimeValue;

/**
 * One instance of an event, task or journal entry.
 *
 * For a modified instance, $item is the override (the component with the RECURRENCE-ID)
 * and $master the recurring item; otherwise both are the same.
 */
final readonly class Occurrence {
	/**
	 * @param ?DateTimeValue $recurrenceId original start of a recurring instance, null for a single item
	 */
	public function __construct(
		public DateTimeValue $start,
		public DateTimeValue $end,
		public Item $item,
		public Item $master,
		public ?DateTimeValue $recurrenceId = null,
	) {
	}

	public function isRecurring(): bool {
		return $this->recurrenceId !== null;
	}

	/** True when the instance comes from an override (a component with a RECURRENCE-ID). */
	public function isModified(): bool {
		return $this->item !== $this->master;
	}

	public function isCancelled(): bool {
		return $this->item->isCancelled();
	}

	public function isAllDay(): bool {
		return $this->start->isDate();
	}

	public function summary(): ?string {
		return $this->item->summary();
	}

	/**
	 * Start as an instant; dates and floating times use the given timezone or the
	 * floating timezone of the calendar.
	 */
	public function startTime(?DateTimeZone $timezone = null): DateTimeImmutable {
		return $this->start->toDateTime($timezone, $this->item->calendar()->floatingTimezone());
	}

	public function endTime(?DateTimeZone $timezone = null): DateTimeImmutable {
		return $this->end->toDateTime($timezone, $this->item->calendar()->floatingTimezone());
	}
}
