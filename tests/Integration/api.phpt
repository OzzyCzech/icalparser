<?php
declare(strict_types=1);

/**
 * The public API: calendars, typed items, series and occurrences.
 */

use om\ICal;
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Event;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Exception\TimezoneResolutionException;
use om\ICal\Occurrence;
use om\ICal\Property;
use om\ICal\Serializer;
use om\ICal\Value\DateTimeValue;
use om\RRule\RecurrenceLimits;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';

function calendar(string ...$lines): Calendar {
	return ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n" . implode("\r\n", $lines) . "\r\nEND:VCALENDAR\r\n");
}

/**
 * @param iterable<Occurrence> $occurrences
 * @return list<string>
 */
function starts(iterable $occurrences, string $format = 'Y-m-d H:i'): array {
	$result = [];
	foreach ($occurrences as $occurrence) {
		$result[] = $occurrence->start->format($format) . ($occurrence->isModified() ? ' *' : '');
	}
	return $result;
}

function between(string $from, string $to): array {
	return [new DateTimeImmutable($from, new DateTimeZone('UTC')), new DateTimeImmutable($to, new DateTimeZone('UTC'))];
}

test('Calendar properties and typed item getters', function () {
	$calendar = calendar(
		'X-WR-CALNAME:Team',
		'X-WR-TIMEZONE:Europe/Prague',
		'METHOD:publish',
		'BEGIN:VEVENT',
		'UID:e1',
		'DTSTAMP:20260101T000000Z',
		'DTSTART;TZID=Europe/Prague:20260105T100000',
		'DTEND;TZID=Europe/Prague:20260105T113000',
		'SUMMARY:Planning\, Q1',
		'DESCRIPTION:Line one\nLine two',
		'LOCATION:Room 1',
		'STATUS:confirmed',
		'CLASS:PRIVATE',
		'CATEGORIES:Work,Planning',
		'CATEGORIES:Team',
		'SEQUENCE:2',
		'PRIORITY:1',
		'TRANSP:TRANSPARENT',
		'GEO:50.08;14.42',
		'URL:https://example.org/e1',
		'CREATED:20251201T080000Z',
		'LAST-MODIFIED:20251202T080000Z',
		'ORGANIZER;CN="Doe, Jane":mailto:jane@example.org',
		'ATTENDEE;CN=John;PARTSTAT=ACCEPTED:mailto:john@example.org',
		'ATTENDEE;CN=Ann;ROLE=OPT-PARTICIPANT:mailto:ann@example.org',
		'X-APPLE-STRUCTURED-LOCATION;VALUE=URI;X-TITLE=Room 1:geo:50.08,14.42',
		'X-MICROSOFT-CDO-BUSYSTATUS:BUSY',
		'END:VEVENT',
	);
	Assert::same(['Team', null, '-//test//EN', '2.0', 'PUBLISH'], [$calendar->name(), $calendar->description(), $calendar->productId(), $calendar->version(), $calendar->method()]);
	Assert::same('Europe/Prague', $calendar->timezone()->getName());

	$event = $calendar->events()[0];
	Assert::type(Event::class, $event);
	Assert::same(['e1', 'Planning, Q1', "Line one\nLine two", 'Room 1', 'CONFIRMED', 'PRIVATE'], [$event->uid(), $event->summary(), $event->description(), $event->location(), $event->status(), $event->classification()]);
	Assert::same(['Work', 'Planning', 'Team'], $event->categories());
	Assert::same([2, 1, 'TRANSPARENT'], [$event->sequence(), $event->priority(), $event->transparency()]);
	Assert::same([50.08, 14.42], $event->geo());
	Assert::same('https://example.org/e1', $event->url());
	Assert::same('20251201T080000Z', (string) $event->created());
	Assert::same('20251202T080000Z', (string) $event->lastModified());
	Assert::same('20260101T000000Z', (string) $event->stamp());
	Assert::same('Doe, Jane', $event->organizer()->name());
	Assert::same(['john@example.org', 'ann@example.org'], array_map(fn($a) => $a->email(), $event->attendees()));
	Assert::same('OPT-PARTICIPANT', $event->attendees()[1]->role());
	Assert::same('2026-01-05 10:00 Europe/Prague', $event->start()->format('Y-m-d H:i e'));
	Assert::same('PT1H30M', ICal\Value\Duration::format($event->duration()), 'the exact duration of DTEND - DTSTART');
	Assert::same('2026-01-05 11:30', $event->end()->format('Y-m-d H:i'));
	Assert::false($event->isAllDay() || $event->isRecurring() || $event->isOverride() || $event->isCancelled());
	Assert::same('Room 1', $event->property('X-APPLE-STRUCTURED-LOCATION')->parameter('X-TITLE'), 'unknown properties are kept');
	Assert::same('BUSY', $event->value('X-MICROSOFT-CDO-BUSYSTATUS'));
	Assert::same('geo:50.08,14.42', $event->value('X-APPLE-STRUCTURED-LOCATION'));
	Assert::null($event->value('COMMENT'));
	Assert::same($calendar, $event->calendar());
	Assert::same('publish', $calendar->value('METHOD'), 'the raw text, method() normalizes it');
});

