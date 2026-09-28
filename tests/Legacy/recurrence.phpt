<?php
declare(strict_types=1);

/**
 * Recurrence sets and rules of the deprecated IcalParser, Freq and Recurrence.
 */

use om\Freq;
use om\IcalParser;
use om\ParserOptions;
use om\Recurrence;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('UTC');

function calendarEvents(string $content): array {
	$parser = new IcalParser();
	$parser->parseString("BEGIN:VCALENDAR\n$content\nEND:VCALENDAR");
	$data = serialize($parser->data);
	$events = $parser->getEvents()->sorted()->getArrayCopy();
	Assert::same($data, serialize($parser->data));
	Assert::equal($events, $parser->getEvents()->sorted()->getArrayCopy());
	return $events;
}

test('RDATE without RRULE forms a sorted set and EXDATE wins over additions', function () {
	$events = calendarEvents("BEGIN:VEVENT\nUID:rdate\nDTSTART:20260102T100000Z\nDTEND:20260102T110000Z\nRDATE:20260101T100000Z,20260102T100000Z,20260103T100000Z\nEXDATE:20260103T100000Z\nEND:VEVENT");
	Assert::same(['2026-01-01 10:00', '2026-01-02 10:00'], array_map(fn($e) => $e['DTSTART']->format('Y-m-d H:i'), $events));
	Assert::same('2026-01-01 11:00', $events[0]['DTEND']->format('Y-m-d H:i'));
});

test('Excluding the first occurrence does not resurrect DTSTART', function () {
	$events = calendarEvents("BEGIN:VEVENT\nDTSTART:20260101T100000Z\nRRULE:FREQ=DAILY;COUNT=3\nEXDATE:20260101T100000Z\nEND:VEVENT");
	Assert::same(['02', '03'], array_map(fn($e) => $e['DTSTART']->format('d'), $events));
});

test('Excluding the entire series returns no events', function () {
	Assert::same([], calendarEvents("BEGIN:VEVENT\nDTSTART:20260101T100000Z\nRRULE:FREQ=DAILY;COUNT=1\nEXDATE:20260101T100000Z\nEND:VEVENT"));
});

test('An override only replaces the matching UID with UTC or local recurrence ID', function () {
	foreach (['RECURRENCE-ID:20260101T090000Z', 'RECURRENCE-ID;TZID=Europe/Prague:20260101T100000'] as $id) {
		$events = calendarEvents("BEGIN:VEVENT\nUID:a\nDTSTART;TZID=Europe/Prague:20260101T100000\nRRULE:FREQ=DAILY;COUNT=2\nEND:VEVENT\nBEGIN:VEVENT\nUID:b\nDTSTART;TZID=Europe/Prague:20260101T100000\nRRULE:FREQ=DAILY;COUNT=2\nEND:VEVENT\nBEGIN:VEVENT\nUID:a\n$id\nDTSTART;TZID=Europe/Prague:20260101T120000\nEND:VEVENT");
		Assert::count(4, $events);
		Assert::same(['b', 'a', 'a', 'b'], array_column($events, 'UID'));
		Assert::same(['01 10:00', '01 12:00', '02 10:00', '02 10:00'], array_map(fn($e) => $e['DTSTART']->format('d H:i'), $events));
	}
});

test('COUNT includes excluded dates and additions are outside the RRULE interval', function () {
	$events = calendarEvents("BEGIN:VEVENT\nDTSTART:20260101T100000Z\nRRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=2\nRDATE:20260108T100000Z\nEXDATE:20260101T100000Z\nEND:VEVENT");
	Assert::same(['08', '15'], array_map(fn($e) => $e['DTSTART']->format('d'), $events));
});

