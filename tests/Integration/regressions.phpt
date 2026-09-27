<?php
declare(strict_types=1);

/**
 * Reported bugs, reproduced with the new and with the array based API.
 * The calendars are in tests/Fixtures/Regression.
 */

use om\ICal;
use om\IcalParser;
use om\ParserOptions;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('UTC');

const REGRESSION = __DIR__ . '/../Fixtures/Regression/';

/**
 * @return list<string>
 */
function legacy(string $file, string $format = 'Y-m-d H:i'): array {
	$parser = new IcalParser(new ParserOptions(now: new DateTimeImmutable('2026-01-01')));
	$parser->parseFile(REGRESSION . $file);
	return array_map(fn($event) => $event['DTSTART']->format($format), $parser->getEvents()->sorted()->getArrayCopy());
}

/**
 * @return list<string>
 */
function modern(string $file, string $format = 'Y-m-d H:i', int $limit = 10): array {
	$result = [];
	foreach (ICal::parseFile(REGRESSION . $file)->events() as $event) {
		foreach ($event->occurrences($limit) as $occurrence) {
			$result[] = $occurrence->start->format($format);
		}
	}
	sort($result);
	return $result;
}

test('#59 yearly events in January recur every year', function () {
	Assert::same(['2023-01-01', '2024-01-01', '2025-01-01', '2026-01-01'], modern('issue-59-yearly-january.ics', 'Y-m-d', 4));
	Assert::same(['2023-01-01', '2024-01-01', '2025-01-01', '2026-01-01'], array_slice(legacy('issue-59-yearly-january.ics', 'Y-m-d'), 0, 4));
});

test('#37 RDATE without RRULE adds dates without a yearly rule', function () {
	$expected = ['2016-04-15 21:00', '2016-12-16 21:00', '2016-12-23 21:00', '2016-12-30 21:00'];
	Assert::same($expected, modern('issue-37-rdate-without-rrule.ics'));
	Assert::same($expected, legacy('issue-37-rdate-without-rrule.ics'));
});

test('#84 recurrence expansion does not leak the timezone', function () {
	date_default_timezone_set('UTC');
	Assert::same(['2026-01-01 10:00', '2026-01-01 10:00', '2026-01-02 10:00', '2026-01-02 10:00'], modern('issue-84-timezone-leak.ics'));
	legacy('issue-84-timezone-leak.ics');
	Assert::same('UTC', date_default_timezone_get());
});

test('#82 UTC series with the Z timezone', function () {
	Assert::same(['01-05', '01-12', '01-19', '01-26'], modern('issue-82-utc-z.ics', 'm-d'));
	Assert::same(['01-05', '01-12', '01-19', '01-26'], legacy('issue-82-utc-z.ics', 'm-d'));
});

test('PR 88 padded numbers in BY rules', function () {
	Assert::same(['01-01', '01-02', '01-03'], modern('pr-88-numeric-filters.ics', 'm-d'));
	Assert::same(['01-01', '01-02', '01-03'], legacy('pr-88-numeric-filters.ics', 'm-d'));
});

test('#63 getEvents() returns a sortable list', function () {
	$parser = new IcalParser();
	$parser->parseFile(REGRESSION . 'issue-82-utc-z.ics');
	Assert::count(4, $parser->getEvents()->sorted());
});
