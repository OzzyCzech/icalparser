<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;

/**
 * VJOURNAL (RFC 5545, section 3.6.3). A journal entry has no duration.
 */
final class Journal extends Item {
	public function duration(): DateInterval {
		return new DateInterval('PT0S');
	}
}
