<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use om\ICal\Value\DateTimeValue;

/**
 * VTODO (RFC 5545, section 3.6.2).
 */
final class Todo extends Item {

	/**
	 * DUE, or DTSTART + DURATION.
	 */
	public function due(): ?DateTimeValue {
		return $this->date('DUE') ?? ($this->has('DURATION') ? parent::end() : null);
	}

	public function end(): ?DateTimeValue {
		return $this->due() ?? $this->start();
	}

	public function duration(): DateInterval {
		$start = $this->start();
		$due = $this->date('DUE');
		if ($start !== null && $due !== null) {
			return self::between($start, $due);
		}
		$duration = $this->property('DURATION');
		return ($duration === null ? null : $this->calendar->values()->duration($duration)) ?? new DateInterval('PT0S');
	}

	public function completed(): ?DateTimeValue {
		return $this->date('COMPLETED');
	}

	public function percentComplete(): ?int {
		return $this->integer('PERCENT-COMPLETE');
	}

	public function isCompleted(): bool {
		return $this->status() === 'COMPLETED' || $this->has('COMPLETED');
	}
}
