<?php
declare(strict_types=1);

/**
 * The sample calendars of ical.js (tests/Fixtures/Samples) read with the new API:
 * properties, timezones and recurring events.
 */

use om\ICal;
use om\ICal\Calendar;
use om\ICal\Occurrence;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('Europe/Prague');

function sample(string $name): Calendar {
	return ICal::parseFile(__DIR__ . "/../Fixtures/Samples/$name.ics");
}

/**
 * @return list<string>
 */
function occurrences(string $name, string $from = '2000-01-01', string $to = '2030-01-01', string $format = 'j.n.Y H:i:s'): array {
	return array_map(
		fn(Occurrence $occurrence) => $occurrence->start->format($format),
		sample($name)->occurrencesBetween(new DateTimeImmutable($from), new DateTimeImmutable($to)),
	);
}

test('Several ATTACH properties', function () {
	$event = sample('multiple_attachments')->events()[0];
	Assert::count(2, $event->properties('ATTACH'));
	Assert::type('string', $event->value('ATTACH'));
});

test('CATEGORIES', function () {
	foreach (sample('multiple_categories')->events() as $event) {
		Assert::same(['one', 'two', 'three'], $event->categories());
	}
});

test('Invalid dates are ignored', function () {
	[$first, $second] = sample('wrong_dates')->events();
	Assert::null($first->start());
	Assert::same('20140930', (string) $first->property('DTEND')?->value);
	Assert::same('29.9.2014', $second->start()->format('j.n.Y'));
});

test('Blank and multi-line DESCRIPTION', function () {
	Assert::same('', sample('blank_description')->events()[0]->description());
	$event = sample('multiline_description')->events()[0];
	Assert::same('30.6.2012 06:00:00', $event->start()->format('j.n.Y H:i:s'));
	Assert::same("Here is a description that spans multiple lines!\n\nThis should be on a new line as well because the description contains newline characters.", $event->description());
});

test('URL', function () {
	Assert::same(urlencode('https://github.com/OzzyCzech/icalparser/'), sample('url')->events()[0]->url());
});

test('Dates without a calendar timezone stay dates', function () {
	$calendar = sample('missing-timezone');
	Assert::null($calendar->timezone());
	$start = $calendar->events()[0]->start();
	Assert::true($start->isDate());
	Assert::same('12.4.2022', $start->format('j.n.Y'));
});

test('Events sorted by date', function () {
	$starts = occurrences('basic');
	Assert::count(38, $starts);
	Assert::same('1.1.2013 00:00:00', $starts[0]);
	Assert::same('26.12.2015 00:00:00', end($starts));
});

test('Timezones: X-WR-TIMEZONE, prefixes, multi-segment IANA names, Windows names, UTC', function () {
	Assert::same('America/Los_Angeles', sample('blank_description')->timezone()->getName());
	Assert::same('America/Argentina/Buenos_Aires', sample('multi_segment_timezone')->timezone()->getName());
	Assert::same(['America/Indiana/Indianapolis', 'America/Argentina/Buenos_Aires'], array_map(fn($event) => $event->start()->timezone()->getName(), sample('multi_segment_timezone')->events()), 'Argentina/Buenos_Aires is resolved too (issue #72)');

	$resolved = fn(string $name) => array_map(fn($definition) => $definition->tzid() . ' => ' . $definition->resolve()?->timezone->getName(), sample($name)->timezones());
	Assert::same(['/mozilla.org/20070129_1/Europe/Paris => Europe/Paris'], $resolved('FrenchHolidays'));
	Assert::same(['Etc/GMT => Etc/GMT'], $resolved('utc_negative_zero'));
	Assert::contains('Greenwich Standard Time => Atlantic/Reykjavik', $resolved('weird_windows_timezones'));
	Assert::contains('(UTC-06:00) Central Time (US & Canada) => America/Chicago', $resolved('weird_windows_timezones'));
});

