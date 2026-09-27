<?php
declare(strict_types=1);

namespace om;

use Generator;
use om\ICal\Calendar;
use om\ICal\Item;
use om\ICal\Parser\Parser;

/**
 * Entry point of the iCalendar (RFC 5545) API.
 *
 *     $calendar = ICal::parse($ics);
 *     foreach ($calendar->events() as $event) {
 *         foreach ($event->occurrencesBetween($from, $to) as $occurrence) { ... }
 *     }
 *
 * The permissive parser is used; see ICal::parser() for strict parsing, limits and warnings.
 */
final class ICal {

	/**
	 * The first calendar of the content.
	 *
	 * @throws ICal\Exception\SyntaxException when there is no VCALENDAR
	 */
	public static function parse(string $content): Calendar {
		return self::parser()->parse($content)->calendar();
	}

	/**
	 * @throws \RuntimeException when the file cannot be read
	 */
	public static function parseFile(string $file): Calendar {
		return self::parser()->parseFile($file)->calendar();
	}

	/**
	 * Events, tasks and journal entries of a large file one by one, see Parser::stream().
	 *
	 * @return Generator<int, Item>
	 */
	public static function stream(string $file): Generator {
		return self::parser()->stream($file);
	}

	public static function parser(): Parser {
		return new Parser();
	}
}
