<?php
declare(strict_types=1);

namespace om\ICal\Parser;

use om\ICal\Calendar;
use om\ICal\Exception\SyntaxException;

final readonly class ParseResult {
	/**
	 * @param list<Calendar> $calendars
	 * @param list<ParseWarning> $warnings
	 */
	public function __construct(
		public array $calendars,
		public array $warnings = [],
	) {
	}

	/**
	 * The first calendar.
	 *
	 * @throws SyntaxException when the input contains no VCALENDAR
	 */
	public function calendar(): Calendar {
		return $this->calendars[0] ?? throw SyntaxException::create('syntax.no-calendar', 'The input contains no VCALENDAR component.');
	}

	/**
	 * @return list<Calendar>
	 */
	public function calendars(): array {
		return $this->calendars;
	}

	/**
	 * @return list<ParseWarning>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	public function hasWarnings(): bool {
		return $this->warnings !== [];
	}
}
