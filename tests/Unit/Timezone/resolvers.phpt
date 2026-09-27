<?php
declare(strict_types=1);

/**
 * Timezone resolution: VTIMEZONE definitions, IANA names, aliases and fallback.
 */

use om\ICal;
use om\ICal\Component;
use om\ICal\Timezone\AliasTimezoneResolver;
use om\ICal\Timezone\CompositeTimezoneResolver;
use om\ICal\Timezone\FallbackTimezoneResolver;
use om\ICal\Timezone\IanaTimezoneResolver;
use om\ICal\Timezone\TimezoneSource;
use om\ICal\Timezone\VTimezoneResolver;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * A VTIMEZONE like Outlook writes it, with rules from 1601.
 */
function outlookTimezone(string $tzid, string $standard = '+0100', string $daylight = '+0200', string $extra = ''): string {
	return implode("\r\n", [
		'BEGIN:VTIMEZONE',
		"TZID:$tzid",
		...($extra === '' ? [] : [$extra]),
		'BEGIN:STANDARD',
		'DTSTART:16010101T030000',
		"TZOFFSETFROM:$daylight",
		"TZOFFSETTO:$standard",
		'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10',
		'END:STANDARD',
		'BEGIN:DAYLIGHT',
		'DTSTART:16010101T020000',
		"TZOFFSETFROM:$standard",
		"TZOFFSETTO:$daylight",
		'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3',
		'END:DAYLIGHT',
		'END:VTIMEZONE',
	]);
}

function calendarWith(string ...$components): Component {
	return ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n" . implode("\r\n", $components) . "\r\nEND:VCALENDAR\r\n")->component;
}

test('IANA names, case-insensitive', function () {
	$resolver = new IanaTimezoneResolver();
	$calendar = new Component('VCALENDAR');
	Assert::same('Europe/Prague', $resolver->resolve('europe/prague', $calendar)->timezone->getName());
	Assert::same(TimezoneSource::Iana, $resolver->resolve('UTC', $calendar)->source);
	Assert::null($resolver->resolve('W. Europe Standard Time', $calendar));
	Assert::null($resolver->resolve('EST5EDT-invalid', $calendar));
});

test('Windows names, Outlook display names, prefixed and shortened IANA names', function () {
	$resolver = new AliasTimezoneResolver();
	$calendar = new Component('VCALENDAR');
	$cases = [
		'W. Europe Standard Time' => 'Europe/Berlin',
		'Central Europe Standard Time' => 'Europe/Budapest',
		'(UTC-08:00) Pacific Time (US & Canada)' => 'America/Los_Angeles',
		'/mozilla.org/20070129_1/Europe/Paris' => 'Europe/Paris',
		'"America/Argentina/Buenos_Aires"' => 'America/Argentina/Buenos_Aires',
		'Argentina/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
	];
	foreach ($cases as $tzid => $expected) {
		$resolved = $resolver->resolve($tzid, $calendar);
		Assert::same($expected, $resolved?->timezone->getName(), $tzid);
		Assert::same(TimezoneSource::Alias, $resolved->source);
	}
	Assert::null($resolver->resolve('Nowhere Standard Time', $calendar));
	Assert::null($resolver->resolve('/x', $calendar), 'too short for the suffix search');
	Assert::same('Europe/Prague', (new AliasTimezoneResolver(['Custom' => 'Europe/Prague']))->resolve('Custom', $calendar)->timezone->getName());
});

test('A custom VTIMEZONE (Outlook "Customized Time Zone") becomes the matching IANA timezone', function () {
	$calendar = calendarWith(outlookTimezone('Customized Time Zone'));
	$resolved = (new VTimezoneResolver())->resolve('Customized Time Zone', $calendar);
	Assert::same(TimezoneSource::VTimezone, $resolved->source);
	$offsets = [
		(new DateTimeImmutable('2026-01-15', $resolved->timezone))->getOffset(),
		(new DateTimeImmutable('2026-07-15', $resolved->timezone))->getOffset(),
	];
	Assert::same([3600, 7200], $offsets);
	Assert::null((new VTimezoneResolver())->resolve('Not Defined', $calendar));
});

