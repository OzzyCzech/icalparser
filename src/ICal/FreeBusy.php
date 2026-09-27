<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Period;

/**
 * VFREEBUSY (RFC 5545, section 3.6.4).
 */
final class FreeBusy extends Item {
	public function end(): ?DateTimeValue {
		return $this->date('DTEND');
	}

	public function duration(): DateInterval {
		$start = $this->start();
		$end = $this->end();
		return $start !== null && $end !== null ? self::between($start, $end) : new DateInterval('PT0S');
	}

	/**
	 * FREEBUSY periods with their type (FBTYPE: BUSY by default, FREE, BUSY-UNAVAILABLE, BUSY-TENTATIVE).
	 *
	 * @return list<array{Period, string}>
	 */
	public function periods(): array {
		$result = [];
		foreach ($this->properties('FREEBUSY') as $property) {
			$type = strtoupper($property->parameter('FBTYPE') ?? 'BUSY');
			foreach ($this->calendar->values()->periods($property) as $period) {
				$result[] = [$period, $type];
			}
		}
		return $result;
	}
}
