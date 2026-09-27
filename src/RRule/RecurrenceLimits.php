<?php
declare(strict_types=1);

namespace om\RRule;

/**
 * Limits of recurrence expansion; exceeding one throws ResourceLimitException.
 */
final readonly class RecurrenceLimits {

	/**
	 * @param int $maxInstances occurrences returned for one recurring component
	 * @param int $maxIterations FREQ periods examined for one rule
	 */
	public function __construct(
		public int $maxInstances = 100_000,
		public int $maxIterations = 1_000_000,
	) {
	}
}
