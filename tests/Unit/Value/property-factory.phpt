<?php
declare(strict_types=1);

/**
 * Conversion of PHP values to properties (PropertyFactory), the reverse of ValueParser.
 */

use om\ICal\Parameters;
use om\ICal\Property;
use om\ICal\Value\CalAddress;
use om\ICal\Value\Conference;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use om\ICal\Value\Image;
use om\ICal\Value\Link;
use om\ICal\Value\Period;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Relation;
use om\ICal\Value\ValueParser;
use om\RRule\Rule;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

function line(Property $property): string {
	return (string) $property;
}

test('A DateTimeInterface with a named timezone keeps its local time and gets a TZID', function () {
	$prague = new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('Europe/Prague'));
	Assert::same('DTSTART;TZID=Europe/Prague:20260105T093000', line(PropertyFactory::dateTime('DTSTART', $prague)));
	Assert::same('DTSTART;TZID=US/Eastern:20260105T093000', line(PropertyFactory::dateTime('dtstart', new DateTime('2026-01-05 09:30', new DateTimeZone('US/Eastern')))));
});

test('UTC is written with Z, offsets and abbreviations are converted to UTC', function () {
	Assert::same('DTSTART:20260105T093000Z', line(PropertyFactory::dateTime('DTSTART', new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('UTC')))));
	Assert::same('DTSTART:20260105T093000Z', line(PropertyFactory::dateTime('DTSTART', new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('Z')))));
	Assert::same('DTSTART:20260705T073000Z', line(PropertyFactory::dateTime('DTSTART', new DateTimeImmutable('2026-07-05 09:30+02:00'))));
	Assert::same('DTSTART:20260705T073000Z', line(PropertyFactory::dateTime('DTSTART', new DateTimeImmutable('2026-07-05 09:30 CEST'))));
	Assert::same('DTSTART:20260705T093000Z', line(PropertyFactory::dateTime('DTSTART', new DateTimeImmutable('2026-07-05 09:30 GMT'))));
});

test('DateTimeValue keeps DATE, floating, UTC and zoned values', function () {
	Assert::same('DTSTART;VALUE=DATE:20260105', line(PropertyFactory::dateTime('DTSTART', DateTimeValue::date(2026, 1, 5))));
	Assert::same('DTSTART:20260105T093000', line(PropertyFactory::dateTime('DTSTART', DateTimeValue::floating(new DateTimeImmutable('2026-01-05 09:30')))));
	Assert::same('DTSTART:20260105T093000', line(PropertyFactory::dateTime('DTSTART', DateTimeValue::parse('20260105T093000', false, 'Unknown Zone'))), 'an unresolved TZID is not written');
	Assert::same('DTSTART:20260105T093000Z', line(PropertyFactory::dateTime('DTSTART', DateTimeValue::parse('20260105T093000Z'))));
	$zoned = DateTimeValue::parse('20260105T093000', false, 'Europe/Prague', new DateTimeZone('Europe/Prague'));
	Assert::same('DTSTART;TZID=Europe/Prague:20260105T093000', line(PropertyFactory::dateTime('DTSTART', $zoned)));
	$windows = DateTimeValue::parse('20260105T093000', false, 'Central Europe Standard Time', new DateTimeZone('Europe/Prague'));
	Assert::same('DTSTART;TZID=Europe/Prague:20260105T093000', line(PropertyFactory::dateTime('DTSTART', $windows)), 'the TZID names an IANA timezone');
});

test('iCalendar strings are validated', function () {
	Assert::same('DTSTART;VALUE=DATE:20260105', line(PropertyFactory::dateTime('DTSTART', '20260105')));
	Assert::same('DTSTART:20260105T093000', line(PropertyFactory::dateTime('DTSTART', '20260105T093000')));
	Assert::same('DTSTART:20260105T093000Z', line(PropertyFactory::dateTime('DTSTART', '20260105T093000Z')));
	Assert::exception(fn() => PropertyFactory::dateTime('DTSTART', '2026-01-05'), InvalidArgumentException::class);
	Assert::exception(fn() => PropertyFactory::dateTime('DTSTART', '20261305'), InvalidArgumentException::class);
});

