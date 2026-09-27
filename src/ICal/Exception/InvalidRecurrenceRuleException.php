<?php
declare(strict_types=1);

namespace om\ICal\Exception;

/**
 * An invalid RRULE, e.g. unknown FREQ, INTERVAL=0 or BYHOUR=24.
 *
 * @phpstan-consistent-constructor
 */
class InvalidRecurrenceRuleException extends InvalidValueException {
}