test('All-day events last a day, DURATION and DTEND define the end', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:day', 'DTSTART;VALUE=DATE:20260105', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:days', 'DTSTART;VALUE=DATE:20260105', 'DTEND;VALUE=DATE:20260108', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:duration', 'DTSTART:20260105T100000Z', 'DURATION:PT45M', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:instant', 'DTSTART:20260105T100000Z', 'END:VEVENT',
	);
	$ends = array_map(fn(Event $event) => (string) $event->end(), $calendar->events());
	Assert::same(['20260106', '20260108', '20260105T104500Z', '20260105T100000Z'], $ends);
	Assert::true($calendar->events()[0]->isAllDay());
	Assert::same(3, $calendar->events()[1]->duration()->days);
});

test('Floating times keep their local time; an instant needs a timezone', function () {
	$calendar = calendar('BEGIN:VEVENT', 'UID:f', 'DTSTART:20260105T100000', 'DTEND:20260105T110000', 'END:VEVENT');
	$start = $calendar->events()[0]->start();
	Assert::true($start->isFloating());
	Assert::exception(fn() => $calendar->occurrencesBetween(...between('2026-01-01', '2026-02-01'))[0]->startTime(), TimezoneResolutionException::class);
	$occurrence = $calendar->occurrencesBetween(...between('2026-01-01', '2026-02-01'))[0];
	Assert::same('2026-01-05T10:00:00-05:00', $occurrence->startTime(new DateTimeZone('America/New_York'))->format('c'));

	$configured = ICal::parser()->floatingTimezone(new DateTimeZone('Asia/Tokyo'))->parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:f\r\nDTSTART:20260105T100000\r\nEND:VEVENT\r\nEND:VCALENDAR")->calendar();
	Assert::same('2026-01-05T10:00:00+09:00', $configured->occurrencesBetween(...between('2026-01-01', '2026-02-01'))[0]->startTime()->format('c'));
	Assert::same('+09:00', $configured->occurrencesBetween(...between('2026-01-01', '2026-02-01'))[0]->endTime()->format('P'));
});