test('DTSTAMP, CREATED, LAST-MODIFIED and COMPLETED are UTC', function () {
	Assert::same('DTSTAMP:20260705T073000Z', line(PropertyFactory::dateTime('DTSTAMP', new DateTimeImmutable('2026-07-05 09:30', new DateTimeZone('Europe/Prague')))));
	Assert::same('COMPLETED:20260705T073000Z', line(PropertyFactory::dateTime('COMPLETED', DateTimeValue::parse('20260705T093000', false, 'Europe/Prague', new DateTimeZone('Europe/Prague')))));
	Assert::exception(fn() => PropertyFactory::dateTime('DTSTAMP', '20260105'), InvalidArgumentException::class, '~must be an instant~');
	Assert::exception(fn() => PropertyFactory::dateTime('CREATED', '20260105T093000'), InvalidArgumentException::class, '~must be an instant~');
});

test('EXDATE and RDATE values of the same kind share a property', function () {
	$prague = new DateTimeZone('Europe/Prague');
	$properties = PropertyFactory::dateTimes('EXDATE', [
		new DateTimeImmutable('2026-01-07 09:30', $prague),
		new DateTimeImmutable('2026-01-09 09:30', $prague),
		'20260112T083000Z',
		DateTimeValue::date(2026, 1, 14),
	]);
	Assert::same([
		'EXDATE;TZID=Europe/Prague:20260107T093000,20260109T093000',
		'EXDATE:20260112T083000Z',
		'EXDATE;VALUE=DATE:20260114',
	], array_map(line(...), $properties));

	$start = DateTimeValue::fromDateTime(new DateTimeImmutable('2026-01-07 09:30', $prague));
	$periods = PropertyFactory::dateTimes('RDATE', [
		new Period($start, $start->add(new DateInterval('PT2H'))),
		new Period($start, $start->add(new DateInterval('PT2H')), new DateInterval('PT2H')),
	]);
	Assert::same(['RDATE;TZID=Europe/Prague;VALUE=PERIOD:20260107T093000/20260107T113000,20260107T093000/PT2H'], array_map(line(...), $periods));
	$values = new ValueParser();
	Assert::same('2026-01-07 11:30', $values->periods($periods[0])[0]->end->format('Y-m-d H:i'));
});

test('DURATION: DateInterval or a string, negative values, no months', function () {
	Assert::same('PT15M', PropertyFactory::duration(new DateInterval('PT15M')));
	Assert::same('P1DT2H', PropertyFactory::duration(new DateInterval('P1DT2H')));
	Assert::same('-PT5M', PropertyFactory::duration('-pt5m'));
	Assert::same('P1W', PropertyFactory::duration('P1W'));
	$negative = new DateInterval('PT5M');
	$negative->invert = 1;
	Assert::same('-PT5M', PropertyFactory::duration($negative));
	Assert::same('-PT5M', Duration::format(Duration::parse('-PT5M')), 'Duration::format() keeps the sign');
	Assert::same('P31D', PropertyFactory::duration((new DateTimeImmutable('2026-01-01'))->diff(new DateTimeImmutable('2026-02-01'))), 'months of diff() are days');
	Assert::exception(fn() => PropertyFactory::duration(new DateInterval('P1M')), InvalidArgumentException::class, '~months or years~');
	Assert::exception(fn() => PropertyFactory::duration('P1M'), InvalidArgumentException::class);
	Assert::exception(fn() => PropertyFactory::duration('15 minutes'), InvalidArgumentException::class);
});

test('RRULE: Rule or a string, validated', function () {
	Assert::same('FREQ=WEEKLY;BYDAY=MO,WE,FR', PropertyFactory::rule('freq=weekly;byday=MO,WE,FR')->toString());
	Assert::same('FREQ=DAILY;COUNT=3', PropertyFactory::rule(Rule::fromString('FREQ=DAILY;COUNT=3'))->toString());
	Assert::exception(fn() => PropertyFactory::rule('FREQ=SOMETIMES'), InvalidArgumentException::class);
	Assert::exception(fn() => PropertyFactory::rule('FREQ=DAILY;BYHOUR=25'), InvalidArgumentException::class);
});

test('TEXT is escaped, list items are escaped one by one', function () {
	Assert::same('SUMMARY:Standup\, team A\; room \\\\1\nsecond line', line(PropertyFactory::text('SUMMARY', "Standup, team A; room \\1\nsecond line")));
	$categories = PropertyFactory::texts('CATEGORIES', ['Work, office', 'Meeting']);
	Assert::same('CATEGORIES:Work\, office,Meeting', line($categories));
	Assert::same(['Work, office', 'Meeting'], (new ValueParser())->texts($categories));
	Assert::null(PropertyFactory::texts('CATEGORIES', []));
});

