[![Packagist Version](https://img.shields.io/packagist/v/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist Downloads](https://img.shields.io/packagist/dm/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist License](https://img.shields.io/packagist/l/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/om/icalparser/php?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![PHP Tests](https://img.shields.io/github/actions/workflow/status/OzzyCzech/icalparser/php.yml?style=for-the-badge)](https://github.com/OzzyCzech/icalparser/actions/workflows/php.yml)

# PHP iCal Parser

An iCalendar parser that converts calendar data ([RFC 5545](https://www.rfc-editor.org/rfc/rfc5545)) into PHP arrays
and expands recurring events into single instances.

## How to install

The recommended way to is via Composer:

```shell script
composer require om/icalparser
```

## Usage and example

```php
<?php
use om\IcalParser;
require_once '../vendor/autoload.php';

$cal = new IcalParser();
$results = $cal->parseFile(
	'https://www.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics'
);

foreach ($cal->getEvents()->sorted() as $event) {
	printf('%s - %s' . PHP_EOL, $event['DTSTART']->format('j.n.Y'), $event['SUMMARY']);
}
```

`parseFile()` accepts a path or any URL supported by PHP stream wrappers, `parseString()` accepts the calendar content.
Both return the parsed data; pass `add: true` to `parseString()` to append another calendar to the data parsed before.

Each property of each component is available using the property name (in capital letters) as a key.
Dates (`DTSTART`, `DTEND`, `DTSTAMP`, `CREATED`, `LAST-MODIFIED`, `DUE`, `COMPLETED`, `EXDATE`, `RDATE`) are `DateTime`
objects in their timezone; text values are unescaped. There are some special cases:

- multiple attendees with individual parameters: use `ATTENDEES` as key to get all attendees in the following scheme:
```php
[
	[
		'ROLE' => 'REQ-PARTICIPANT',
		'PARTSTAT' => 'NEEDS-ACTION',
		'CN' => 'John Doe',
		'VALUE' => 'mailto:john.doe@example.org'
	],
	[
		'ROLE' => 'REQ-PARTICIPANT',
		'PARTSTAT' => 'NEEDS-ACTION',
		'CN' => 'Test Example',
		'VALUE' => 'mailto:test@example.org'
	]
]
```
- organizer's name: the *CN* parameter of the organizer property can be retrieved using the key `ORGANIZER-CN`
- `ATTACHMENTS`, `EXDATES` and `RDATES` collect all values of properties that may occur more than once
- `CATEGORIES` is a list of categories

Other components are available as well: `getAlarms()`, `getTimezones()`, `getTodos()` and `getJournals()`.

### Events and recurring events

`getEvents()` returns an `EventsList` (an `ArrayObject`) with every recurring event expanded into single instances;
`sorted()` orders them oldest first, `reversed()` newest first, events without a date come last.

- the recurrence set is built from `RRULE`, `RDATE` and `EXDATE` as defined by RFC 5545,
  all rule parts are supported including `BYSETPOS`, `BYWEEKNO`, `BYYEARDAY` and `WKST`
- an instance modified by another `VEVENT` with the same `UID` and a `RECURRENCE-ID` is replaced by that event
- recurring instances contain `RECURRING => true` and a zero-based `RECURRENCE_INSTANCE`
- every event with a start has `DTEND`: from `DTEND`, from `DURATION`, or one day for all-day events
- rules without `UNTIL` or `COUNT` are expanded 3 years into the future (see options below)
- an invalid `RRULE` is ignored, so the event keeps just its `DTSTART` (and `RDATE`)

The recurrence engine is available on its own as well:

```php
use om\RRule\Expander;
use om\RRule\Rule;

$rule = Rule::fromString('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3');
$start = new DateTimeImmutable('2026-01-30 09:00', new DateTimeZone('Europe/Prague'));
foreach (new Expander($rule, $start) as $timestamp) {
	echo date('Y-m-d', $timestamp), PHP_EOL; // last workday of the month
}
```

### Options

```php
use om\IcalParser;
use om\ParserOptions;

$cal = new IcalParser(new ParserOptions(
	untilInterval: new DateInterval('P1Y'),   // expand unbounded rules 1 year ahead (default 3 years)
	shiftEventDates: new DateInterval('P1M'), // skip occurrences of unbounded rules older than 1 month
	now: new DateTimeImmutable('2026-01-01'), // fixed "now" for reproducible results
	maxOccurrences: 10000,                    // occurrences per event (default 100 000)
	strict: true,                             // throw on invalid RRULE instead of ignoring it
));
```

You can run example with [PHP Built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php) as follow:

```shell
php -S localhost:8000 -t example
```

## Upgrading

Version 5 keeps the API of version 4 and fixes many recurrence and parsing bugs, so the results can differ.
See [CHANGELOG.md](CHANGELOG.md) for the full list.

## Requirements

- PHP version see `composer.json`

## Development

iCal parser uses [Nette Tester](https://github.com/nette/tester) and [PHPStan](https://phpstan.org/).

```shell script
composer install
composer test      # tests
composer phpstan   # static analysis
composer coverage  # coverage report (requires pcov, xdebug or phpdbg)
```

Every calendar in `tests/cal` has a snapshot of the parser output in `tests/snapshots`.
After an intended change of the output, regenerate them with `UPDATE_SNAPSHOTS=1 composer test` and review the diff.
