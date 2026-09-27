<?php
declare(strict_types=1);

/**
 * Pathological input: huge lines, deep nesting, missing ENDs, invalid UTF-8,
 * many parameters and escapes, extreme rules. The parser must stay within its limits.
 */

use om\ICal;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Parser\ParseLimits;
use om\RRule\RecurrenceLimits;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

function wrap(string ...$lines): string {
	return "BEGIN:VCALENDAR\r\n" . implode("\r\n", $lines) . "\r\nEND:VCALENDAR\r\n";
}

function quick(callable $test): mixed {
	$started = hrtime(true);
	$result = $test();
	Assert::true((hrtime(true) - $started) < 3e9, 'within 3 seconds');
	return $result;
}

test('A line of 5 MB is refused by the default limit, allowed when configured', function () {
	$content = wrap('X-HUGE:' . str_repeat('a', 5 * 1024 * 1024));
	Assert::exception(fn() => quick(fn() => ICal::parse($content)), ResourceLimitException::class);
	Assert::same(5 * 1024 * 1024, strlen(quick(fn() => ICal::parser()->limits(new ParseLimits(maxLineLength: 10 * 1024 * 1024))->parse($content))->calendar()->property('X-HUGE')->value));
});

test('Deep nesting and thousands of unclosed components', function () {
	Assert::exception(fn() => quick(fn() => ICal::parse(wrap(...array_fill(0, 10000, 'BEGIN:X')))), ResourceLimitException::class);
	$result = quick(fn() => ICal::parser()->parse(wrap(...array_merge(...array_fill(0, 5000, ['BEGIN:VEVENT', 'UID:x'])))));
	Assert::count(5000, $result->calendar()->events());
});

test('Invalid UTF-8, control characters, many parameters and escapes', function () {
	$calendar = quick(fn() => ICal::parse(wrap(
		'BEGIN:VEVENT',
		"SUMMARY:\xC3\x28 invalid \xFF\xFE bytes \x00\x07",
		'DESCRIPTION:' . str_repeat('\\n\\,\;\\\\', 20000),
		'X-PARAMS;' . implode(';', array_map(fn($i) => "P$i=\"v:$i;,\"", range(1, 5000))) . ':value',
		'END:VEVENT',
	)));
	$event = $calendar->events()[0];
	Assert::contains('invalid', $event->summary());
	Assert::same(20000 * 4, strlen($event->description()));
	Assert::count(5000, $event->property('X-PARAMS')->parameters);
});

test('Extreme rules stay within the recurrence limits', function () {
	$rules = [
		'FREQ=SECONDLY',
		'FREQ=SECONDLY;BYMONTH=2;BYMONTHDAY=30',
		'FREQ=MINUTELY;BYSETPOS=-366;BYDAY=MO',
		'FREQ=YEARLY;BYWEEKNO=53;BYDAY=MO;BYYEARDAY=1;BYMONTHDAY=31;BYMONTH=2',
		'FREQ=YEARLY;INTERVAL=999999999;COUNT=999999999',
		'FREQ=DAILY;BYHOUR=' . implode(',', range(0, 23)) . ';BYMINUTE=' . implode(',', range(0, 59)) . ';BYSECOND=' . implode(',', range(0, 59)),
	];
	$parser = ICal::parser()->recurrenceLimits(new RecurrenceLimits(maxInstances: 10000, maxIterations: 500000));
	foreach ($rules as $rule) {
		$event = $parser->parse(wrap('BEGIN:VEVENT', 'UID:x', 'DTSTART:20260101T000000Z', "RRULE:$rule", 'END:VEVENT'))->calendar()->events()[0];
		quick(function () use ($event) {
			try {
				iterator_to_array($event->occurrences(5000));
			} catch (ResourceLimitException) {
				// a limit is a valid result
			}
			try {
				iterator_to_array($event->occurrencesBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2100-01-01')));
			} catch (ResourceLimitException) {
			}
		});
	}
});

test('A missing END everywhere', function () {
	$content = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nBEGIN:VALARM\r\nBEGIN:VEVENT\r\nUID:b\r\n";
	$result = quick(fn() => ICal::parser()->parse($content));
	Assert::same(['a', 'b'], array_map(fn($event) => $event->uid(), $result->calendar()->events()), 'BEGIN:VEVENT closes the open event');
	Assert::true(count($result->warnings()) >= 3);
});