test('URIs, calendar user addresses and GEO', function () {
	Assert::same('URL:https://example.org/a', line(PropertyFactory::uri('URL', ' https://example.org/a ')));
	Assert::exception(fn() => PropertyFactory::uri('URL', 'https://example.org/a b'), InvalidArgumentException::class);
	Assert::same('ATTENDEE;CN="Doe, John";RSVP=TRUE:mailto:john@example.org', line(PropertyFactory::calAddress('ATTENDEE', CalAddress::create('mailto:john@example.org', name: 'Doe, John', rsvp: true))));
	Assert::same('ORGANIZER:mailto:boss@example.org', line(PropertyFactory::calAddress('ORGANIZER', 'boss@example.org')));
	Assert::same('GEO:50.087;14.4208', line(PropertyFactory::geo([50.087, 14.4208])));
	Assert::same('GEO:-33.8688;151.209301', line(PropertyFactory::geo([-33.8688, 151.2093014])));
	Assert::exception(fn() => PropertyFactory::geo([91.0, 0.0]), InvalidArgumentException::class);
});

test('IMAGE, CONFERENCE, LINK and RELATED-TO keep their parameters', function () {
	Assert::same('IMAGE;VALUE=URI:https://example.org/a.png', line(PropertyFactory::image('https://example.org/a.png')));
	Assert::same('IMAGE;DISPLAY=BADGE;VALUE=URI:https://example.org/a.png', line(PropertyFactory::image(new Image('https://example.org/a.png', null, Parameters::from(['DISPLAY' => 'BADGE'])))));
	Assert::same('IMAGE;FMTTYPE=image/png;VALUE=BINARY;ENCODING=BASE64:iVBORw==', line(PropertyFactory::image(new Image(null, base64_decode('iVBORw=='), Parameters::from(['FMTTYPE' => 'image/png'])))));
	Assert::same('CONFERENCE;VALUE=URI:https://meet.example.org/1', line(PropertyFactory::conference('https://meet.example.org/1')));
	Assert::same('CONFERENCE;FEATURE=AUDIO,VIDEO;LABEL=Join;VALUE=URI:https://meet.example.org/1', line(PropertyFactory::conference(new Conference('https://meet.example.org/1', Parameters::from(['FEATURE' => ['AUDIO', 'VIDEO'], 'LABEL' => 'Join'])))));
	Assert::same('LINK:https://example.org/doc', line(PropertyFactory::link('https://example.org/doc')));
	Assert::same('LINK;VALUE=UID;LINKREL=next:uid\,1', line(PropertyFactory::link(new Link('uid,1', Parameters::from(['VALUE' => 'UID', 'LINKREL' => 'next'])))));
	Assert::same('RELATED-TO:parent\,1', line(PropertyFactory::relation('parent,1')));
	Assert::same('RELATED-TO;RELTYPE=CHILD:child-1', line(PropertyFactory::relation(new Relation('child-1', Parameters::from(['RELTYPE' => 'CHILD'])))));
	Assert::same('RELATED-TO;VALUE=URI:https://example.org/1', line(PropertyFactory::relation(new Relation('https://example.org/1', Parameters::from(['VALUE' => 'URI'])))));
});

