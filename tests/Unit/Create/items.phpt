<?php
declare(strict_types=1);

/**
 * Event::new(), Todo::new(), Journal::new() and Location::new().
 */

use om\ICal;
use om\ICal\Alarm;
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Event;
use om\ICal\Item;
use om\ICal\Journal;
use om\ICal\Location;
use om\ICal\Parameters;
use om\ICal\Property;
use om\ICal\Serializer;
use om\ICal\Todo;
use om\ICal\Validation\Issue;
use om\ICal\Validation\Severity;
use om\ICal\Validation\Validator;
use om\ICal\Value\CalAddress;
use om\ICal\Value\Classification;
use om\ICal\Value\Conference;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use om\ICal\Value\Link;
use om\ICal\Value\Period;
use om\ICal\Value\Relation;
use om\ICal\Value\Status;
use om\RRule\Rule;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

const STAMP = '20260101T000000Z';

function lines(Item $item): array {
	return explode("\r\n", trim(Serializer::serialize($item->component)));
}

function prague(string $time): DateTimeImmutable {
	return new DateTimeImmutable($time, new DateTimeZone('Europe/Prague'));
}

/** Errors of the validator for a calendar with the item. */
function errors(Item $item): array {
	$issues = (new Validator())->validate(Calendar::create()->withComponent($item));
	return array_map(strval(...), array_values(array_filter($issues, fn(Issue $issue) => $issue->severity === Severity::Error)));
}

/** The item parsed back from its serialization. */
function reparse(Item $item): Item {
	$calendar = ICal::parse(Calendar::create()->withComponent($item)->serialize());
	return [...$calendar->events(), ...$calendar->todos(), ...$calendar->journals()][0];
}

test('An event with the arguments of the issue', function () {
	$event = Event::new(
		uid: 'standup-1@example.org',
		stamp: STAMP,
		summary: 'Standup, team A',
		start: prague('2026-01-05 09:30'),
		duration: new DateInterval('PT15M'),
		rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
		organizer: CalAddress::create('mailto:boss@example.org', name: 'Boss'),
		attendees: [CalAddress::create('mailto:a@example.org', name: 'A', rsvp: true)],
		alarms: [Alarm::display('Standup', trigger: '-PT5M')],
		properties: ['X-EXAMPLE' => 'custom'],
	);
	Assert::same([
		'BEGIN:VEVENT',
		'UID:standup-1@example.org',
		'DTSTAMP:20260101T000000Z',
		'DTSTART;TZID=Europe/Prague:20260105T093000',
		'DURATION:PT15M',
		'RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR',
		'SUMMARY:Standup\, team A',
		'ORGANIZER;CN=Boss:mailto:boss@example.org',
		'ATTENDEE;CN=A;RSVP=TRUE:mailto:a@example.org',
		'X-EXAMPLE:custom',
		'BEGIN:VALARM',
		'ACTION:DISPLAY',
		'TRIGGER:-PT5M',
		'DESCRIPTION:Standup',
		'END:VALARM',
		'END:VEVENT',
	], lines($event));

	// the typed getters of the created event
	Assert::same('Standup, team A', $event->summary());
	Assert::same('2026-01-05 09:30 Europe/Prague', $event->start()->toDateTime()->format('Y-m-d H:i e'), 'TZID resolves without a VTIMEZONE');
	Assert::same('2026-01-05 09:45', $event->end()->format('Y-m-d H:i'));
	Assert::same('FREQ=WEEKLY;BYDAY=MO,WE,FR', $event->recurrenceRule()->toString());
	Assert::same(['2026-01-05', '2026-01-07', '2026-01-09'], array_map(fn($o) => $o->start->format('Y-m-d'), iterator_to_array($event->occurrences(3), false)));
	Assert::same([], errors($event));
});

