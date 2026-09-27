<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use RuntimeException;

/**
 * A timezone that cannot be resolved, or a floating time converted without a timezone.
 *
 * @phpstan-consistent-constructor
 */
class TimezoneResolutionException extends RuntimeException implements ICalException {
	use ErrorDetails;
}