test('properties: Property objects and name => value pairs', function () {
	$properties = PropertyFactory::properties([
		Property::create('COMMENT', 'first', ['LANGUAGE' => 'en']),
		Property::create('COMMENT', 'second'),
		'X-EXAMPLE' => 'raw\, value',
		'CREATED' => new DateTimeImmutable('2026-01-01 10:00', new DateTimeZone('Europe/Prague')),
		'X-DUE' => new DateTimeImmutable('2026-01-01 10:00', new DateTimeZone('Europe/Prague')),
		'X-DAY' => DateTimeValue::date(2026, 1, 1),
		'REFRESH-INTERVAL' => new DateInterval('P1D'),
		'X-RULE' => 'FREQ=DAILY',
		'EXRULE' => Rule::fromString('FREQ=DAILY'),
		'CONTACT' => CalAddress::create('mailto:a@example.org'),
		'PERCENT-COMPLETE' => 50,
		'X-COUNT' => 5,
		'X-ENABLED' => true,
		'X-RATIO' => 0.5,
		'GEO' => [50.0, 14.0],
		'RESOURCES' => ['Projector', 'Room, 2nd floor'],
		'EXDATE' => [new DateTimeImmutable('2026-01-02 10:00', new DateTimeZone('Europe/Prague'))],
		'X-PERIOD' => new Period(DateTimeValue::parse('20260101T100000Z'), DateTimeValue::parse('20260101T110000Z')),
	]);
	Assert::same([
		'COMMENT;LANGUAGE=en:first',
		'COMMENT:second',
		'X-EXAMPLE:raw\, value',
		'CREATED:20260101T090000Z',
		'X-DUE;TZID=Europe/Prague;VALUE=DATE-TIME:20260101T100000',
		'X-DAY;VALUE=DATE:20260101',
		'REFRESH-INTERVAL:P1D',
		'X-RULE:FREQ=DAILY',
		'EXRULE:FREQ=DAILY',
		'CONTACT;VALUE=CAL-ADDRESS:mailto:a@example.org',
		'PERCENT-COMPLETE:50',
		'X-COUNT;VALUE=INTEGER:5',
		'X-ENABLED;VALUE=BOOLEAN:TRUE',
		'X-RATIO;VALUE=FLOAT:0.5',
		'GEO:50;14',
		'RESOURCES:Projector,Room\, 2nd floor',
		'EXDATE;TZID=Europe/Prague:20260102T100000',
		'X-PERIOD;VALUE=PERIOD:20260101T100000Z/20260101T110000Z',
	], array_map(line(...), $properties));

	// the typed values are read back
	$values = new ValueParser();
	Assert::same(5, $values->value($properties[11]));
	Assert::true($values->value($properties[12]));
	Assert::same(0.5, $values->value($properties[13]));
	Assert::same('2026-01-01 10:00 Europe/Prague', $values->value($properties[4])->toDateTime()->format('Y-m-d H:i e'));
	Assert::same(['Projector', 'Room, 2nd floor'], $values->value($properties[15]));
});

test('properties: invalid names and values are rejected', function () {
	Assert::exception(fn() => PropertyFactory::properties(['X-A B' => 'x']), InvalidArgumentException::class, '~Invalid property name~');
	Assert::exception(fn() => PropertyFactory::properties(['END' => 'VEVENT']), InvalidArgumentException::class, '~not a property~');
	Assert::exception(fn() => PropertyFactory::properties(['value']), InvalidArgumentException::class, '~Property object~');
	Assert::exception(fn() => PropertyFactory::properties(['X-LIST' => []]), InvalidArgumentException::class);
	Assert::exception(fn() => PropertyFactory::properties(['X-OBJECT' => new stdClass()]), InvalidArgumentException::class, '~Unsupported value~');
});

test('CalAddress::create() sets CN, ROLE, PARTSTAT, RSVP and CUTYPE', function () {
	$address = CalAddress::create('mailto:a@example.org', name: 'Doe, Jane', role: 'chair', status: 'accepted', rsvp: false, type: 'individual', parameters: ['DELEGATED-FROM' => 'mailto:b@example.org']);
	Assert::same('ATTENDEE;CN="Doe, Jane";CUTYPE=INDIVIDUAL;ROLE=CHAIR;PARTSTAT=ACCEPTED;RSVP=FALSE;DELEGATED-FROM="mailto:b@example.org":mailto:a@example.org', line(PropertyFactory::calAddress('ATTENDEE', $address)));
	Assert::same(['a@example.org', 'Doe, Jane', 'CHAIR', 'ACCEPTED', false], [$address->email(), $address->name(), $address->role(), $address->status(), $address->rsvp()]);
	Assert::true(CalAddress::create('a@example.org', rsvp: true)->rsvp());
	Assert::same('mailto:a@example.org', CalAddress::create('a@example.org')->uri, 'an e-mail address becomes a mailto: URI');
	Assert::same('urn:uuid:1', CalAddress::create('urn:uuid:1')->uri);
	Assert::same([], CalAddress::create('mailto:a@example.org')->parameters->all());
	Assert::exception(fn() => CalAddress::create(' '), InvalidArgumentException::class);
	Assert::exception(fn() => CalAddress::create('mailto:a b@example.org'), InvalidArgumentException::class);
});