test('Daily wall clock time is retained across daylight saving transitions', function () {
	foreach ([['20260328', ['+0100', '+0200', '+0200']], ['20261024', ['+0200', '+0100', '+0100']]] as [$start, $offsets]) {
		$events = calendarEvents("BEGIN:VEVENT\nDTSTART;TZID=Europe/Prague:{$start}T100000\nRRULE:FREQ=DAILY;COUNT=3\nEND:VEVENT");
		Assert::same(['10:00', '10:00', '10:00'], array_map(fn($e) => $e['DTSTART']->format('H:i'), $events));
		Assert::same($offsets, array_map(fn($e) => $e['DTSTART']->format('O'), $events));
	}
});

test('The epoch and an empty cached series are distinct from failure', function () {
	$frequency = new Freq(['FREQ' => 'DAILY', 'UNTIL' => 86400], 0);
	Assert::same([0, 86400], $frequency->getAllOccurrences());
	$empty = new Freq('FREQ=DAILY;COUNT=1', 0, [0]);
	Assert::same([], $empty->getAllOccurrences());
	Assert::same([], $empty->getAllOccurrences());
	Assert::false($empty->firstOccurrence());
	Assert::false($empty->lastOccurrence());
	Assert::false($empty->nextOccurrence(-1));
	Assert::false($empty->previousOccurrence(1));
});

test('Numeric BY rules accept padded and unpadded values including negative month days', function () {
	foreach (['1', '01'] as $month) {
		$rule = "FREQ=DAILY;COUNT=3;BYMONTH=$month;BYMONTHDAY=1,2,3;BYHOUR=9";
		$frequency = new Freq($rule, strtotime('2026-01-01T09:00:00Z'));
		Assert::same(['2026-01-01', '2026-01-02', '2026-01-03'], array_map(fn($ts) => gmdate('Y-m-d', $ts), $frequency->getAllOccurrences()));
	}
	$frequency = new Freq('FREQ=MONTHLY;COUNT=3;BYMONTHDAY=-1', strtotime('2026-01-31T09:00:00Z'));
	Assert::same(['2026-01-31', '2026-02-28', '2026-03-31'], array_map(fn($ts) => gmdate('Y-m-d', $ts), $frequency->getAllOccurrences()));
});

test('Finite caches stop at the last occurrence and EXDATE removes RDATE too', function () {
	$frequency = new Freq('FREQ=DAILY;COUNT=2', 0, [86400], [86400, -86400]);
	Assert::same([-86400, 0], $frequency->getAllOccurrences());
	Assert::same(-86400, $frequency->firstOccurrence());
	Assert::same(0, $frequency->lastOccurrence());
	Assert::same(-86400, $frequency->previousOccurrence(0));
	Assert::false($frequency->previousOccurrence(-86400));
	Assert::same(0, $frequency->nextOccurrence(-86400));
	Assert::false($frequency->nextOccurrence(0));
	Assert::false($frequency->findNext(false));
});

test('Occurrence and search budgets prevent unlimited expansion', function () {
	Assert::exception(fn() => new Freq('FREQ=DAILY;COUNT=4', 0, maxOccurrences: 3), RuntimeException::class);
	Assert::exception(fn() => (new Freq('FREQ=DAILY', 0, maxOccurrences: 3))->getAllOccurrences(), RuntimeException::class);
	Assert::same([0, 2678400], (new Freq('FREQ=DAILY;COUNT=2;BYMONTH=2', 0, maxOccurrences: 3))->getAllOccurrences());
	Assert::same([0], (new Freq('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', 0))->getAllOccurrences(), 'a rule without any further match ends');
	Assert::exception(fn() => new Freq('FREQ=DAILY', 0, maxOccurrences: 0), InvalidArgumentException::class);
	Assert::exception(fn() => new Freq('FREQ=DAILY;COUNT=2', 0, added: [172800], maxOccurrences: 2), RuntimeException::class);
	Assert::exception(fn() => (new Freq('FREQ=DAILY', 0, maxOccurrences: 2))->previousOccurrence(864000), RuntimeException::class);
	Assert::count(3, (new Freq('FREQ=DAILY;COUNT=3', 0, maxOccurrences: 3))->getAllOccurrences());
});

