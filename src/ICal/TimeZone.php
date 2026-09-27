<?php
declare(strict_types=1);

namespace om\ICal;

use om\ICal\Timezone\ResolvedTimezone;
use om\ICal\Timezone\VTimezoneResolver;

/**
 * VTIMEZONE (RFC 5545, section 3.6.5).
 */
final class TimeZone {

	/**
	 * @internal use Calendar::timezones()
	 */
	public function __construct(
		public readonly Component $component,
		private readonly Calendar $calendar,
	) {
	}

	public function tzid(): ?string {
		return $this->component->property('TZID')?->value;
	}

	/**
	 * The PHP timezone used for this TZID (see TimezoneResolver).
	 */
	public function resolve(): ?ResolvedTimezone {
		$tzid = $this->tzid();
		return $tzid === null ? null : $this->calendar->values()->timezone($tzid);
	}

	/**
	 * STANDARD and DAYLIGHT observances.
	 *
	 * @return list<array{start: string, offsetFrom: string, offsetTo: string, rrule: ?string, rdates: list<string>}>
	 */
	public function observances(): array {
		return VTimezoneResolver::observances($this->component);
	}
}
