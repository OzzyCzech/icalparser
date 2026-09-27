<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeZone;

final readonly class ResolvedTimezone {

	public function __construct(
		public string $tzid,
		public DateTimeZone $timezone,
		public TimezoneSource $source,
	) {
	}
}
