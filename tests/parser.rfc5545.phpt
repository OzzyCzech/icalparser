<?php
declare(strict_types=1);

/**
 * Content line and property handling according to RFC 5545.
 */

use om\IcalParser;
use om\ParserOptions;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('UTC');

function parse(string $content, ?ParserOptions $options = null): IcalParser {
	$parser = new IcalParser($options);
	$parser->parseString("BEGIN:VCALENDAR\r\n" . $content . "\r\nEND:VCALENDAR\r\n");
	return $parser;
}

/**
 * @return list<string>
 */
function starts(IcalParser $parser, string $format = 'Y-m-d H:i'): array {
	return array_map(fn($event) => $event['DTSTART']->format($format), $parser->getEvents()->sorted()->getArrayCopy());
}

test('Quoted parameter values may contain colons, semicolons and commas', function () {
	$event = parse(implode("\r\n", [
		'BEGIN:VEVENT',
		'ORGANIZER;CN="Doe; John";SENT-BY="mailto:assistant@example.org":mailto:john@example.org',
		'ATTENDEE;CN="Smith, Jane";ROLE=REQ-PARTICIPANT:mailto:jane@example.org',
		'DESCRIPTION;ALTREP="http://example.org/desc.html":Text',
		'END:VEVENT',
	]))->data['VEVENT'][0];

	Assert::same('mailto:john@example.org', $event['ORGANIZER']);
	Assert::same('Doe; John', $event['ORGANIZER-CN']);
	Assert::same('mailto:assistant@example.org', $event['ORGANIZER-SENT-BY']);
	Assert::same(['CN' => 'Smith, Jane', 'ROLE' => 'REQ-PARTICIPANT', 'VALUE' => 'mailto:jane@example.org'], $event['ATTENDEES'][0]);
	Assert::same('Text', $event['DESCRIPTION']);
});

test('Property, parameter and component names are case-insensitive', function () {
	$parser = parse("begin:vevent\r\nuid:lower\r\ndtstart;value=date:20260105\r\nsummary:Lower case\r\nend:vevent");
	$event = $parser->data['VEVENT'][0];
	Assert::same('lower', $event['UID']);
	Assert::same('Lower case', $event['SUMMARY']);
	Assert::same('2026-01-05', $event['DTSTART']->format('Y-m-d'));
});

test('CR line endings and a byte order mark are accepted', function () {
	$parser = new IcalParser();
	$data = $parser->parseString("\u{FEFF}BEGIN:VCALENDAR\rVERSION:2.0\rBEGIN:VEVENT\rUID:cr\rSUMMARY:Old\r  Mac\rEND:VEVENT\rEND:VCALENDAR");
	Assert::same('2.0', $data['VERSION']);
	Assert::same('Old Mac', $data['VEVENT'][0]['SUMMARY']);
});

test('Escaped commas do not split CATEGORIES', function () {
	$event = parse("BEGIN:VEVENT\r\nCATEGORIES:Work\\, Office,Home\r\nEND:VEVENT")->data['VEVENT'][0];
	Assert::same(['Work, Office', 'Home'], $event['CATEGORIES']);
});

test('Properties of unknown and nested components do not leak into their parent', function () {
	$parser = parse(implode("\r\n", [
		'BEGIN:VEVENT',
		'UID:parent',
		'BEGIN:X-CUSTOM',
		'SUMMARY:Inside custom component',
		'END:X-CUSTOM',
		'SUMMARY:Parent summary',
		'END:VEVENT',
	]));
	Assert::same('Parent summary', $parser->data['VEVENT'][0]['SUMMARY']);
	Assert::same('Inside custom component', $parser->data['X-CUSTOM'][0]['SUMMARY']);
	Assert::hasNotKey('BEGIN', $parser->data['VEVENT'][0]);
});

test('DURATION defines the end of single and recurring events', function () {
	$parser = parse(implode("\r\n", [
		'BEGIN:VEVENT',
		'UID:single',
		'DTSTART:20260105T090000Z',
		'DURATION:PT1H30M',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:recurring',
		'DTSTART:20260110T090000Z',
		'DURATION:P1DT2H',
		'RRULE:FREQ=WEEKLY;COUNT=2',
		'END:VEVENT',
	]));
	$ends = array_map(fn($event) => $event['DTEND']->format('Y-m-d H:i'), $parser->getEvents()->sorted()->getArrayCopy());
	Assert::same(['2026-01-05 10:30', '2026-01-11 11:00', '2026-01-18 11:00'], $ends);
	Assert::same('PT1H30M', $parser->data['VEVENT'][0]['DURATION']);
	Assert::hasNotKey('DTEND', $parser->data['VEVENT'][0], 'parsed data is not modified');
});

