<?php
declare(strict_types=1);

namespace om\ICal\Parser;

enum ParserMode {
	/**
	 * Any syntax error, invalid value, invalid RRULE or unresolved TZID throws an exception.
	 * For validation, tests and debugging.
	 */
	case Strict;

	/**
	 * Recoverable problems are repaired and reported as warnings. For real-world feeds.
	 */
	case Permissive;
}
