<?php
declare(strict_types=1);

/**
 * Typed values (RFC 5545, section 3.3).
 */

use om\ICal\Component;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\InvalidValueException;
use om\ICal\Exception\TimezoneResolutionException;
use om\ICal\Property;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeType;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use om\ICal\Value\Period;
use om\ICal\Value\Text;
use om\ICal\Value\UtcOffset;
use om\ICal\Value\ValueParser;
use om\RRule\Rule;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

function property(string $line): Property {
	return Property::fromContentLine(om\ICal\ContentLine::parse($line));
}

test('The four kinds of DATE and DATE-TIME values keep their meaning', function () {
	$date = DateTimeValue::parse('20261010', true);
	$floating = DateTimeValue::parse('20261010T100000');
	$utc = DateTimeValue::parse('20261010T100000Z');
	$zoned = DateTimeValue::parse('20261010T100000', false, 'Europe/Prague', new DateTimeZone('Europe/Prague'));

	Assert::same([DateTimeType::Date, DateTimeType::Floating, DateTimeType::Utc, DateTimeType::Zoned], [$date->type, $floating->type, $utc->type, $zoned->type]);
	Assert::same(['20261010', '20261010T100000', '20261010T100000Z', '20261010T100000'], array_map('strval', [$date, $floating, $utc, $zoned]));
	Assert::same('Europe/Prague', $zoned->tzid);
	Assert::true($date->isDate() && $floating->isFloating() && $utc->isUtc() && $zoned->isZoned());
	Assert::null($floating->timezone());
	Assert::same('UTC', $utc->timezone()->getName());
	Assert::same('2026-10-10 10:00', $floating->format('Y-m-d H:i'), 'local values are readable without a timezone');
	Assert::same('2026-10-10 08:00:00 UTC', $zoned->toDateTime(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T'));
	Assert::same('2026-10-10T00:00:00+02:00', DateTimeValue::parse('20261010', true)->toDateTime(new DateTimeZone('Europe/Prague'))->format('c'));
	Assert::true(DateTimeValue::parse('20261010T100000Z')->equals($utc));
	Assert::false($utc->equals($floating));
});

test('Floating times and dates become instants only with a timezone', function () {
	$floating = DateTimeValue::parse('20261010T100000');
	$exception = Assert::exception(fn() => $floating->toDateTime(), TimezoneResolutionException::class);
	Assert::same('timezone.floating', $exception->errorCode());
	Assert::same('2026-10-10T10:00:00-04:00', $floating->toDateTime(new DateTimeZone('America/New_York'))->format('c'));
	Assert::same('2026-10-10T10:00:00+02:00', $floating->toDateTime(null, new DateTimeZone('Europe/Prague'))->format('c'), 'default timezone');
});

test('A TZID that cannot be resolved keeps the value floating', function () {
	$value = DateTimeValue::parse('20261010T100000', false, 'Custom Zone');
	Assert::true($value->isFloating());
	Assert::same('Custom Zone', $value->tzid);
});

test('Invalid DATE-TIME values', function () {
	foreach (['2026-10-10', '20261310', '20260230', '20261010T250000', '20261010T100000X', '20261010T100000'] as $value) {
		Assert::exception(fn() => DateTimeValue::parse($value, $value === '20261010T100000'), InvalidValueException::class);
	}
	Assert::same('2026-10-10 23:59:59', DateTimeValue::parse('20261010T235960')->format('Y-m-d H:i:s'), 'a leap second is accepted');
});

test('Adding durations: days keep the local time, hours are elapsed time', function () {
	$zoned = DateTimeValue::parse('20260328T100000', false, 'Europe/Prague', new DateTimeZone('Europe/Prague'));
	Assert::same('2026-03-29 10:00 +02:00', $zoned->add(new DateInterval('P1D'))->format('Y-m-d H:i P'));
	Assert::same('2026-03-29 11:00 +02:00', $zoned->add(new DateInterval('PT24H'))->format('Y-m-d H:i P'));
	Assert::true(DateTimeValue::date(2026, 1, 31)->add(new DateInterval('P1D'))->isDate());
	Assert::same('20260201', (string) DateTimeValue::date(2026, 1, 31)->add(new DateInterval('P1D')));
	Assert::true(DateTimeValue::floating(new DateTime('2026-01-01 08:00'))->isFloating());
	Assert::true(DateTimeValue::fromDateTime(new DateTime('2026-01-01 08:00', new DateTimeZone('UTC')))->isUtc());
	Assert::same('America/New_York', DateTimeValue::fromDateTime(new DateTime('2026-01-01 08:00', new DateTimeZone('America/New_York')))->tzid);
});

test('DURATION values', function () {
	Assert::same('P1DT2H', Duration::format(Duration::parse('P1DT2H')));
	Assert::same('P15D', Duration::format(Duration::parse('P2W1D')));
	Assert::same('-PT15M', Duration::format(Duration::parse('-PT15M')));
	Assert::same('PT0S', Duration::format(Duration::parse('PT0S')));
	Assert::same('P1D', Duration::format(Duration::parse('+p1d')));
	Assert::same('P400D', Duration::format((new DateTime('2026-01-01'))->diff(new DateTime('2027-02-05'))));
	foreach (['P', 'PT', '1H', 'P1H', 'P1Y'] as $value) {
		Assert::null(Duration::parse($value), $value);
	}
});

test('TEXT escaping', function () {
	Assert::same("a\\b;c,d\ne", Text::unescape('a\\\\b\;c\,d\ne'));
	Assert::same('a\\\\b\;c\,d\ne', Text::escape("a\\b;c,d\ne"));
	Assert::same(['Work, Office', 'Home'], Text::split('Work\, Office,Home,'));
	Assert::same("Line\nNext", Text::unescape('Line\NNext'));
});

test('UTC-OFFSET values', function () {
	Assert::same(3600, UtcOffset::parse('+0100'));
	Assert::same(-19800, UtcOffset::parse('-0530'));
	Assert::same(3661, UtcOffset::parse('+010101'));
	Assert::same('+0100', UtcOffset::format(3600));
	Assert::same('-0530', UtcOffset::format(-19800));
	Assert::same('+010101', UtcOffset::format(3661));
	Assert::exception(fn() => UtcOffset::parse('0100'), InvalidValueException::class);
});

test('CAL-ADDRESS values', function () {
	$address = new CalAddress('mailto:jane@example.org', om\ICal\Parameters::parse('CN="Doe, Jane";ROLE=CHAIR;PARTSTAT=accepted;RSVP=TRUE'));
	Assert::same('jane@example.org', $address->email());
	Assert::same('Doe, Jane', $address->name());
	Assert::same(['CHAIR', 'ACCEPTED', 'INDIVIDUAL', true], [$address->role(), $address->status(), $address->type(), $address->rsvp()]);
	Assert::null((new CalAddress('urn:uuid:x', om\ICal\Parameters::parse('')))->email());
	Assert::same('REQ-PARTICIPANT', (new CalAddress('mailto:a@b', om\ICal\Parameters::parse('')))->role());
});

test('ValueParser converts values by their type', function () {
	$values = new ValueParser();
	Assert::type(DateTimeValue::class, $values->value(property('DTSTART:20261010T100000Z')));
	Assert::count(2, $values->value(property('EXDATE:20261010T100000Z,20261011T100000Z')));
	Assert::type(Period::class, $values->value(property('FREEBUSY:20261010T100000Z/PT1H'))[0]);
	Assert::same('20261010T110000Z', (string) $values->value(property('FREEBUSY:20261010T100000Z/PT1H'))[0]->end);
	Assert::same('20261010T100000Z/20261010T120000Z', (string) $values->value(property('FREEBUSY:20261010T100000Z/20261010T120000Z'))[0]);
	Assert::same('PT1H', Duration::format($values->value(property('DURATION:PT1H'))));
	Assert::same(3, $values->value(property('SEQUENCE:3')));
	Assert::same([50.08, 14.42], $values->value(property('GEO:50.08;14.42')));
	Assert::same(1.5, $values->value(property('X-NUM;VALUE=FLOAT:1.5')));
	Assert::true($values->value(property('X-FLAG;VALUE=BOOLEAN:TRUE')));
	Assert::type(Rule::class, $values->value(property('RRULE:FREQ=DAILY;COUNT=2')));
	Assert::type(CalAddress::class, $values->value(property('ORGANIZER:mailto:a@example.org')));
	Assert::same(-18000, $values->value(property('TZOFFSETTO:-0500')));
	Assert::same('hello', $values->value(property('ATTACH;ENCODING=BASE64;VALUE=BINARY:aGVsbG8=')));
	Assert::same('http://example.org', $values->value(property('URL:http://example.org')));
	Assert::same(['A', 'B'], $values->value(property('CATEGORIES:A,B')));
	Assert::same('a, b', $values->value(property('SUMMARY:a\, b')));
	Assert::same('X', $values->value(property('X-APPLE-STRUCTURED-LOCATION;VALUE=URI:X')));
	Assert::same('ACCEPTED', strtoupper((string) property('X-TEXT:ACCEPTED')->value));
	Assert::same('20131210', (string) $values->dateTime(property('RDATE:20131210Z')), 'a date with Z (Google) is a date');
});

test('Permissive conversion gives null for invalid values, strict throws', function () {
	$permissive = new ValueParser();
	$strict = new ValueParser(strict: true);
	$invalid = ['DTSTART:2026-10-10', 'DURATION:1H', 'SEQUENCE:x', 'GEO:1', 'X-B;VALUE=BOOLEAN:yes', 'X-F;VALUE=FLOAT:x', 'TZOFFSETTO:1', 'ATTACH;VALUE=BINARY:***', 'RRULE:FREQ=NEVER', 'RDATE:20261310Z'];
	foreach ($invalid as $line) {
		Assert::null($permissive->value(property($line)) ?: null, $line);
		Assert::exception(fn() => $strict->value(property($line)), InvalidValueException::class);
	}
	Assert::same([], $permissive->dateTimes(property('EXDATE:x,y')));
	Assert::same([], $permissive->periods(property('FREEBUSY:x/y')));
	$exception = Assert::exception(fn() => $strict->value(Property::fromContentLine(om\ICal\ContentLine::parse('RRULE:FREQ=DAILY;INTERVAL=0', 12))), InvalidRecurrenceRuleException::class);
	Assert::same(12, $exception->line());
	Assert::same('RRULE', $exception->property());
	Assert::same('FREQ=DAILY;INTERVAL=0', $exception->rawValue());
});

test('TZID parameters use the timezone resolver of the calendar', function () {
	$calendar = new Component('VCALENDAR');
	$values = new ValueParser($calendar);
	$value = $values->dateTime(property('DTSTART;TZID=W. Europe Standard Time:20261010T100000'));
	Assert::true($value->isZoned());
	Assert::same('Europe/Berlin', $value->timezone()->getName());
	Assert::same('W. Europe Standard Time', $value->tzid);
	Assert::true($values->dateTime(property('DTSTART;TZID=Nowhere/Nothing:20261010T100000'))->isFloating());
	Assert::null($values->timezone('Nowhere/Nothing'));
});

test('Default types of RFC 7986 properties', function () {
	$values = new ValueParser();
	Assert::same('https://example.com/a.png', $values->value(property('IMAGE:https://example.com/a.png')));
	Assert::same('https://example.com/a.png', $values->value(property('IMAGE;VALUE=URI;DISPLAY=BADGE:https://example.com/a.png')));
	Assert::same('hello', $values->value(property('IMAGE;ENCODING=BASE64;VALUE=BINARY;FMTTYPE=image/png:aGVsbG8=')));
	Assert::same('tel:+1-412-555-0123,,,654321', $values->value(property('CONFERENCE:tel:+1-412-555-0123,,,654321')));
	Assert::same('xmpp:chat-123@conference.example.com', $values->value(property('CONFERENCE;VALUE=URI;FEATURE=CHAT:xmpp:chat-123@conference.example.com')));
	Assert::same('https://example.com/holidays.ics', $values->value(property('SOURCE:https://example.com/holidays.ics')));
	Assert::same('https://example.com/holidays.ics', $values->value(property('SOURCE;VALUE=URI:https://example.com/holidays.ics')));
	Assert::same('P7D', Duration::format($values->value(property('REFRESH-INTERVAL:P1W'))));
	Assert::same('PT12H', Duration::format($values->value(property('REFRESH-INTERVAL;VALUE=DURATION:PT12H'))));
	Assert::same('turquoise', $values->value(property('COLOR:turquoise')), 'COLOR is TEXT');
	Assert::same('Holidays', $values->value(property('NAME:Holidays')), 'NAME is TEXT');
	Assert::same('P1W', $values->value(property('REFRESH-INTERVAL;VALUE=TEXT:P1W')), 'the VALUE parameter takes precedence');
});

test('ACKNOWLEDGED is a DATE-TIME (RFC 9074)', function () {
	$values = new ValueParser();
	$acknowledged = $values->value(property('ACKNOWLEDGED:20090604T084500Z'));
	Assert::type(DateTimeValue::class, $acknowledged);
	Assert::true($acknowledged->isUtc());
	Assert::true($values->value(property('ACKNOWLEDGED:20090604T084500'))->isFloating(), 'a local time is read, the Validator reports it');
	Assert::null($values->value(property('ACKNOWLEDGED:yesterday')));
	Assert::exception(fn() => (new ValueParser(strict: true))->value(property('ACKNOWLEDGED:yesterday')), InvalidValueException::class);
});

test('LINK, CONCEPT and REFID (RFC 9253)', function () {
	$values = new ValueParser();
	Assert::same('URI', ValueParser::type(property('LINK;LINKREL=SOURCE:https://example.com/events')));
	Assert::same('https://example.com/events', $values->value(property('LINK;LINKREL=SOURCE:https://example.com/events')));
	Assert::same('https://example.com/events', $values->value(property('LINK;LINKREL=SOURCE;VALUE=URI:https://example.com/events')));
	Assert::same('https://example.com/bid.xml#xpointer(descendant::CostStruc)', $values->value(property('LINK;LINKREL="https://example.com/linkrel/costStructure";VALUE=XML-REFERENCE:https://example.com/bid.xml#xpointer(descendant::CostStruc)')));
	Assert::same('event-1, part 2', $values->value(property('LINK;LINKREL=next;VALUE=UID:event-1\, part 2')), 'a UID is TEXT');
	Assert::same('see; also', $values->value(property('LINK;VALUE=TEXT:see\; also')));
	Assert::same('https://example.com/event-types/arts/music', $values->value(property('CONCEPT:https://example.com/event-types/arts/music')));
	Assert::same('TEXT', ValueParser::type(property('REFID:itinerary-2014-11-17')));
	Assert::same('itinerary-2014-11-17', $values->value(property('REFID:itinerary-2014-11-17')));
});

test('Invalid values of RFC 7986 properties', function () {
	$values = new ValueParser();
	$strict = new ValueParser(strict: true);
	foreach (['REFRESH-INTERVAL:weekly', 'REFRESH-INTERVAL;VALUE=DURATION:1W', 'IMAGE;ENCODING=BASE64;VALUE=BINARY:***'] as $line) {
		Assert::null($values->value(property($line)), $line);
		Assert::same('value.invalid', $values->diagnose(property($line))[0][0] ?? null, $line);
		Assert::exception(fn() => $strict->value(property($line)), InvalidValueException::class);
	}
});