test('A recurring series with RDATE, EXDATE, a moved, a cancelled and a changed instance', function () {
	$calendar = calendar(
		'BEGIN:VEVENT',
		'UID:series',
		'DTSTART;TZID=Europe/Prague:20260105T100000',
		'DTEND;TZID=Europe/Prague:20260105T110000',
		'RRULE:FREQ=WEEKLY;COUNT=6',
		'RDATE;TZID=Europe/Prague:20260107T150000',
		'EXDATE;TZID=Europe/Prague:20260119T100000',
		'SUMMARY:Weekly',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:series',
		'RECURRENCE-ID;TZID=Europe/Prague:20260112T100000',
		'DTSTART;TZID=Europe/Prague:20260113T140000',
		'DTEND;TZID=Europe/Prague:20260113T150000',
		'SUMMARY:Moved to Tuesday',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:series',
		'RECURRENCE-ID:20260126T090000Z',
		'DTSTART;TZID=Europe/Prague:20260126T100000',
		'STATUS:CANCELLED',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:series',
		'RECURRENCE-ID;TZID=Europe/Prague:20260202T100000',
		'DTSTART;TZID=Europe/Prague:20260202T100000',
		'DTEND;TZID=Europe/Prague:20260202T120000',
		'SUMMARY:Longer',
		'END:VEVENT',
	);
	Assert::count(1, $calendar->events(), 'overrides are not separate events');
	$event = $calendar->events()[0];
	Assert::count(3, $event->overrides());
	Assert::true($event->overrides()[0]->isOverride());

	$occurrences = iterator_to_array($event->occurrencesBetween(...between('2026-01-01', '2026-03-01')), false);
	Assert::same(['2026-01-05 10:00', '2026-01-07 15:00', '2026-01-13 14:00 *', '2026-02-02 10:00 *', '2026-02-09 10:00'], starts($occurrences));
	Assert::same(['Weekly', 'Weekly', 'Moved to Tuesday', 'Longer', 'Weekly'], array_map(fn(Occurrence $o) => $o->summary(), $occurrences));
	Assert::same('20260112T100000', (string) $occurrences[2]->recurrenceId);
	Assert::same('12:00', $occurrences[3]->end->format('H:i'));
	Assert::same($event, $occurrences[2]->master);
	Assert::true($occurrences[0]->isRecurring());
	Assert::false($occurrences[0]->isAllDay());

	$withCancelled = starts($event->occurrencesBetween(...between('2026-01-01', '2026-03-01'), includeCancelled: true));
	Assert::contains('2026-01-26 10:00 *', $withCancelled);
	Assert::true(iterator_to_array($event->occurrencesBetween(...between('2026-01-26', '2026-01-27'), includeCancelled: true))[0]->isCancelled());

	Assert::same(['2026-01-13 14:00 *'], starts($event->occurrencesBetween(...between('2026-01-13', '2026-01-14'))), 'a moved instance is found at its new time');
	Assert::same([], starts($event->occurrencesBetween(...between('2026-01-12', '2026-01-13'))), 'and not at its original time');
	Assert::same(['2026-01-05 10:00', '2026-01-07 15:00'], starts($event->occurrences(2)));
	Assert::same(['2026-02-02 10:00 *', '2026-02-09 10:00'], starts($event->occurrences(5, new DateTimeImmutable('2026-01-27'))));
	Assert::same([], starts($event->occurrences(0)));
});

test('RANGE=THISANDFUTURE changes an instance and all later ones', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:r', 'DTSTART:20260105T100000Z', 'DTEND:20260105T110000Z', 'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Old', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:r', 'RECURRENCE-ID;RANGE=THISANDFUTURE:20260107T100000Z', 'DTSTART:20260107T120000Z', 'DTEND:20260107T123000Z', 'SUMMARY:New', 'END:VEVENT',
	);
	$occurrences = iterator_to_array($calendar->events()[0]->occurrencesBetween(...between('2026-01-01', '2026-02-01')), false);
	Assert::same(['2026-01-05 10:00', '2026-01-06 10:00', '2026-01-07 12:00 *', '2026-01-08 12:00 *', '2026-01-09 12:00 *'], starts($occurrences));
	Assert::same(['Old', 'Old', 'New', 'New', 'New'], array_map(fn(Occurrence $o) => $o->summary(), $occurrences));
	Assert::same('12:30', $occurrences[4]->end->format('H:i'));
	Assert::true($calendar->events()[0]->overrides()[0]->isThisAndFuture());
});

test('Date-only and floating recurring series, date-only EXDATE and RECURRENCE-ID', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:d', 'DTSTART;VALUE=DATE:20260105', 'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE;VALUE=DATE:20260106', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:d', 'RECURRENCE-ID;VALUE=DATE:20260107', 'DTSTART;VALUE=DATE:20260110', 'SUMMARY:Moved day', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:t', 'DTSTART;TZID=Europe/Prague:20260105T100000', 'RRULE:FREQ=HOURLY;COUNT=3', 'EXDATE;VALUE=DATE:20260105', 'RDATE:20260106T080000Z', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:f', 'DTSTART:20260328T100000', 'RRULE:FREQ=DAILY;COUNT=3', 'END:VEVENT',
	);
	[$days, $hours, $floating] = $calendar->events();
	Assert::same(['2026-01-05', '2026-01-08', '2026-01-10 *'], starts($days->occurrencesBetween(...between('2026-01-01', '2026-02-01')), 'Y-m-d'));
	Assert::same(['2026-01-06 09:00'], starts($hours->occurrencesBetween(...between('2026-01-01', '2026-02-01'))), 'a date EXDATE removes the whole day');
	Assert::same(['03-28 10:00', '03-29 10:00', '03-30 10:00'], starts($floating->occurrencesBetween(...between('2026-01-01', '2026-12-01')), 'm-d H:i'), 'floating times keep the local time over DST');
	Assert::true($floating->occurrences(1)->current()->start->isFloating());
});