test('Every argument of an event', function () {
	$event = Event::new(
		uid: 'all-1',
		stamp: prague('2026-01-01 10:00'),
		start: prague('2026-03-28 10:00'),
		end: prague('2026-03-29 10:00'),
		summary: 'Summary',
		description: "Line 1\nLine 2; with, separators",
		location: 'Room 1, 2nd floor',
		url: 'https://example.org/event',
		status: Status::Confirmed,
		transparency: 'transparent',
		classification: Classification::Private,
		priority: 1,
		sequence: 2,
		categories: ['Work, office', 'Meeting'],
		organizer: 'boss@example.org',
		attendees: ['a@example.org', CalAddress::create('mailto:b@example.org', role: 'OPT-PARTICIPANT', status: 'TENTATIVE')],
		rrule: Rule::fromString('FREQ=DAILY;UNTIL=20260405T080000Z'),
		rdates: [prague('2026-04-10 10:00')],
		exdates: [prague('2026-03-30 10:00'), prague('2026-03-31 10:00')],
		geo: [50.087, 14.421],
		color: 'turquoise',
		images: ['https://example.org/a.png'],
		conferences: [new Conference('https://meet.example.org/1', Parameters::from(['FEATURE' => 'VIDEO', 'LABEL' => 'Join']))],
		links: [new Link('https://example.org/doc', Parameters::from(['LINKREL' => 'latest-version']))],
		relatedTo: [new Relation('parent-1', Parameters::from(['RELTYPE' => 'PARENT']))],
		locations: [Location::new(uid: 'loc-1', name: 'Venue', types: ['hotel'], geo: [50.0, 14.0])],
	);
	Assert::same([
		'BEGIN:VEVENT',
		'UID:all-1',
		'DTSTAMP:20260101T090000Z',
		'DTSTART;TZID=Europe/Prague:20260328T100000',
		'DTEND;TZID=Europe/Prague:20260329T100000',
		'RRULE:FREQ=DAILY;UNTIL=20260405T080000Z',
		'RDATE;TZID=Europe/Prague:20260410T100000',
		'EXDATE;TZID=Europe/Prague:20260330T100000,20260331T100000',
		'SUMMARY:Summary',
		'DESCRIPTION:Line 1\nLine 2\; with\, separators',
		'LOCATION:Room 1\, 2nd floor',
		'GEO:50.087;14.421',
		'URL:https://example.org/event',
		'CLASS:PRIVATE',
		'PRIORITY:1',
		'SEQUENCE:2',
		'CATEGORIES:Work\, office,Meeting',
		'COLOR:turquoise',
		'ORGANIZER:mailto:boss@example.org',
		'ATTENDEE:mailto:a@example.org',
		'ATTENDEE;ROLE=OPT-PARTICIPANT;PARTSTAT=TENTATIVE:mailto:b@example.org',
		'IMAGE;VALUE=URI:https://example.org/a.png',
		'CONFERENCE;FEATURE=VIDEO;LABEL=Join;VALUE=URI:https://meet.example.org/1',
		'LINK;LINKREL=latest-version:https://example.org/doc',
		'RELATED-TO;RELTYPE=PARENT:parent-1',
		'STATUS:CONFIRMED',
		'TRANSP:TRANSPARENT',
		'BEGIN:VLOCATION',
		'UID:loc-1',
		'NAME:Venue',
		'GEO:50;14',
		'LOCATION-TYPE:hotel',
		'END:VLOCATION',
		'END:VEVENT',
	], lines($event));
	Assert::same([], errors($event));

	// the same typed values after parsing
	$parsed = reparse($event);
	foreach ([$event, $parsed] as $item) {
		Assert::same('all-1', $item->uid());
		Assert::same("Line 1\nLine 2; with, separators", $item->description());
		Assert::same('Room 1, 2nd floor', $item->location());
		Assert::same(['Work, office', 'Meeting'], $item->categories());
		Assert::same([50.087, 14.421], $item->geo());
		Assert::same(['CONFIRMED', 'TRANSPARENT', 'PRIVATE', 1, 2], [$item->status(), $item->transparency(), $item->classification(), $item->priority(), $item->sequence()]);
		Assert::same('PT23H', Duration::format($item->duration()), 'the DST change shortens the day');
		Assert::same(['2026-03-28', '2026-03-29', '2026-04-01'], array_map(fn($o) => $o->start->format('Y-m-d'), iterator_to_array($item->occurrences(3), false)));
		Assert::same(['a@example.org', 'b@example.org'], array_map(fn(CalAddress $a) => $a->email(), $item->attendees()));
		Assert::same('OPT-PARTICIPANT', $item->attendees()[1]->role());
		Assert::same(['VIDEO'], $item->conferences()[0]->features());
		Assert::same('latest-version', $item->links()[0]->relation());
		Assert::same('PARENT', $item->relatedTo()[0]->type());
		Assert::same(['hotel'], $item->locations()[0]->types());
		Assert::same('Venue', $item->locations()[0]->name());
		Assert::same('https://example.org/a.png', $item->images()[0]->uri);
	}
	Assert::same(Serializer::serialize($event->component), Serializer::serialize($parsed->component));
});

