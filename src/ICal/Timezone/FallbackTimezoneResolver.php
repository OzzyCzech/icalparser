<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeZone;
use om\ICal\Component;

/**
 * Resolves every TZID to the configured timezone; use it as the last resolver.
 */
final readonly class FallbackTimezoneResolver implements TimezoneResolver {

	public function __construct(private DateTimeZone $timezone) {
	}

	public function resolve(string $tzid, Component $calendar): ResolvedTimezone {
		return new ResolvedTimezone($tzid, $this->timezone, TimezoneSource::Fallback);
	}
}
