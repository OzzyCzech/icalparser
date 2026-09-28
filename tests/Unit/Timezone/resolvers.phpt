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

test('A definition with RDATE transitions', function () {
	$calendar = calendarWith(implode("\r\n", [
		'BEGIN:VTIMEZONE', 'TZID:Rdates',
		'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'RDATE:20241027T030000,20251026T030000,20261025T030000,20271031T030000', 'END:STANDARD',
		'BEGIN:DAYLIGHT', 'DTSTART:19700329T020000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'RDATE:20240331T020000,20250330T020000,20260329T020000,20270328T020000', 'END:DAYLIGHT',
		'END:VTIMEZONE',
	]));
	$resolved = (new VTimezoneResolver(reference: new DateTimeImmutable('2026-06-01')))->resolve('Rdates', $calendar);
	Assert::same(7200, (new DateTimeImmutable('2026-07-01', $resolved->timezone))->getOffset());
	Assert::same(3600, (new DateTimeImmutable('2026-12-01', $resolved->timezone))->getOffset());
});

test('UNTIL of an observance is in UTC, also east of UTC (#110)', function () {
	// Moscow 2008-2013 under a name the IANA resolver does not know
	$calendar = calendarWith(implode("\r\n", [
		'BEGIN:VTIMEZONE', 'TZID:Custom/Moscow',
		'BEGIN:STANDARD', 'DTSTART:19961027T030000', 'TZOFFSETFROM:+0400', 'TZOFFSETTO:+0300', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU;UNTIL=20101030T230000Z', 'END:STANDARD',
		'BEGIN:DAYLIGHT', 'DTSTART:19930328T020000', 'TZOFFSETFROM:+0300', 'TZOFFSETTO:+0400', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU;UNTIL=20100327T230000Z', 'END:DAYLIGHT',
		'BEGIN:STANDARD', 'DTSTART:20110327T020000', 'TZOFFSETFROM:+0300', 'TZOFFSETTO:+0400', 'END:STANDARD',
		'BEGIN:STANDARD', 'DTSTART:20141026T020000', 'TZOFFSETFROM:+0400', 'TZOFFSETTO:+0300', 'END:STANDARD',
		'END:VTIMEZONE',
	]));
	foreach (['2010-06-01', '2011-06-01'] as $reference) {
		$resolved = (new VTimezoneResolver(reference: new DateTimeImmutable($reference)))->resolve('Custom/Moscow', $calendar);
		Assert::notNull($resolved, $reference);
		Assert::same(TimezoneSource::VTimezone, $resolved->source);
		foreach (['2010-03-27 12:00' => 10800, '2010-07-01' => 14400, '2010-12-01' => 10800, '2011-07-01' => 14400, '2012-01-01' => 14400] as $date => $offset) {
			Assert::same($offset, (new DateTimeImmutable($date, $resolved->timezone))->getOffset(), "$reference $date");
		}
	}
});

test('UNTIL of an observance is in UTC, west of UTC no transition after it is kept (#110)', function () {
	// daylight time until 2009: 2010-03-14 02:00 local is 07:00 UTC, after UNTIL
	$calendar = calendarWith(implode("\r\n", [
		'BEGIN:VTIMEZONE', 'TZID:Custom/Eastern',
		'BEGIN:STANDARD', 'DTSTART:20071104T020000', 'TZOFFSETFROM:-0400', 'TZOFFSETTO:-0500', 'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU', 'END:STANDARD',
		'BEGIN:DAYLIGHT', 'DTSTART:20070311T020000', 'TZOFFSETFROM:-0500', 'TZOFFSETTO:-0400', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU;UNTIL=20100314T030000Z', 'END:DAYLIGHT',
		'END:VTIMEZONE',
	]));
	$resolved = (new VTimezoneResolver(reference: new DateTimeImmutable('2009-06-01')))->resolve('Custom/Eastern', $calendar);
	Assert::null($resolved); // not America/New_York, which has daylight time in 2010
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