test('Every IANA timezone is resolved with its offset', function () {
	$now = new DateTimeImmutable();
	foreach (DateTimeZone::listIdentifiers() as $timezone) {
		$calendar = ICal::parse("BEGIN:VCALENDAR\r\nX-WR-TIMEZONE:$timezone\r\nBEGIN:VEVENT\r\nDTSTART;TZID=$timezone:20240101T120000\r\nEND:VEVENT\r\nEND:VCALENDAR");
		Assert::same((new DateTimeZone($timezone))->getOffset($now), $calendar->timezone()?->getOffset($now), $timezone);
		Assert::true($calendar->events()[0]->start()->isZoned(), $timezone);
	}
});

test('Recurring events with RDATE, EXDATE and overrides', function () {
	Assert::same(['2.10.2012 10:00:00', '5.11.2012 10:00:00', '6.11.2012 10:00:00', '10.11.2012 10:00:00', '4.12.2012 10:00:00'], occurrences('recur_instances_finite'));

	$modified = sample('recur_instances_with_modifications')->occurrencesBetween(new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2030-01-01'));
	Assert::count(36, $modified, '35 instances and the modified one');
	Assert::same(['8.8.2016'], array_values(array_map(fn(Occurrence $o) => $o->start->format('j.n.Y'), array_filter($modified, fn(Occurrence $o) => $o->isModified()))));

	Assert::count(89, occurrences('recur_instances_with_modifications_and_interval'));
	Assert::same(['29.9.2016 12:30:00'], occurrences('recur_instances_with_modifications_to_first_day'), 'the modified first instance replaces it');
});

test('Daily, weekly and bi-weekly series', function () {
	$daily = occurrences('daily_recur', '2012-08-01', '2015-08-01T06:00');
	$expected = array_map(fn(DateTimeInterface $day) => $day->format('j.n.Y H:i:s'), iterator_to_array(new DatePeriod(new DateTime('20120801T050000'), new DateInterval('P1D'), new DateTime('20150801T050000'))));
	Assert::same($expected, $daily);

	Assert::same(['21.8.2017 00:00:00', '28.8.2017 00:00:00', '4.9.2017 00:00:00', '11.9.2017 00:00:00'], occurrences('daily_recur2'));
	Assert::same(['31.1.2023 05:00:00', '14.2.2023 05:00:00', '28.2.2023 05:00:00'], occurrences('rrule_interval'), 'bi-weekly (issue #61)');
	Assert::count(3, occurrences('recurring_utc_z'), 'issue #82');
});

test('Twice weekly (issue #75) with their end', function () {
	$occurrences = sample('twice_weekly')->occurrencesBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'));
	Assert::same(
		['1.1. 10-11', '6.1. 10-11', '8.1. 10-11', '13.1. 10-11', '15.1. 10-11', '20.1. 10-11', '22.1. 10-11', '27.1. 10-11', '29.1. 10-11'],
		array_map(fn(Occurrence $o) => $o->start->format('j.n. G') . '-' . $o->end->format('G'), $occurrences),
	);
});

test('Weekly on weekdays does not miss a day (issue #38)', function () {
	$starts = occurrences('38_weekly_recurring_event_missing_day', '2019-02-25', '2019-04-20', 'j.n.');
	$expected = [];
	foreach (new DatePeriod(new DateTime('2019-02-25'), new DateInterval('P1D'), new DateTime('2019-04-20')) as $day) {
		if ($day->format('N') < 6) {
			$expected[] = $day->format('j.n.');
		}
	}
	Assert::same($expected, $starts);
});

test('A weekly series in Denver is at the same instant in UTC (issue #84)', function () {
	date_default_timezone_set('UTC');
	$calendar = ICal::parse(implode("\r\n", ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'UID:denver', 'DTSTART;TZID=America/Denver:20260613T000000', 'DTEND;TZID=America/Denver:20260614T000000', 'RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=SA', 'END:VEVENT', 'END:VCALENDAR']));
	$first = $calendar->events()[0]->occurrences(1)->current();
	Assert::same('0600', $first->startTime(new DateTimeZone('UTC'))->format('Hi'));
	Assert::same('UTC', date_default_timezone_get());
});
