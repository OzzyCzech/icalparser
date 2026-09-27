<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use om\ICal\Value\DateTimeValue;

/**
 * VEVENT (RFC 5545, section 3.6.1).
 */
final class Event extends Item {
	/**
	 * DTEND, DTSTART + DURATION, or one day after an all-day DTSTART (RFC 5545, section 3.6.1).
	 */
	public function end(): ?DateTimeValue {
		return $this->date('DTEND') ?? parent::end();
	}

	/**
	 * From DTEND or DURATION; one day for all-day events, zero otherwise.
	 */
	public function duration(): DateInterval {
		$start = $this->start();
		$end = $this->date('DTEND');
		if ($start !== null && $end !== null) {
			return self::between($start, $end);
		}
		$duration = $this->property('DURATION');
		if ($duration !== null && ($interval = $this->calendar->values()->duration($duration)) !== null) {
			return $interval;
		}
		return new DateInterval($start?->isDate() ? 'P1D' : 'PT0S');
	}

	/**
	 * TRANSP: OPAQUE (busy, default) or TRANSPARENT (free).
	 */
	public function transparency(): string {
		return strtoupper($this->text('TRANSP') ?? 'OPAQUE');
	}

	/**
	 * @return array{float, float}|null latitude and longitude
	 */
	public function geo(): ?array {
		$property = $this->property('GEO');
		return $property === null ? null : $this->calendar->values()->geo($property);
	}
}
