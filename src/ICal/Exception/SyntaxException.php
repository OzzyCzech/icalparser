<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use InvalidArgumentException;

/**
 * Malformed iCalendar data: invalid content lines, unbalanced components, missing VCALENDAR.
 *
 * @phpstan-consistent-constructor
 */
class SyntaxException extends InvalidArgumentException implements ICalException {
	use ErrorDetails;
}