test('All-day and floating events', function () {
	$day = Event::new(uid: 'day', stamp: STAMP, start: DateTimeValue::date(2026, 12, 24), end: '20261227', rrule: 'FREQ=YEARLY;UNTIL=20301224', exdates: ['20271224']);
	Assert::same(['DTSTART;VALUE=DATE:20261224', 'DTEND;VALUE=DATE:20261227', 'RRULE:FREQ=YEARLY;UNTIL=20301224', 'EXDATE;VALUE=DATE:20271224'], array_slice(lines($day), 3, 4));
	Assert::true($day->isAllDay());
	Assert::same([], errors($day));

	$floating = Event::new(uid: 'f', stamp: STAMP, start: '20260105T090000', duration: 'PT1H', rrule: 'FREQ=DAILY;UNTIL=20260110T090000');
	Assert::same(['DTSTART:20260105T090000', 'DURATION:PT1H', 'RRULE:FREQ=DAILY;UNTIL=20260110T090000'], array_slice(lines($floating), 3, 3));
	Assert::true($floating->start()->isFloating());
	Assert::same([], errors($floating));

	$utc = Event::new(uid: 'u', stamp: STAMP, start: new DateTimeImmutable('2026-01-05 09:00', new DateTimeZone('UTC')), end: new DateTimeImmutable('2026-01-05 10:00+01:00'));
	Assert::same(['DTSTART:20260105T090000Z', 'DTEND:20260105T090000Z'], array_slice(lines($utc), 3, 2));
});

