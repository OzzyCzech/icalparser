<?php
declare(strict_types=1);

/**
 * Calendar::create() with events, tasks, journal entries and generated VTIMEZONE definitions.
 *
 * The calendar of teamCalendar() is the golden file tests/Fixtures/Created/team.ics, regenerate it
 * after an intended change: UPDATE_SNAPSHOTS=1 composer test
 */

use om\ICal;
use om\ICal\Alarm;
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Event;
use om\ICal\Journal;
use om\ICal\Location;
use om\ICal\Property;
use om\ICal\Timezone\AliasTimezoneResolver;
use om\ICal\Timezone\CompositeTimezoneResolver;
use om\ICal\Timezone\IanaTimezoneResolver;
use om\ICal\Timezone\TimezoneSource;
use om\ICal\Timezone\VTimezoneBuilder;
use om\ICal\Timezone\VTimezoneResolver;
use om\ICal\Todo;
use om\ICal\Validation\Validator;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Status;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

const GOLDEN = __DIR__ . '/../../Fixtures/Created/team.ics';

function prague(string $time): DateTimeImmutable {
	return new DateTimeImmutable($time, new DateTimeZone('Europe/Prague'));
}

function issues(Calendar $calendar): array {
	return array_map(strval(...), (new Validator())->validate($calendar));
}

function teamCalendar(): Calendar {
	$stamp = '20260101T000000Z';
	return Calendar::create('-//example//team//EN', name: 'Team A', description: 'Meetings, tasks and notes of team A', color: 'steelblue', method: 'publish', events: [
		Event::new(
			uid: 'standup@example.org',
			stamp: $stamp,
			summary: 'Standup, team A',
			start: prague('2026-01-05 09:30'),
			duration: new DateInterval('PT15M'),
			rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
			exdates: [prague('2026-04-06 09:30')],
			organizer: CalAddress::create('mailto:boss@example.org', name: 'Boss'),
			attendees: [CalAddress::create('mailto:a@example.org', name: 'A', rsvp: true), CalAddress::create('mailto:b@example.org', name: 'B', role: 'OPT-PARTICIPANT')],
			categories: ['Meeting', 'Team A'],
			conferences: ['https://meet.example.org/team-a'],
			alarms: [Alarm::display('Standup in 5 minutes', trigger: '-PT5M', uid: 'standup-alarm')],
			properties: ['X-EXAMPLE' => 'custom'],
		),
		Event::new(
			uid: 'standup@example.org',
			stamp: $stamp,
			recurrenceId: prague('2026-03-30 09:30'),
			summary: 'Standup, team A (moved)',
			start: new DateTimeImmutable('2026-03-30 14:00', new DateTimeZone('America/New_York')),
			duration: 'PT30M',
			sequence: 1,
		),
		Event::new(
			uid: 'offsite@example.org',
			stamp: $stamp,
			summary: 'Offsite',
			description: "Two days out of the office.\nBring a laptop; lunch is provided.",
			start: DateTimeValue::date(2026, 10, 22),
			end: DateTimeValue::date(2026, 10, 24),
			location: 'Hotel Krkonoše, Špindlerův Mlýn',
			geo: [50.7263, 15.6095],
			transparency: 'transparent',
			status: Status::Confirmed,
			locations: [Location::new(uid: 'offsite-hotel', name: 'Hotel Krkonoše', types: ['hotel'])],
		),
	], todos: [
		Todo::new(
			uid: 'report@example.org',
			stamp: $stamp,
			summary: 'Quarterly report',
			start: prague('2026-03-23 09:00'),
			due: prague('2026-03-31 17:00'),
			priority: 1,
			percentComplete: 40,
			status: 'IN-PROCESS',
			alarms: [Alarm::email('Report is due', 'The quarterly report is due in a day.', ['boss@example.org'], trigger: '-P1D', related: 'END')],
		),
	], journals: [
		Journal::new(uid: 'minutes@example.org', stamp: $stamp, start: '20260105', summary: 'Minutes', description: 'Kickoff of the year.', status: 'final'),
	]);
}

test('Calendar::create() with only the product ID is backward compatible', function () {
	Assert::same("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//example//EN\r\nEND:VCALENDAR\r\n", Calendar::create('-//example//EN')->serialize());
	Assert::same('-//om//icalparser//EN', Calendar::create()->productId());
});

test('The golden calendar', function () {
	$calendar = teamCalendar();
	if (getenv('UPDATE_SNAPSHOTS')) {
		@mkdir(dirname(GOLDEN));
		file_put_contents(GOLDEN, $calendar->serialize());
	}
	Assert::same(file_get_contents(GOLDEN), $calendar->serialize());
	Assert::same([], issues($calendar), 'no errors and no warnings');
});

