<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use om\ICal\Component;

/**
 * Maps a TZID to a PHP timezone.
 */
interface TimezoneResolver {
	/**
	 * @param Component $calendar the VCALENDAR component containing the TZID (and its VTIMEZONE definitions)
	 */
	public function resolve(string $tzid, Component $calendar): ?ResolvedTimezone;
}
