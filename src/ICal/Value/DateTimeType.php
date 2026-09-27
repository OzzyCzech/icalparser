<?php
declare(strict_types=1);

namespace om\ICal\Value;

/**
 * Kinds of DATE and DATE-TIME values (RFC 5545, sections 3.3.4 and 3.3.5).
 */
enum DateTimeType {
	/** DTSTART;VALUE=DATE:20261010 */
	case Date;
	/** DTSTART:20261010T100000, the same local time in any timezone */
	case Floating;
	/** DTSTART:20261010T100000Z */
	case Utc;
	/** DTSTART;TZID=Europe/Prague:20261010T100000 */
	case Zoned;
}
