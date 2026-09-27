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
		$probe = new DateTime('@0');
		$offsetAt = static fn(int $timestamp): int => $timezone->getOffset($probe->setTimestamp($timestamp));

		$before = $offsetAt($local - 2 * 86400);
		$after = $offsetAt($local + 2 * 86400);
		$offsets = [$before => true, $after => true];
		foreach ($timezone->getTransitions($local - 2 * 86400, $local + 2 * 86400) ?: [] as $transition) {
			$offsets[$transition['offset']] = true;
		}

		$valid = [];
		foreach (array_keys($offsets) as $offset) {
			$timestamp = $local - $offset;
			if ($offsetAt($timestamp) === $offset) {
				$valid[] = $timestamp;
			}
		}
		if ($valid !== []) {
			return min($valid); // the first of repeated times
		}
		// a nonexistent time: interpreted with the offset before the gap (there is at most
		// one transition within two days)
		return $local - $before;
	}
}