test('Occurrences of the calendar are sorted and limited to the window', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:a', 'DTSTART:20260105T100000Z', 'RRULE:FREQ=DAILY', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:b', 'DTSTART:20260106T080000Z', 'DTEND:20260106T090000Z', 'END:VEVENT',
		'BEGIN:VEVENT', 'UID:c', 'DTSTART:20260104T230000Z', 'DTEND:20260105T010000Z', 'END:VEVENT',
	);
	Assert::same(['2026-01-04 23:00', '2026-01-05 10:00', '2026-01-06 08:00', '2026-01-06 10:00'], starts($calendar->occurrencesBetween(...between('2026-01-05', '2026-01-07'))), 'an event overlapping the start of the window is included');
	Assert::same([], $calendar->occurrencesBetween(...between('2025-01-01', '2025-02-01')));
});

test('Unbounded expansion is limited', function () {
	$limited = ICal::parser()->recurrenceLimits(new RecurrenceLimits(maxInstances: 10))->parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20260101T000000Z\r\nRRULE:FREQ=HOURLY\r\nEND:VEVENT\r\nEND:VCALENDAR")->calendar();
	Assert::count(10, iterator_to_array($limited->events()[0]->occurrencesBetween(...between('2026-01-01', '2026-01-01 09:59')), false));
	$exception = Assert::exception(fn() => iterator_to_array($limited->events()[0]->occurrencesBetween(...between('2026-01-01', '2026-02-01'))), ResourceLimitException::class);
	Assert::same('recurrence.limit', $exception->errorCode());
});

test('Invalid RRULE: the event is a single occurrence in permissive mode', function () {
	$calendar = calendar('BEGIN:VEVENT', 'UID:x', 'DTSTART:20260101T100000Z', 'RRULE:FREQ=DAILY;INTERVAL=0', 'END:VEVENT');
	Assert::null($calendar->events()[0]->recurrenceRule());
	Assert::same(['2026-01-01 10:00'], starts($calendar->events()[0]->occurrencesBetween(...between('2026-01-01', '2027-01-01'))));
});

test('Several RRULEs are combined', function () {
	$calendar = calendar('BEGIN:VEVENT', 'UID:x', 'DTSTART:20260101T100000Z', 'RRULE:FREQ=WEEKLY;COUNT=2', 'RRULE:FREQ=DAILY;INTERVAL=3;COUNT=2', 'END:VEVENT');
	Assert::same(['01-01', '01-04', '01-08'], starts($calendar->events()[0]->occurrencesBetween(...between('2026-01-01', '2027-01-01')), 'm-d'));
	Assert::count(2, $calendar->events()[0]->recurrenceRules());
});

test('Orphan overrides and events without UID are single events', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:o', 'RECURRENCE-ID:20260105T100000Z', 'DTSTART:20260105T120000Z', 'END:VEVENT',
		'BEGIN:VEVENT', 'DTSTART:20260105T100000Z', 'END:VEVENT',
		'BEGIN:VEVENT', 'DTSTART:20260106T100000Z', 'END:VEVENT',
		'BEGIN:VEVENT', 'SUMMARY:No start', 'END:VEVENT',
	);
	Assert::count(4, $calendar->events());
	Assert::same(['2026-01-05 10:00', '2026-01-05 12:00', '2026-01-06 10:00'], starts($calendar->occurrencesBetween(...between('2026-01-01', '2026-02-01'))));
	$withoutStart = array_values(array_filter($calendar->events(), fn(Event $event) => $event->summary() === 'No start'))[0];
	Assert::null($withoutStart->end());
	Assert::same([], iterator_to_array($withoutStart->occurrences()));
});

