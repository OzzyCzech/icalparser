<?php
declare(strict_types=1);

/**
 * Semantic validation (RFC 5545), independent of parsing.
 */

use om\ICal;
use om\ICal\Exception\ValidationException;
use om\ICal\Validation\Issue;
use om\ICal\Validation\Severity;
use om\ICal\Validation\Validator;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * @return list<string> "SEVERITY code"
 */
function issues(array $event): array {
	$calendar = ICal::parse(implode("\r\n", [
		'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN',
		'BEGIN:VTIMEZONE', 'TZID:Europe/Prague', 'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'END:STANDARD', 'END:VTIMEZONE',
		...$event,
		'END:VCALENDAR',
	]));
	return array_map(fn(Issue $issue) => strtoupper($issue->severity->name) . ' ' . $issue->code, (new Validator())->validate($calendar));
}

$base = ['BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z'];

test('A valid calendar has no issues', function () use ($base) {
	Assert::same([], issues([...$base, 'DTSTART;TZID=Europe/Prague:20260105T100000', 'DTEND;TZID=Europe/Prague:20260105T110000', 'RRULE:FREQ=DAILY;UNTIL=20260110T090000Z', 'END:VEVENT']));
	Assert::same([], issues([...$base, 'DTSTART;VALUE=DATE:20260105', 'RRULE:FREQ=YEARLY;UNTIL=20300105', 'END:VEVENT']));
	Assert::same([], issues([...$base, 'DTSTART:20260105T100000', 'RRULE:FREQ=DAILY;UNTIL=20260110T100000', 'END:VEVENT']));
});

test('Required and single properties', function () {
	Assert::same(['ERROR component.missing-property', 'ERROR component.missing-property'], issues(['BEGIN:VEVENT', 'DTSTART:20260105T100000Z', 'END:VEVENT']));
	Assert::same(['ERROR component.duplicate-property'], issues(['BEGIN:VEVENT', 'UID:1', 'UID:2', 'DTSTAMP:20260101T000000Z', 'END:VEVENT']));
	Assert::same(['ERROR component.missing-property'], issues(['BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'END:VALARM', 'END:VEVENT']), 'TRIGGER is required');
	Assert::contains('ERROR alarm.outside-item', issues(['BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'END:VALARM']));
});

test('DTEND, DUE and DURATION', function () use ($base) {
	Assert::same(['ERROR component.end-and-duration'], issues([...$base, 'DTSTART:20260105T100000Z', 'DTEND:20260105T110000Z', 'DURATION:PT1H', 'END:VEVENT']));
	Assert::same(['ERROR component.end-type'], issues([...$base, 'DTSTART;VALUE=DATE:20260105', 'DTEND:20260105T110000Z', 'END:VEVENT']));
	Assert::same(['ERROR component.end-before-start'], issues([...$base, 'DTSTART:20260105T100000Z', 'DTEND:20260105T090000Z', 'END:VEVENT']));
	Assert::same(['ERROR component.duration-without-start'], issues(['BEGIN:VTODO', 'UID:1', 'DTSTAMP:20260101T000000Z', 'DURATION:PT1H', 'END:VTODO']));
	Assert::same(['ERROR component.end-and-duration'], issues(['BEGIN:VTODO', 'UID:1', 'DTSTAMP:20260101T000000Z', 'DTSTART:20260105T100000Z', 'DUE:20260105T110000Z', 'DURATION:PT1H', 'END:VTODO']));
});

test('Recurrence rules', function () use ($base) {
	Assert::same(['ERROR recurrence.count-and-until'], issues([...$base, 'DTSTART:20260105T100000Z', 'RRULE:FREQ=DAILY;COUNT=2;UNTIL=20260110T000000Z', 'END:VEVENT']));
	Assert::same(['ERROR recurrence.until-type'], issues([...$base, 'DTSTART;TZID=Europe/Prague:20260105T100000', 'RRULE:FREQ=DAILY;UNTIL=20260110T100000', 'END:VEVENT']));
	Assert::same(['ERROR recurrence.until-type'], issues([...$base, 'DTSTART;VALUE=DATE:20260105', 'RRULE:FREQ=DAILY;UNTIL=20260110T100000Z', 'END:VEVENT']));
	Assert::same(['WARNING recurrence.multiple-rrule'], issues([...$base, 'DTSTART:20260105T100000Z', 'RRULE:FREQ=DAILY;COUNT=2', 'RRULE:FREQ=WEEKLY;COUNT=2', 'END:VEVENT']));
	Assert::same(['ERROR recurrence.without-start'], issues([...$base, 'RRULE:FREQ=DAILY', 'END:VEVENT']));
	Assert::same(['ERROR value.invalid'], issues([...$base, 'DTSTART:20260105T100000Z', 'RRULE:FREQ=SOMETIMES', 'END:VEVENT']));
});

test('Invalid values and timezones', function () use ($base) {
	Assert::same(['ERROR value.invalid', 'ERROR value.invalid'], issues([...$base, 'DTSTART:yesterday', 'SEQUENCE:x', 'END:VEVENT']));
	Assert::same(['WARNING timezone.not-defined'], issues([...$base, 'DTSTART;TZID=America/New_York:20260105T100000', 'END:VEVENT']));
	Assert::same(['WARNING timezone.not-defined', 'WARNING timezone.unresolved'], issues([...$base, 'DTSTART;TZID=Nowhere:20260105T100000', 'END:VEVENT']));
	Assert::contains('ERROR timezone.no-observance', issues(['BEGIN:VTIMEZONE', 'TZID:Empty', 'END:VTIMEZONE']));
});

test('Issues and exceptions', function () use ($base) {
	$calendar = ICal::parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20260105T100000Z\r\nDURATION:PT1H\r\nDTEND:20260105T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");
	$issues = (new Validator())->validate($calendar);
	Assert::same(Severity::Error, $issues[0]->severity);
	Assert::same('ERROR VCALENDAR.PRODID: PRODID is required. [component.missing-property]', (string) $issues[0]);
	$last = end($issues);
	Assert::same('ERROR VEVENT.DURATION (line 4): DTEND and DURATION must not occur together. [component.end-and-duration]', (string) $last);
	$exception = Assert::exception(fn() => (new Validator())->assertValid($calendar), ValidationException::class);
	Assert::same('component.missing-property', $exception->errorCode());
	Assert::noError(fn() => (new Validator())->assertValid(ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nEND:VCALENDAR")));
	Assert::same(0, Severity::Info->value);
});
