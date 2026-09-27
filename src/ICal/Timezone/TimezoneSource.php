<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

/**
 * Where a timezone came from.
 */
enum TimezoneSource {
	/** a VTIMEZONE definition of the calendar */
	case VTimezone;
	/** an IANA timezone name */
	case Iana;
	/** a Windows name or a prefixed IANA name (e.g. /mozilla.org/20070129_1/Europe/Paris) */
	case Alias;
	/** the configured fallback timezone */
	case Fallback;
}