test('DURATION values', function () {
	Assert::same('+1 day 02:00:00', IcalParser::parseDuration('P1DT2H')->format('%R%d day %H:%I:%S'));
	Assert::same(15, IcalParser::parseDuration('P2W1D')->d);
	Assert::same(1, IcalParser::parseDuration('-PT15M')->invert);
	Assert::null(IcalParser::parseDuration('P'));
	Assert::null(IcalParser::parseDuration('1H'));
});

test('An all-day event without DTEND lasts one day', function () {
	$events = parse("BEGIN:VEVENT\r\nUID:day\r\nDTSTART;VALUE=DATE:20260105\r\nEND:VEVENT")->getEvents()->getArrayCopy();
	Assert::same('2026-01-06', $events[0]['DTEND']->format('Y-m-d'));
});

test('A date-only EXDATE removes the occurrence of that day', function () {
	$parser = parse("BEGIN:VEVENT\r\nUID:x\r\nDTSTART;TZID=Europe/Prague:20260105T090000\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEXDATE;VALUE=DATE:20260106\r\nEND:VEVENT");
	Assert::same(['2026-01-05', '2026-01-07'], starts($parser, 'Y-m-d'));
});

test('RECURRENCE-ID matches the instance by time, whatever its timezone', function () {
	$parser = parse(implode("\r\n", [
		'BEGIN:VEVENT',
		'UID:meeting',
		'DTSTART;TZID=Europe/Prague:20260105T090000',
		'RRULE:FREQ=DAILY;COUNT=3',
		'SUMMARY:Regular',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:meeting',
		'RECURRENCE-ID;TZID=America/New_York:20260106T030000',
		'DTSTART;TZID=Europe/Prague:20260106T140000',
		'SUMMARY:Moved',
		'END:VEVENT',
	]));
	Assert::same(['2026-01-05 09:00', '2026-01-06 14:00', '2026-01-07 09:00'], starts($parser));
});

test('A date-only RECURRENCE-ID overrides the instance of that day', function () {
	$parser = parse(implode("\r\n", [
		'BEGIN:VEVENT',
		'UID:day',
		'DTSTART;VALUE=DATE:20260105',
		'RRULE:FREQ=DAILY;COUNT=2',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:day',
		'RECURRENCE-ID;VALUE=DATE:20260106',
		'DTSTART;VALUE=DATE:20260107',
		'END:VEVENT',
	]));
	Assert::same(['2026-01-05', '2026-01-07'], starts($parser, 'Y-m-d'));
});

test('RDATE periods are represented by their start', function () {
	$parser = parse("BEGIN:VEVENT\r\nUID:p\r\nDTSTART:20260105T090000Z\r\nDTEND:20260105T100000Z\r\nRDATE;VALUE=PERIOD:20260107T090000Z/20260107T100000Z,20260108T090000Z/PT1H\r\nEND:VEVENT");
	Assert::same(['2026-01-05 09:00', '2026-01-07 09:00', '2026-01-08 09:00'], starts($parser));
});

test('Tasks and journals are available with their dates', function () {
	$parser = parse(implode("\r\n", [
		'BEGIN:VTODO',
		'UID:todo',
		'DUE:20260110T170000Z',
		'COMPLETED:20260109T120000Z',
		'SUMMARY:Write tests',
		'END:VTODO',
		'BEGIN:VJOURNAL',
		'UID:journal',
		'SUMMARY:Notes',
		'END:VJOURNAL',
	]));
	$todos = $parser->getTodos();
	Assert::count(1, $todos);
	Assert::type(DateTime::class, $todos[0]['DUE']);
	Assert::same('2026-01-10 17:00', $todos[0]['DUE']->format('Y-m-d H:i'));
	Assert::type(DateTime::class, $todos[0]['COMPLETED']);
	Assert::same('Notes', $parser->getJournals()[0]['SUMMARY']);
	Assert::count(0, $parser->getEvents());
});

test('The calendar timezone does not leak into the next parsed calendar', function () {
	$parser = new IcalParser();
	$parser->parseString("BEGIN:VCALENDAR\nX-WR-TIMEZONE:Europe/Prague\nEND:VCALENDAR");
	Assert::same('Europe/Prague', $parser->timezone->getName());
	$data = $parser->parseString("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20260105T090000\nEND:VEVENT\nEND:VCALENDAR");
	Assert::null($parser->timezone);
	Assert::same('UTC', $data['VEVENT'][0]['DTSTART']->getTimezone()->getName());
});