test('The TZID and X-LIC-LOCATION are preferred among matching timezones', function () {
	$resolver = new VTimezoneResolver();
	$calendar = calendarWith(outlookTimezone('Europe/Prague'), outlookTimezone('My zone', extra: 'X-LIC-LOCATION:Europe/Vienna'));
	Assert::same('Europe/Prague', $resolver->resolve('Europe/Prague', $calendar)->timezone->getName());
	Assert::same('Europe/Vienna', $resolver->resolve('My zone', $calendar)->timezone->getName());
});

test('A definition without transitions is a fixed offset', function () {
	$calendar = calendarWith("BEGIN:VTIMEZONE\r\nTZID:India\r\nBEGIN:STANDARD\r\nDTSTART:16010101T000000\r\nTZOFFSETFROM:+0530\r\nTZOFFSETTO:+0530\r\nEND:STANDARD\r\nEND:VTIMEZONE");
	Assert::same('+05:30', (new VTimezoneResolver())->resolve('India', $calendar)->timezone->getName());
});

test('Invalid definitions are not resolved', function () {
	$calendar = calendarWith("BEGIN:VTIMEZONE\r\nTZID:Broken\r\nBEGIN:STANDARD\r\nDTSTART:x\r\nTZOFFSETFROM:+01\r\nTZOFFSETTO:bad\r\nEND:STANDARD\r\nEND:VTIMEZONE");
	Assert::null((new VTimezoneResolver())->resolve('Broken', $calendar));
});

test('The default resolver asks VTIMEZONE, IANA, aliases and the fallback in this order', function () {
	$calendar = calendarWith(outlookTimezone('Customized Time Zone'), outlookTimezone('Impossible', '-0500', '-0400'));
	$default = CompositeTimezoneResolver::default();
	Assert::same(TimezoneSource::VTimezone, $default->resolve('Customized Time Zone', $calendar)->source);
	Assert::null($default->resolve('Impossible', $calendar), 'no IANA timezone has these rules');
	Assert::same(TimezoneSource::Iana, $default->resolve('Asia/Tokyo', $calendar)->source);
	Assert::same(TimezoneSource::Alias, $default->resolve('Tokyo Standard Time', $calendar)->source);
	Assert::null($default->resolve('Unknown', $calendar));

	$withFallback = CompositeTimezoneResolver::default(new DateTimeZone('Europe/Prague'));
	Assert::same(TimezoneSource::Fallback, $withFallback->resolve('Impossible', $calendar)->source);
	$resolved = $withFallback->resolve('Unknown', $calendar);
	Assert::same(TimezoneSource::Fallback, $resolved->source);
	Assert::same('Europe/Prague', $resolved->timezone->getName());
	Assert::same('UTC', (new FallbackTimezoneResolver(new DateTimeZone('UTC')))->resolve('x', $calendar)->timezone->getName());
});

test('Events use the resolved timezone', function () {
	$calendar = ICal::parse("BEGIN:VCALENDAR\r\n" . outlookTimezone('Customized Time Zone') . "\r\nBEGIN:VEVENT\r\nUID:1\r\nDTSTART;TZID=Customized Time Zone:20260710T100000\r\nEND:VEVENT\r\nEND:VCALENDAR");
	$start = $calendar->events()[0]->start();
	Assert::true($start->isZoned());
	Assert::same('2026-07-10T08:00:00+00:00', $start->toDateTime(new DateTimeZone('UTC'))->format('c'));
	Assert::same('Customized Time Zone', $start->tzid);
	Assert::same(TimezoneSource::VTimezone, $calendar->timezones()[0]->resolve()->source);
	Assert::count(2, $calendar->timezones()[0]->observances());
});