test('Tasks, journal entries, free/busy components and alarms', function () {
	$calendar = calendar(
		'BEGIN:VTODO', 'UID:t', 'DTSTART:20260105T090000Z', 'DUE:20260106T170000Z', 'COMPLETED:20260106T120000Z', 'PERCENT-COMPLETE:100', 'STATUS:COMPLETED', 'SUMMARY:Write tests',
		'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER;RELATED=END:-PT1H', 'DESCRIPTION:Due soon', 'REPEAT:2', 'DURATION:PT10M', 'END:VALARM',
		'END:VTODO',
		'BEGIN:VTODO', 'UID:t2', 'DTSTART:20260105T090000Z', 'DURATION:PT2H', 'END:VTODO',
		'BEGIN:VJOURNAL', 'UID:j', 'DTSTART;VALUE=DATE:20260105', 'SUMMARY:Notes', 'END:VJOURNAL',
		'BEGIN:VFREEBUSY', 'UID:fb', 'DTSTART:20260105T000000Z', 'DTEND:20260106T000000Z', 'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T100000Z/PT1H,20260105T140000Z/20260105T150000Z', 'END:VFREEBUSY',
		'BEGIN:VEVENT', 'UID:e', 'DTSTART:20260105T100000Z',
		'BEGIN:VALARM', 'ACTION:EMAIL', 'TRIGGER;VALUE=DATE-TIME:20260105T080000Z', 'SUMMARY:Mail', 'ATTENDEE:mailto:a@example.org', 'END:VALARM',
		'END:VEVENT',
	);
	[$task, $short] = $calendar->todos();
	Assert::same(['20260106T170000Z', '20260106T120000Z', 100], [(string) $task->due(), (string) $task->completed(), $task->percentComplete()]);
	Assert::true($task->isCompleted());
	Assert::same('20260105T110000Z', (string) $short->due());
	Assert::same('20260105T110000Z', (string) $short->end());
	Assert::same(['2026-01-05 09:00'], starts($task->occurrencesBetween(...between('2026-01-01', '2026-02-01'))));

	$alarm = $task->alarms()[0];
	Assert::same(['DISPLAY', 'END', 'Due soon', 2], [$alarm->action(), $alarm->related(), $alarm->description(), $alarm->repeat()]);
	Assert::same(600, $alarm->duration()->i * 60);
	$occurrence = $task->occurrences(1)->current();
	Assert::same('2026-01-06 16:00', $alarm->triggerTime($occurrence)->format('Y-m-d H:i'));

	$journal = $calendar->journals()[0];
	Assert::same('Notes', $journal->summary());
	Assert::same(0, $journal->duration()->s);

	$periods = $calendar->freeBusy()[0]->periods();
	Assert::count(2, $periods);
	Assert::same(['BUSY-TENTATIVE', '20260105T100000Z/PT1H'], [$periods[0][1], (string) $periods[0][0]]);
	Assert::same(24, $calendar->freeBusy()[0]->duration()->h, 'exact elapsed time in hours');

	$mail = $calendar->events()[0]->alarms()[0];
	Assert::same('2026-01-05 08:00', $mail->triggerTime($calendar->events()[0]->occurrences(1)->current())->format('Y-m-d H:i'));
	Assert::same(['EMAIL', 'Mail', 'a@example.org'], [$mail->action(), $mail->summary(), $mail->attendees()[0]->email()]);
	Assert::null((new ICal\Alarm(new Component('VALARM'), $calendar))->trigger());
});