test('BYSETPOS and BYSECOND are supported, an invalid UNTIL fails', function () {
	Assert::same([0, 10, 86410], (new Freq('FREQ=DAILY;COUNT=3;BYSECOND=10', 0))->getAllOccurrences());
	Assert::same(['1970-01-01', '1970-01-30', '1970-02-27'], array_map(fn($ts) => gmdate('Y-m-d', $ts), (new Freq('FREQ=MONTHLY;COUNT=3;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1', 0))->getAllOccurrences()));
	Assert::exception(fn() => new Freq('FREQ=DAILY;UNTIL=invalid', 0), InvalidArgumentException::class);
});

test('String and array recurrence rules produce the same finite series', function () {
	$start = strtotime('2026-01-01T10:00:00Z');
	$expected = [$start, $start + 86400, $start + 172800];
	Assert::same($expected, (new Freq('FREQ=DAILY;COUNT=3', $start))->getAllOccurrences());
	Assert::same($expected, (new Freq('FREQ=DAILY;COUNT=03;INTERVAL=01', $start))->getAllOccurrences());
	Assert::same($expected, (new Freq(['FREQ' => 'DAILY', 'COUNT' => 3], $start))->getAllOccurrences());
	Assert::same($expected, (new Freq(['FREQ' => 'DAILY', 'UNTIL' => new DateTimeImmutable('2026-01-03T10:00:00Z')], $start))->getAllOccurrences());
});

test('Invalid recurrence inputs fail before expansion', function () {
	foreach (['FREQ', 'FREQ=', 'FREQ=UNKNOWN', 'FREQ=DAILY;INTERVAL=0', 'FREQ=DAILY;INTERVAL=-1', 'FREQ=DAILY;COUNT=0', 'FREQ=DAILY;COUNT=1.5'] as $rule) {
		Assert::exception(fn() => new Freq($rule, 0), InvalidArgumentException::class);
	}
});

test('Recurrence extensions are retained without dynamic properties', function () {
	$rule = ['FREQ' => 'DAILY', 'X-CUSTOM' => 'value', 'LISTPROPERTIES' => 'extension'];
	$recurrence = new Recurrence($rule);
	Assert::same($rule, $recurrence->rrule);
	Assert::same('DAILY', $recurrence->getFreq());
	Assert::false(property_exists($recurrence, 'x-custom'));
	Assert::false($recurrence->getCount());
});

test('An invalid recurrence rule fails in strict mode and keeps the process timezone', function () {
	$parser = new IcalParser(new ParserOptions(strict: true));
	Assert::exception(fn() => $parser->parseString(implode("\n", [
		'BEGIN:VCALENDAR',
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/Denver:20260101T100000',
		'RRULE:FREQ=DAILY;INTERVAL=0',
		'END:VEVENT',
		'END:VCALENDAR',
	])), InvalidArgumentException::class);
	Assert::same('UTC', date_default_timezone_get());
});

test('An invalid recurrence rule is ignored by default and the event keeps DTSTART', function () {
	$parser = new IcalParser();
	$parser->parseString("BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:x\nDTSTART:20260101T100000Z\nRRULE:FREQ=DAILY;INTERVAL=0\nEND:VEVENT\nEND:VCALENDAR");
	Assert::same(['2026-01-01 10:00'], array_map(fn($e) => $e['DTSTART']->format('Y-m-d H:i'), $parser->getEvents()->getArrayCopy()));
});

test('RDATE without RRULE adds only its dates, as in 4.1.4 (#37)', function () {
	$parser = new IcalParser();
	$parser->parseFile(__DIR__ . '/../Fixtures/Regression/issue-37-rdate-without-rrule.ics');
	$events = $parser->getEvents()->sorted();
	Assert::count(4, $events);
	Assert::same('15.4.2016 21:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same('16.12.2016 21:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same('23.12.2016 21:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same('30.12.2016 21:00:00', $events[3]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same('17.12.2016 04:00:00', $events[1]['DTEND']->format('j.n.Y H:i:s'));
});