test('Defaults: a random UID and DTSTAMP now in UTC', function () {
	$before = time();
	$event = Event::new(summary: 'x');
	Assert::match('~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $event->uid());
	Assert::notSame($event->uid(), Event::new()->uid());
	Assert::true($event->stamp()->isUtc());
	Assert::true($event->stamp()->toDateTime()->getTimestamp() >= $before);
	Assert::same([], errors($event));
});

test('Other properties, also repeated ones with parameters', function () {
	$event = Event::new(uid: 'p', stamp: STAMP, properties: [
		Property::create('COMMENT', 'first', ['LANGUAGE' => 'en']),
		Property::create('COMMENT', 'second'),
		'CREATED' => prague('2025-12-31 10:00'),
		'RESOURCES' => ['Projector'],
	]);
	Assert::same(['COMMENT;LANGUAGE=en:first', 'COMMENT:second', 'CREATED:20251231T090000Z', 'RESOURCES:Projector'], array_slice(lines($event), 3, 4));
	Assert::same('20251231T090000Z', (string) $event->created());
});

test('Invalid combinations of arguments are rejected', function () {
	$start = prague('2026-01-05 09:30');
	$cases = [
		'DTEND and DURATION' => fn() => Event::new(start: $start, end: $start, duration: 'PT1H'),
		'must not be earlier than the start' => fn() => Event::new(start: $start, end: $start->modify('-1 minute')),
		'DTEND requires a start' => fn() => Event::new(end: $start),
		'DURATION requires a start' => fn() => Event::new(duration: 'PT1H'),
		'must not be negative' => fn() => Event::new(start: $start, duration: '-PT1H'),
		'must be days or weeks' => fn() => Event::new(start: '20260105', duration: 'PT1H'),
		'must be a DATE like the start' => fn() => Event::new(start: '20260105', end: $start),
		'must be a DATE-TIME like the start' => fn() => Event::new(start: $start, end: '20260106'),
		'RRULE requires a start' => fn() => Event::new(rrule: 'FREQ=DAILY'),
		'RDATE requires a start' => fn() => Event::new(rdates: [$start]),
		'EXDATE 20260106 must be a DATE-TIME' => fn() => Event::new(start: $start, rrule: 'FREQ=DAILY', exdates: ['20260106']),
		'RDATE 20260106T093000 must be a DATE' => fn() => Event::new(start: '20260105', rdates: [new Period(DateTimeValue::parse('20260106T093000'), DateTimeValue::parse('20260106T103000'))]),
		'RECURRENCE-ID must be a DATE-TIME' => fn() => Event::new(start: $start, recurrenceId: '20260105'),
		'UNTIL must be a UTC time like the start, a DATE given' => fn() => Event::new(start: $start, rrule: 'FREQ=DAILY;UNTIL=20260110'),
		'UNTIL must be a DATE like the start' => fn() => Event::new(start: '20260105', rrule: 'FREQ=DAILY;UNTIL=20260110T000000Z'),
		'UNTIL must be a floating time' => fn() => Event::new(start: '20260105T090000', rrule: 'FREQ=DAILY;UNTIL=20260110T000000Z'),
		'COUNT and UNTIL' => fn() => Event::new(start: $start, rrule: new Rule(om\RRule\Frequency::Daily, count: 2, until: $start)),
		'Unsupported recurrence frequency' => fn() => Event::new(start: $start, rrule: 'FREQ=SOMETIMES'),
		'PRIORITY must be between 0 and 9' => fn() => Event::new(priority: 10),
		'SEQUENCE must be between 0' => fn() => Event::new(sequence: -1),
		'STATUS of VEVENT must be one of TENTATIVE, CONFIRMED, CANCELLED, completed given' => fn() => Event::new(status: 'completed'),
		'STATUS of VEVENT must be one of TENTATIVE, CONFIRMED, CANCELLED, DRAFT given' => fn() => Event::new(status: Status::Draft),
		'TRANSP must be OPAQUE or TRANSPARENT' => fn() => Event::new(transparency: 'free'),
		'Invalid CLASS value' => fn() => Event::new(classification: 'top secret'),
		'GEO must be' => fn() => Event::new(geo: [0, 181]),
		'Invalid URI' => fn() => Event::new(url: 'not a uri'),
		'UID must not be empty' => fn() => Event::new(uid: ' '),
		'An alarm related to the start requires a start' => fn() => Event::new(alarms: [Alarm::display('x', '-PT5M')]),
		'An alarm related to the end requires DTEND' => fn() => Event::new(start: $start, alarms: [Alarm::display('x', '-PT5M', related: 'END')]),
		'Alarms must be VALARM components' => fn() => Event::new(alarms: [new Component('VTODO')]),
		'Locations must be VLOCATION components' => fn() => Event::new(locations: [new Component('VALARM')]),
		'SUMMARY of VEVENT is already set' => fn() => Event::new(summary: 'a', properties: ['SUMMARY' => 'b']),
		'UID of VEVENT is already set' => fn() => Event::new(properties: ['UID' => 'b']),
		'DUE and DURATION' => fn() => Todo::new(start: $start, due: $start, duration: 'PT1H'),
		'DUE (20260105T092900) must not be earlier' => fn() => Todo::new(start: $start, due: $start->modify('-1 minute')),
		'PERCENT-COMPLETE must be between 0 and 100' => fn() => Todo::new(percentComplete: 101),
		'COMPLETED must be an instant' => fn() => Todo::new(completed: '20260105'),
		'STATUS of VTODO must be one of NEEDS-ACTION' => fn() => Todo::new(status: 'CONFIRMED'),
		'An alarm related to the end requires DUE' => fn() => Todo::new(start: $start, alarms: [Alarm::display('x', '-PT5M', related: 'END')]),
		'STATUS of VJOURNAL must be one of DRAFT' => fn() => Journal::new(status: 'TENTATIVE'),
		'Invalid DATE-TIME value' => fn() => Journal::new(start: 'tomorrow'),
	];
	foreach ($cases as $message => $case) {
		$e = Assert::exception($case, InvalidArgumentException::class);
		Assert::contains($message, $e->getMessage());
	}
});

test('Alarms related to the end of an event with an end or a duration', function () {
	$start = prague('2026-01-05 09:30');
	$alarm = Alarm::display('x', '-PT5M', related: 'END');
	Assert::count(1, Event::new(start: $start, duration: 'PT1H', alarms: [$alarm])->alarms());
	Assert::count(1, Event::new(start: $start, end: $start, alarms: [$alarm])->alarms());
	Assert::count(1, Event::new(alarms: [Alarm::display('x', '20260105T080000Z')])->alarms(), 'an absolute trigger needs no start');
	Assert::count(1, Todo::new(due: $start, alarms: [$alarm])->alarms());
});

test('A task', function () {
	$todo = Todo::new(
		uid: 'todo-1',
		stamp: STAMP,
		start: prague('2026-01-05 09:00'),
		due: prague('2026-01-09 17:00'),
		completed: prague('2026-01-08 12:00'),
		percentComplete: 100,
		summary: 'Report',
		status: 'completed',
		priority: 2,
		alarms: [Alarm::display('Report is due', trigger: '-PT1H', related: 'END')],
	);
	Assert::same([
		'BEGIN:VTODO', 'UID:todo-1', 'DTSTAMP:20260101T000000Z',
		'DTSTART;TZID=Europe/Prague:20260105T090000', 'DUE;TZID=Europe/Prague:20260109T170000',
		'COMPLETED:20260108T110000Z', 'PERCENT-COMPLETE:100', 'SUMMARY:Report', 'PRIORITY:2', 'STATUS:COMPLETED',
		'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER;RELATED=END:-PT1H', 'DESCRIPTION:Report is due', 'END:VALARM', 'END:VTODO',
	], lines($todo));
	Assert::same([], errors($todo));
	foreach ([$todo, reparse($todo)] as $item) {
		Assert::true($item->isCompleted());
		Assert::same(100, $item->percentComplete());
		Assert::same('2026-01-09 17:00', $item->due()->format('Y-m-d H:i'));
		Assert::same('20260108T110000Z', (string) $item->completed());
	}
	$duration = Todo::new(uid: 'todo-2', stamp: STAMP, start: '20260105', duration: 'P2D');
	Assert::same('2026-01-07', $duration->due()->format('Y-m-d'));
	Assert::same([], errors(Todo::new(uid: 'todo-3', stamp: STAMP, due: '20260105')), 'DUE without a start');
});

test('A journal entry', function () {
	$journal = Journal::new(uid: 'j-1', stamp: STAMP, start: '20260105', summary: 'Minutes', description: 'Notes', status: Status::Final, categories: ['Minutes'], rrule: 'FREQ=WEEKLY;COUNT=2');
	Assert::same([
		'BEGIN:VJOURNAL', 'UID:j-1', 'DTSTAMP:20260101T000000Z', 'DTSTART;VALUE=DATE:20260105', 'RRULE:FREQ=WEEKLY;COUNT=2',
		'SUMMARY:Minutes', 'DESCRIPTION:Notes', 'CATEGORIES:Minutes', 'STATUS:FINAL', 'END:VJOURNAL',
	], lines($journal));
	Assert::same([], errors($journal));
	Assert::same('Minutes', reparse($journal)->summary());
});

test('A location', function () {
	$location = Location::new(name: 'Parking', description: 'Level -1', url: 'https://example.org/parking', properties: ['STRUCTURED-DATA' => 'raw']);
	Assert::match('~^[0-9a-f-]{36}$~', $location->uid());
	Assert::same(['Parking', 'Level -1', 'https://example.org/parking', 'raw'], [$location->name(), $location->description(), $location->url(), $location->property('STRUCTURED-DATA')->value]);
	Assert::exception(fn() => Location::new(uid: 'a', properties: ['UID' => 'b']), InvalidArgumentException::class, '~already set~');
});
