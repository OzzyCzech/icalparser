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

test('#90 SKIP of RFC 7529 moves instances on invalid days', function () {
	$expected = [
		'2012-02-29', '2013-03-01', '2014-03-01', '2015-03-01', '2016-02-29', '2017-03-01', // leap day, SKIP=FORWARD
		'2026-01-31', '2026-01-31', '2026-01-31', '2026-02-28', '2026-03-01', '2026-03-31', '2026-03-31', '2026-03-31', // monthly
		'2026-04-30', '2026-05-01', '2026-05-31', '2026-07-31',
		'2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29', // leap day, SKIP=BACKWARD
	];
	Assert::same($expected, modern('issue-90-rscale-skip.ics', 'Y-m-d'));
	Assert::same($expected, legacy('issue-90-rscale-skip.ics', 'Y-m-d'));
});

test('#90 rules of other calendar systems are not expanded as Gregorian', function () {
	$expected = ['2013-02-10', '2014-02-08', '2015-02-27', '2016-02-17'];
	Assert::same($expected, modern('issue-90-unsupported-rscale.ics', 'Y-m-d'));
	Assert::same($expected, legacy('issue-90-unsupported-rscale.ics', 'Y-m-d'));
	$warnings = array_map(fn($warning) => $warning->code . '@' . $warning->line, ICal::parser()->parseFile(REGRESSION . 'issue-90-unsupported-rscale.ics')->warnings());
	Assert::same(['recurrence.unsupported-rscale@8', 'recurrence.unsupported-rscale@15'], $warnings);
});

test('#63 getEvents() returns a sortable list', function () {
	$parser = new IcalParser();
	$parser->parseFile(REGRESSION . 'issue-82-utc-z.ics');
	Assert::count(4, $parser->getEvents()->sorted());
});

/**
 * @return list<string>
 */
function series(string $lines, string $from, string $to, string $format = 'm-d H:i', ?int $limit = null, ?string $limitFrom = null): array {
	$calendar = ICal::parse("BEGIN:VCALENDAR\r\n" . $lines . "\r\nEND:VCALENDAR\r\n");
	$event = $calendar->events()[0];
	$occurrences = $limit === null
		? $event->occurrencesBetween(new DateTimeImmutable($from), new DateTimeImmutable($to))
		: $event->occurrences($limit, new DateTimeImmutable((string) $limitFrom));
	return array_map(fn($o) => $o->start->format($format) . ($o->isModified() ? ' *' : ''), iterator_to_array($occurrences, false));
}

test('Review: the instance limit counts occurrences of the window, not since DTSTART', function () {
	$event = "BEGIN:VEVENT\r\nUID:h\r\nDTSTART:20000101T100000Z\r\nRRULE:FREQ=HOURLY\r\nEND:VEVENT";
	Assert::same(['2026-01-01 00:00', '2026-01-01 01:00', '2026-01-01 02:00'], series($event, '2026-01-01T00:00:00Z', '2026-01-01T03:00:00Z', 'Y-m-d H:i'));
	Assert::same(['2026-01-01 00:00', '2026-01-01 01:00'], series($event, '', '', 'Y-m-d H:i', 2, '2026-01-01T00:00:00Z'));
});

test('Review: THISANDFUTURE keeps the local time over DST, without DTSTART it keeps the instance', function () {
	$dst = "BEGIN:VEVENT\r\nUID:w\r\nDTSTART;TZID=Europe/Prague:20260321T100000\r\nDTEND;TZID=Europe/Prague:20260321T110000\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:w\r\nRECURRENCE-ID;TZID=Europe/Prague;RANGE=THISANDFUTURE:20260328T100000\r\nDTSTART;TZID=Europe/Prague:20260329T100000\r\nDTEND;TZID=Europe/Prague:20260329T110000\r\nEND:VEVENT";
	Assert::same(['03-21 10:00', '03-29 10:00 *', '04-05 10:00 *', '04-12 10:00 *'], series($dst, '2026-03-01', '2026-05-01'));

	$noStart = "BEGIN:VEVENT\r\nUID:d\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=5\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:d\r\nRECURRENCE-ID;RANGE=THISANDFUTURE:20260103T100000Z\r\nSUMMARY:Changed\r\nEND:VEVENT";
	Assert::same(['01-01 10:00', '01-02 10:00', '01-03 10:00 *', '01-04 10:00 *', '01-05 10:00 *'], series($noStart, '2026-01-01', '2026-02-01'));
	$calendar = ICal::parse("BEGIN:VCALENDAR\r\n$noStart\r\nEND:VCALENDAR");
	$last = iterator_to_array($calendar->events()[0]->occurrences(10), false)[4];
	Assert::same(['Changed', '11:00'], [$last->summary(), $last->end->format('H:i')], 'the length of the recurring event is kept');
});

