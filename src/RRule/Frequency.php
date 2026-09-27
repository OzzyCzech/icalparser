<?php
declare(strict_types=1);

namespace om\RRule;

/**
 * FREQ rule part (RFC 5545, section 3.3.10).
 */
enum Frequency: string {
	case Yearly = 'YEARLY';
	case Monthly = 'MONTHLY';
	case Weekly = 'WEEKLY';
	case Daily = 'DAILY';
	case Hourly = 'HOURLY';
	case Minutely = 'MINUTELY';
	case Secondly = 'SECONDLY';

	/**
	 * True when this frequency is coarser than the given one (e.g. YEARLY is coarser than DAILY).
	 */
	public function isCoarserThan(self $other): bool {
		return $this->rank() < $other->rank();
	}

	private function rank(): int {
		return match ($this) {
			self::Yearly => 0,
			self::Monthly => 1,
			self::Weekly => 2,
			self::Daily => 3,
			self::Hourly => 4,
			self::Minutely => 5,
			self::Secondly => 6,
		};
	}
}
