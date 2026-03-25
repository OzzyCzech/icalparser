<?php
/**
 * @author Marc Vachette <marc.vachette@gmail.com>
 */

use om\IcalParser;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Europe/Paris');

test('Normal time zone', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/blank_description.ics');
	Assert::same('America/Los_Angeles', $cal->timezone->getName());
});

test('Negative zero UTC timezone', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/utc_negative_zero.ics');
	Assert::same('Etc/GMT', $cal->timezone->getName());
});

/**
 * Time zone with custom prefixes (Mozilla files tken from here: https://www.mozilla.org/en-US/projects/calendar/holidays/)
 */
test('Time zone with custom prefixes', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/FrenchHolidays.ics');
	Assert::same('Europe/Paris', $cal->timezone->getName());
});

test('Multi-segment IANA timezones', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/multi_segment_timezone.ics');
	Assert::same('America/Argentina/Buenos_Aires', $cal->timezone->getName());
	$events = $cal->getEvents()->sorted();
	Assert::count(2, $events);
	// Argentina/Buenos_Aires is invalid but must not throw (issue #72)
	Assert::noError(fn() => $cal->getEvents());
});

test('All IANA timezones', function () {
	$template = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
X-WR-TIMEZONE:%s
BEGIN:VEVENT
DTSTART;TZID=%s:20240101T120000
DTEND;TZID=%s:20240101T130000
SUMMARY:Test
END:VEVENT
END:VCALENDAR
ICS;

	$now = new DateTime();
	foreach (DateTimeZone::listIdentifiers() as $tz) {
		$cal = new IcalParser();
		$cal->parseString(sprintf($template, $tz, $tz, $tz));
		Assert::notNull($cal->timezone, "Timezone not set for: $tz");
		$expected = (new DateTimeZone($tz))->getOffset($now);
		$actual = $cal->timezone->getOffset($now);
		Assert::same($expected, $actual, "Offset mismatch for timezone: $tz (got {$cal->timezone->getName()})");
	}
});

test('Weird windows timezones', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/weird_windows_timezones.ics');
	$cal->getEvents()->sorted();
	Assert::same('Atlantic/Reykjavik', $cal->timezone->getName());
});
