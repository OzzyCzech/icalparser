<?php
declare(strict_types=1);

namespace om\RRule;

/**
 * SKIP rule part (RFC 7529, section 4.1): handling of instances on an invalid date,
 * e.g. February 30 or February 29 in a common year.
 */
enum Skip: string {
	/** The instance is dropped (the RFC 5545 behaviour). */
	case Omit = 'OMIT';
	/** The instance moves to the previous valid day, the last day of the month. */
	case Backward = 'BACKWARD';
	/** The instance moves to the next valid day, the first day of the next month. */
	case Forward = 'FORWARD';
}
