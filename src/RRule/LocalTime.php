<?php
declare(strict_types=1);

namespace om\RRule;

use DateTime;
use DateTimeZone;

/**
 * Converts a local (wall-clock) time to an instant as RFC 5545, section 3.3.5 requires:
 * an ambiguous time (DST fall-back) is the first occurrence, a nonexistent time (DST gap)
 * uses the UTC offset before the gap. PHP itself is not consistent in these cases.
 */
final class LocalTime {
	/**
	 * @param int $local the wall-clock time as seconds since 1970-01-01 00:00 (as if it were UTC)
	 */
	public static function timestamp(DateTimeZone $timezone, int $local): int {
		static $probe;
		$probe ??= new DateTime('@0');
		$offsetAt = static fn(int $timestamp): int => $timezone->getOffset($probe->setTimestamp($timestamp));

		// there is at most one transition within two days
		$before = $offsetAt($local - 2 * 86400);
		$after = $offsetAt($local + 2 * 86400);
		if ($before === $after && $offsetAt($local - $before) === $before) {
			return $local - $before;
		}

		$valid = [];
		foreach ([$before, $after] as $offset) {
			$timestamp = $local - $offset;
			if ($offsetAt($timestamp) === $offset) {
				$valid[] = $timestamp;
			}
		}
		if ($valid !== []) {
			return min($valid); // the first of repeated times
		}
		// a nonexistent time is interpreted with the offset before the gap
		return $local - $before;
	}
}