test('The getters of the created calendar', function () {
	$calendar = teamCalendar();
	foreach ([$calendar, ICal::parse($calendar->serialize())] as $c) {
		Assert::same(['Team A', 'Meetings, tasks and notes of team A', 'steelblue', 'PUBLISH', '2.0'], [$c->name(), $c->description(), $c->color(), $c->method(), $c->version()]);
		Assert::same(['Europe/Prague', 'America/New_York'], array_map(fn($definition) => $definition->tzid(), $c->timezones()));
		Assert::count(2, $c->events(), 'the override belongs to the series');
		[$standup, $offsite] = $c->events();
		Assert::count(1, $standup->overrides());
		Assert::same('Standup, team A (moved)', $standup->overrides()[0]->summary());
		$occurrences = iterator_to_array($standup->occurrencesBetween(prague('2026-03-27'), prague('2026-04-09')), false);
		Assert::same([
			'2026-03-27 09:30 +01:00 Standup, team A',
			'2026-03-30 20:00 +02:00 Standup, team A (moved)',
			'2026-04-01 09:30 +02:00 Standup, team A',
			'2026-04-03 09:30 +02:00 Standup, team A',
			'2026-04-08 09:30 +02:00 Standup, team A',
		], array_map(fn($o) => $o->startTime(new DateTimeZone('Europe/Prague'))->format('Y-m-d H:i P ') . $o->summary(), $occurrences));
		Assert::same("Two days out of the office.\nBring a laptop; lunch is provided.", $offsite->description());
		Assert::same('Hotel Krkonoše, Špindlerův Mlýn', $offsite->location());
		Assert::same(['2026-10-22', '2026-10-24'], [$offsite->start()->format('Y-m-d'), $offsite->end()->format('Y-m-d')]);
		Assert::same(40, $c->todos()[0]->percentComplete());
		Assert::same(['boss@example.org'], array_map(fn($a) => $a->email(), $c->todos()[0]->alarms()[0]->attendees()));
		Assert::same('FINAL', $c->journals()[0]->status());
	}
});

test('A VTIMEZONE is read also when its TZID is not an IANA name', function () {
	$calendar = Calendar::create(events: [
		Event::new(uid: 'weekly', stamp: '20260101T000000Z', start: prague('2026-01-07 09:30'), duration: 'PT1H', rrule: 'FREQ=WEEKLY'),
		Event::new(uid: 'single', stamp: '20260101T000000Z', start: prague('2026-10-25 01:30'), end: prague('2026-10-25 04:30')),
	]);
	// the same definition with a TZID the name resolvers do not know
	$ics = str_replace(['TZID:Europe/Prague', 'TZID=Europe/Prague', "X-LIC-LOCATION:Europe/Prague\r\n"], ['TZID:Custom/Zone-1', 'TZID=Custom/Zone-1', ''], $calendar->serialize());
	Assert::notContains('Europe/Prague', $ics);
	Assert::null((new CompositeTimezoneResolver(new IanaTimezoneResolver(), new AliasTimezoneResolver()))->resolve('Custom/Zone-1', new Component('VCALENDAR')));

	$names = new CompositeTimezoneResolver(new IanaTimezoneResolver(), new AliasTimezoneResolver());
	$parsed = ICal::parser()->timezoneResolver(new VTimezoneResolver($names, new DateTimeImmutable('2026-06-01')))->parse($ics)->calendar();
	$resolved = $parsed->timezones()[0]->resolve();
	Assert::same(TimezoneSource::VTimezone, $resolved->source);

	// the instants across the DST changes are those of Europe/Prague
	$prague = new DateTimeZone('Europe/Prague');
	[$weekly, $single] = $parsed->events();
	$starts = array_map(fn($o) => $o->startTime()->getTimestamp(), iterator_to_array($weekly->occurrences(52), false));
	$expected = [];
	for ($i = 0; $i < 52; $i++) {
		$expected[] = prague('2026-01-07 09:30')->modify("+$i weeks")->getTimestamp();
	}
	Assert::same($expected, $starts);
	Assert::same('2026-03-25 09:30 +01:00', (new DateTimeImmutable('@' . $starts[11]))->setTimezone($prague)->format('Y-m-d H:i P'));
	Assert::same('2026-04-01 09:30 +02:00', (new DateTimeImmutable('@' . $starts[12]))->setTimezone($prague)->format('Y-m-d H:i P'));
	Assert::same(prague('2026-10-25 01:30')->getTimestamp(), $single->start()->toDateTime()->getTimestamp());
	Assert::same(prague('2026-10-25 04:30')->getTimestamp(), $single->end()->toDateTime()->getTimestamp());
	Assert::same('PT4H', ICal\Value\Duration::format($single->duration()), 'the night of the DST change has an hour more');
});