test('Unbounded rules use a fixed horizon from ParserOptions::$now', function () {
	$options = new ParserOptions(untilInterval: new DateInterval('P1W'), now: new DateTimeImmutable('2026-01-01T00:00:00Z'));
	$parser = parse("BEGIN:VEVENT\r\nDTSTART:20251229T090000Z\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT", $options);
	Assert::count(10, $parser->getEvents());
	Assert::same('2026-01-07 09:00', starts($parser)[9]);

	$options = new ParserOptions(untilInterval: null, now: new DateTimeImmutable('2026-01-01T00:00:00Z'));
	Assert::count(3, parse("BEGIN:VEVENT\r\nDTSTART:20251229T090000Z\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT", $options)->getEvents());
});

test('A COUNT series is complete even beyond the horizon for unbounded rules', function () {
	$parser = parse("BEGIN:VEVENT\r\nDTSTART:20260101T100000Z\r\nRRULE:FREQ=YEARLY;COUNT=6\r\nEND:VEVENT", new ParserOptions(now: new DateTimeImmutable('2026-01-01')));
	Assert::same(['2026', '2027', '2028', '2029', '2030', '2031'], starts($parser, 'Y'));
});

test('An unbounded event starting after the horizon keeps its first instance', function () {
	$now = new DateTimeImmutable('2026-01-01T00:00:00Z');
	$event = "BEGIN:VEVENT\r\nDTSTART:20400101T100000Z\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT";
	Assert::same(['2040-01-01 10:00'], starts(parse($event, new ParserOptions(now: $now))));
	Assert::same(['2040-01-01 10:00'], starts(parse($event, new ParserOptions(untilInterval: null, now: $now))));
	$until = "BEGIN:VEVENT\r\nDTSTART;TZID=Europe/Prague:20240110T100000\r\nRRULE:FREQ=DAILY;UNTIL=20240110T080000Z\r\nEND:VEVENT";
	Assert::same(['2024-01-10 10:00'], starts(parse($until)), 'UNTIL before DTSTART keeps DTSTART as in 4.1.3');
});

test('shiftEventDates skips old occurrences of unbounded rules', function () {
	$options = new ParserOptions(untilInterval: new DateInterval('P1W'), shiftEventDates: new DateInterval('P3D'), now: new DateTimeImmutable('2026-01-10T00:00:00Z'));
	$parser = parse("BEGIN:VEVENT\r\nDTSTART:19700101T090000Z\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT", $options);
	Assert::same('2026-01-07 09:00', starts($parser)[0]);
	Assert::same('2026-01-16 09:00', starts($parser)[9]);
	Assert::count(10, $parser->getEvents());
});

test('Long series are truncated to maxOccurrences, strict mode throws', function () {
	$event = "BEGIN:VEVENT\r\nDTSTART:20260101T090000Z\r\nRRULE:FREQ=DAILY;COUNT=50\r\nEND:VEVENT";
	Assert::count(5, parse($event, new ParserOptions(maxOccurrences: 5))->getEvents());
	Assert::exception(fn() => parse($event, new ParserOptions(maxOccurrences: 5, strict: true)), RuntimeException::class);
	Assert::exception(fn() => new ParserOptions(maxOccurrences: 0), InvalidArgumentException::class);
});

test('An event without a valid DTSTART is kept as it is', function () {
	$events = parse("BEGIN:VEVENT\r\nUID:broken\r\nDTSTART:not a date\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT")->getEvents()->getArrayCopy();
	Assert::count(1, $events);
	Assert::null($events[0]['DTSTART']);
	Assert::hasNotKey('RECURRENCES', $events[0]);
});

test('parseRecurrences() expands a parsed event', function () {
	$parser = parse("BEGIN:VEVENT\r\nDTSTART:20260101T090000Z\r\nRRULE:FREQ=MONTHLY;COUNT=3;BYMONTHDAY=-1\r\nEND:VEVENT");
	$dates = $parser->parseRecurrences($parser->data['VEVENT'][0]);
	Assert::same(['2026-01-01', '2026-01-31', '2026-02-28'], array_map(fn($date) => $date->format('Y-m-d'), $dates));
	Assert::exception(fn() => $parser->parseRecurrences(['RRULE' => ['FREQ' => 'DAILY']]), InvalidArgumentException::class);

	$parser = parse("BEGIN:VEVENT\r\nDTSTART;TZID=America/New_York:20240101T100000\r\nRRULE:FREQ=DAILY;UNTIL=20240103\r\nEXDATE;VALUE=DATE:20240102\r\nEND:VEVENT");
	$dates = array_map(fn($date) => $date->format('Y-m-d'), $parser->parseRecurrences($parser->data['VEVENT'][0]));
	Assert::same(['2024-01-01', '2024-01-03'], $dates, 'same result as the parsed RECURRENCES');
});