test('Streaming returns items one by one with the timezones seen before', function () {
	$file = __DIR__ . '/../Fixtures/Samples/recur_instances_with_modifications.ics';
	$items = iterator_to_array(ICal::stream($file), false);
	Assert::count(2, $items);
	Assert::same([true, false], array_map(fn(Event $item) => $item->isOverride(), $items), 'overrides are separate items in a stream');
	$warnings = [];
	foreach (ICal::parser()->stream(fopen($file, 'rb'), function ($warning) use (&$warnings) {
		$warnings[] = $warning->code;
	}) as $item) {
		Assert::type(Event::class, $item);
	}
	Assert::same(['syntax.line-ending'], $warnings);

	$stream = fopen('php://memory', 'r+b');
	fwrite($stream, "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20260101T100000\r\nEND:VEVENT\r\nX-WR-TIMEZONE:Asia/Tokyo\r\nBEGIN:VEVENT\r\nUID:b\r\nDTSTART:20260101T100000\r\nEND:VEVENT\r\ngarbage\r\nEND:VCALENDAR\r\n");
	rewind($stream);
	$warnings = [];
	$zones = array_map(fn(Event $event) => $event->calendar()->floatingTimezone()?->getName(), iterator_to_array(ICal::parser()->stream($stream, function ($warning) use (&$warnings) {
		$warnings[] = $warning->code;
	}), false));
	Assert::same([null, 'Asia/Tokyo'], $zones, 'calendar properties seen so far apply');
	Assert::same(['syntax.invalid-line'], $warnings);
});

test('Creating a calendar and serializing it', function () {
	$event = new Component('VEVENT', [
		Property::create('UID', 'new@example.org'),
		Property::create('DTSTAMP', '20260101T000000Z'),
		Property::create('DTSTART', (string) DateTimeValue::parse('20260105T100000', false, 'Europe/Prague', new DateTimeZone('Europe/Prague')), ['TZID' => 'Europe/Prague']),
		Property::create('SUMMARY', ICal\Value\Text::escape('Planning, Q1; long description ' . str_repeat('ž', 40))),
		Property::create('X-CUSTOM', 'kept', ['X-PARAM' => 'a:b']),
	]);
	$calendar = Calendar::create()->withComponent($event);
	$ics = $calendar->serialize();
	Assert::contains("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//om//icalparser//EN\r\nBEGIN:VEVENT\r\n", $ics);
	Assert::contains('X-CUSTOM;X-PARAM="a:b":kept', $ics);
	foreach (explode("\r\n", $ics) as $line) {
		Assert::true(strlen($line) <= 75, $line);
		Assert::true(mb_check_encoding($line, 'UTF-8'), 'folding keeps UTF-8 characters');
	}

	$parsed = ICal::parse($ics);
	Assert::same('Planning, Q1; long description ' . str_repeat('ž', 40), $parsed->events()[0]->summary());
	Assert::same('2026-01-05 10:00 Europe/Prague', $parsed->events()[0]->start()->format('Y-m-d H:i e'));
	Assert::same($ics, $parsed->serialize(), 'round trip');
	Assert::same("A:1\r\n", Serializer::fold('A:1'));
});

function contents(Component $component): array {
	return [
		$component->name,
		array_map(fn(Property $property) => [$property->name, $property->parameters->all(), $property->value], $component->properties),
		array_map(contents(...), $component->components),
	];
}

test('Round trip of the sample calendars keeps every property', function () {
	foreach (glob(__DIR__ . '/../Fixtures/Samples/*.ics') as $file) {
		$first = ICal::parser()->parseFile($file)->calendars;
		foreach ($first as $calendar) {
			$again = ICal::parse($calendar->serialize());
			Assert::same(contents($calendar->component), contents($again->component), basename($file));
		}
	}
});

test('Immutable components', function () {
	$component = new Component('VEVENT');
	$changed = $component->withProperty('SUMMARY', 'A')->withProperty('SUMMARY', 'B')->withComponent(new Component('VALARM'));
	Assert::same([], $component->properties);
	Assert::same(['A', 'B'], array_map(fn(Property $p) => $p->value, $changed->properties('summary')));
	Assert::same('C', $changed->withReplacedProperty('SUMMARY', 'C')->property('SUMMARY')->value);
	Assert::false($changed->withoutProperties('SUMMARY')->has('SUMMARY'));
	Assert::same('VALARM', $changed->component('valarm')->name);
	Assert::null($changed->component('VTODO'));
	Assert::same([], $changed->withComponents([])->components);
	$property = Property::create('DTSTART', '20260101');
	Assert::same('DTSTART;VALUE=DATE:20260101', (string) $property->withParameter('VALUE', 'DATE'));
	Assert::same('DTSTART:20260102', (string) $property->withValue('20260102'));
	Assert::null($property->parameter('VALUE'));
});