test('VTIMEZONE: one per TZID, not duplicated, can be turned off', function () {
	$event = Event::new(uid: 'a', stamp: '20260101T000000Z', start: prague('2026-01-05 09:30'), rdates: [new DateTimeImmutable('2026-02-01 10:00', new DateTimeZone('Asia/Tokyo'))]);
	$names = fn(Calendar $calendar) => array_map(fn($definition) => $definition->tzid(), $calendar->timezones());
	Assert::same(['Europe/Prague', 'Asia/Tokyo'], $names(Calendar::create(events: [$event, $event])));
	Assert::same([], $names(Calendar::create(events: [$event], timezones: false)));

	$custom = new Component('VTIMEZONE', [Property::create('TZID', 'Europe/Prague')], [new Component('STANDARD', [Property::create('DTSTART', '19700101T000000'), Property::create('TZOFFSETFROM', '+0100'), Property::create('TZOFFSETTO', '+0100')])]);
	$calendar = Calendar::create(events: [$event], components: [$custom]);
	Assert::same(['Europe/Prague', 'Asia/Tokyo'], $names($calendar));
	Assert::same($custom, $calendar->component->components[0], 'the given definition is kept');

	// UTC, floating times and dates need no definition
	Assert::same([], $names(Calendar::create(events: [Event::new(start: '20260105T093000Z'), Event::new(start: '20260105T093000'), Event::new(start: '20260105')])));
});

test('VTIMEZONE covers the range of the dates, open recurrences for years', function () {
	$observances = function (Calendar $calendar): array {
		return array_map(fn(Component $c) => $c->name . ' ' . $c->property('DTSTART')->value . ($c->has('RRULE') ? ' ' . $c->property('RRULE')->value : ''), $calendar->component->components('VTIMEZONE')[0]->components);
	};
	$single = Calendar::create(events: [Event::new(start: new DateTimeImmutable('2026-07-01 10:00', new DateTimeZone('America/New_York')))]);
	Assert::same(['DAYLIGHT 20250309T020000 FREQ=YEARLY;BYMONTH=3;BYDAY=2SU', 'STANDARD 20251102T020000 FREQ=YEARLY;BYMONTH=11;BYDAY=1SU'], $observances($single));

	// a timezone changing its rules after the last date: the old rules get UNTIL, the new ones start
	$old = Calendar::create(events: [Event::new(start: new DateTimeImmutable('2005-07-01 10:00', new DateTimeZone('America/New_York')), rrule: 'FREQ=YEARLY')]);
	Assert::same([
		'DAYLIGHT 20040404T020000 FREQ=YEARLY;BYMONTH=4;BYDAY=1SU;UNTIL=20060402T070000Z',
		'STANDARD 20041031T020000 FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU;UNTIL=20061029T060000Z',
		'DAYLIGHT 20070311T020000 FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
		'STANDARD 20071104T020000 FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
	], $observances($old));
	Assert::same(10, VTimezoneBuilder::OPEN_YEARS);
});

test('Components of other types are rejected', function () {
	Assert::exception(fn() => Calendar::create(events: [Todo::new()]), InvalidArgumentException::class, 'Expected a VEVENT component, VTODO given.');
	Assert::exception(fn() => Calendar::create(todos: [new Component('VEVENT')]), InvalidArgumentException::class, 'Expected a VTODO component, VEVENT given.');
	Assert::exception(fn() => Calendar::create(components: [new Component('VCALENDAR')]), InvalidArgumentException::class, '~cannot be a component~');
	Assert::exception(fn() => Calendar::create(method: 'PUBLISH NOW'), InvalidArgumentException::class, '~Invalid METHOD~');
	Assert::exception(fn() => Calendar::create(properties: ['VERSION' => '3.0']), InvalidArgumentException::class, '~already set~');
});

test('Other properties and components of a calendar', function () {
	$freeBusy = new Component('VFREEBUSY', [Property::create('UID', 'fb'), Property::create('DTSTAMP', '20260101T000000Z')]);
	$calendar = Calendar::create(properties: ['REFRESH-INTERVAL' => new DateInterval('P1D'), 'SOURCE' => 'https://example.org/team.ics', 'CALSCALE' => 'GREGORIAN'], components: [$freeBusy]);
	Assert::same('P1D', ICal\Value\Duration::format($calendar->refreshInterval()));
	Assert::same('https://example.org/team.ics', $calendar->source());
	Assert::count(1, $calendar->freeBusy());
	Assert::same([], issues($calendar));
});

test('writeFile()', function () {
	$file = tempnam(sys_get_temp_dir(), 'ics');
	teamCalendar()->writeFile($file);
	Assert::same(teamCalendar()->serialize(), file_get_contents($file));
	unlink($file);
	Assert::exception(fn() => teamCalendar()->writeFile(__DIR__ . '/missing/dir/team.ics'), RuntimeException::class, '~Unable to write~');
});