test('Review: instances moved into the window by THISANDFUTURE and the order of merged overrides', function () {
	$base = "BEGIN:VEVENT\r\nUID:r\r\nDTSTART:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=5\r\nEND:VEVENT\r\n";
	$earlier = $base . "BEGIN:VEVENT\r\nUID:r\r\nRECURRENCE-ID;RANGE=THISANDFUTURE:20260103T100000Z\r\nDTSTART:20260102T120000Z\r\nEND:VEVENT";
	Assert::same(['01-02 10:00', '01-02 12:00 *'], series($earlier, '2026-01-02T00:00:00Z', '2026-01-03T00:00:00Z'));

	$mixed = $base . "BEGIN:VEVENT\r\nUID:r\r\nRECURRENCE-ID;RANGE=THISANDFUTURE:20260103T100000Z\r\nDTSTART:20260103T150000Z\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:r\r\nRECURRENCE-ID:20260105T100000Z\r\nDTSTART:20260104T120000Z\r\nEND:VEVENT";
	Assert::same(['01-01 10:00', '01-02 10:00', '01-03 15:00 *', '01-04 12:00 *', '01-04 15:00 *'], series($mixed, '2026-01-01', '2026-02-01'));
	Assert::same(['01-01 10:00', '01-02 10:00', '01-03 15:00 *', '01-04 12:00 *'], series($mixed, '', '', 'm-d H:i', 4, '2026-01-01'));
});

test('Review: floating values resolve repeated local times to the first occurrence', function () {
	$prague = new DateTimeZone('Europe/Prague');
	Assert::same('+02:00', ICal\Value\DateTimeValue::parse('20261025T023000')->toDateTime($prague)->format('P'));
	$exdate = "BEGIN:VEVENT\r\nUID:x\r\nDTSTART;TZID=Europe/Prague:20261024T023000\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEXDATE:20261025T023000\r\nEND:VEVENT";
	Assert::same(['10-24 02:30', '10-26 02:30'], series($exdate, '2026-10-01', '2026-11-01'));
	$event = ICal::parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:d\r\nDTSTART;TZID=Europe/Prague:20261025T020000\r\nDTEND;TZID=Europe/Prague:20261025T023000\r\nEND:VEVENT\r\nEND:VCALENDAR")->events()[0];
	Assert::same(30, $event->duration()->i);
	Assert::same(0, $event->duration()->h);
	Assert::true(ICal\Value\DateTimeValue::fromDateTime(new DateTimeImmutable('now', new DateTimeZone('Etc/UTC')))->isUtc());
});

test('Review: values, parameters and escaped TZIDs', function () {
	$calendar = ICal::parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20260101T100000Z\r\nDURATION:P9999999999999999W\r\nCATEGORIES:a\\\\,b\r\nEND:VEVENT\r\nEND:VCALENDAR");
	Assert::same('PT0S', ICal\Value\Duration::format($calendar->events()[0]->duration()), 'an oversized DURATION is ignored');
	Assert::same(['a\\', 'b'], $calendar->events()[0]->categories());

	$injected = ICal\Property::create('ATTENDEE', 'mailto:a@example.org', ['CN' => "Eve\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:injected"]);
	$ics = ICal\Calendar::create()->withComponent(new ICal\Component('VEVENT', [ICal\Property::create('UID', '1'), $injected, ICal\Property::create('SUMMARY', "a\r\nBEGIN:VEVENT")]))->serialize();
	$parsed = ICal::parse($ics);
	Assert::count(1, $parsed->events(), 'no content line can be injected');
	Assert::same("Eve\nEND:VEVENT\nBEGIN:VEVENT\nUID:injected", $parsed->events()[0]->attendees()[0]->name(), 'RFC 6868 keeps the value');
	Assert::same('The "Boss" x', ICal\Parameters::parse((string) ICal\Parameters::from(['CN' => 'The "Boss" x']))->get('CN'));
	Assert::exception(fn() => ICal\Property::create('BAD:NAME', 'x'), InvalidArgumentException::class);
	Assert::exception(fn() => ICal\Parameters::from(['BAD NAME' => 'x']), InvalidArgumentException::class);

	$escaped = ICal::parse(implode("\r\n", [
		'BEGIN:VCALENDAR', 'BEGIN:VTIMEZONE', 'TZID:My Zone\, Custom',
		'BEGIN:STANDARD', 'DTSTART:16010101T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10', 'END:STANDARD',
		'BEGIN:DAYLIGHT', 'DTSTART:16010101T020000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3', 'END:DAYLIGHT',
		'END:VTIMEZONE', 'BEGIN:VEVENT', 'UID:x', 'DTSTAMP:20260101T000000Z', 'DTSTART;TZID="My Zone, Custom":20260101T100000', 'END:VEVENT', 'END:VCALENDAR',
	]));
	Assert::true($escaped->events()[0]->start()->isZoned());
	Assert::same(['My Zone, Custom'], array_map(fn($definition) => $definition->tzid(), $escaped->timezones()));
	Assert::same([], array_filter(array_map('strval', (new ICal\Validation\Validator())->validate($escaped)), fn($issue) => str_contains($issue, 'timezone.')));
});

test('Review: the validator uses the timezone resolver of the calendar', function () {
	$calendar = ICal::parser()->timezoneResolver(ICal\Timezone\CompositeTimezoneResolver::default(new DateTimeZone('UTC')))
		->parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Nowhere:20260101T100000\r\nEND:VEVENT\r\nEND:VCALENDAR")->calendar();
	$codes = array_map(fn($issue) => $issue->code, (new ICal\Validation\Validator())->validate($calendar));
	Assert::notContains('timezone.unresolved', $codes);
	Assert::contains('timezone.not-defined', $codes);
});
