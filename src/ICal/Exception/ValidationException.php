<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use RuntimeException;

/**
 * A calendar violating RFC 5545 semantics, see Validator.
 *
 * @phpstan-consistent-constructor
 */
class ValidationException extends RuntimeException implements ICalException {
	use ErrorDetails;
}
