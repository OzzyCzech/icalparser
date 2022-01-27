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

test('Weird windows timezones', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/weird_windows_timezones.ics');
	$cal->getEvents()->sorted();
	Assert::same('Atlantic/Reykjavik', $cal->timezone->getName());
});
