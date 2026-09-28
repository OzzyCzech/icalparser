<?php
declare(strict_types=1);

/**
 * Create a calendar with a recurring event, a task and alarms and write it as iCalendar data.
 *
 * Usage: php examples/create.php > team.ics
 *        php -S localhost:8000 examples/create.php (shown as plain text)
 */

use om\ICal\Alarm;
use om\ICal\Calendar;
use om\ICal\Event;
use om\ICal\Todo;
use om\ICal\Validation\Validator;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;

require_once __DIR__ . '/../vendor/autoload.php';

$prague = new DateTimeZone('Europe/Prague');

$calendar = Calendar::create('-//example//standup//EN', name: 'Team A', events: [
	Event::new(
		uid: 'standup@example.org',
		summary: 'Standup, team A',
		start: new DateTimeImmutable('2026-01-05 09:30', $prague),
		duration: new DateInterval('PT15M'),
		rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
		exdates: [new DateTimeImmutable('2026-04-06 09:30', $prague)],
		organizer: CalAddress::create('mailto:boss@example.org', name: 'Boss'),
		attendees: [CalAddress::create('mailto:a@example.org', name: 'A', rsvp: true)],
		alarms: [Alarm::display('Standup in 5 minutes', trigger: '-PT5M')],
		properties: ['X-EXAMPLE' => 'custom properties are kept'],
	),
	Event::new(summary: 'Offsite', start: DateTimeValue::date(2026, 10, 22), end: DateTimeValue::date(2026, 10, 24), transparency: 'TRANSPARENT'),
], todos: [
	Todo::new(summary: 'Quarterly report', due: new DateTimeImmutable('2026-03-31 17:00', $prague), priority: 1),
]);

foreach ((new Validator())->validate($calendar) as $issue) {
	error_log((string) $issue); // there are none
}

if (PHP_SAPI !== 'cli') {
	// shown in the browser; serve calendars as "text/calendar; charset=utf-8"
	header('Content-Type: text/plain; charset=utf-8');
}
echo $calendar->serialize(); // with a VTIMEZONE for Europe/Prague
