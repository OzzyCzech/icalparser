[![Packagist Version](https://img.shields.io/packagist/v/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist Downloads](https://img.shields.io/packagist/dm/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist License](https://img.shields.io/packagist/l/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/om/icalparser/php?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![PHP Tests](https://img.shields.io/github/actions/workflow/status/OzzyCzech/icalparser/php.yml?style=for-the-badge)](https://github.com/OzzyCzech/icalparser/actions/workflows/php.yml)

# PHP iCal Parser

A lightweight and robust iCalendar ([RFC 5545](https://www.rfc-editor.org/rfc/rfc5545)) parser for PHP.

- reads real-world `.ics` files from Google Calendar, Apple Calendar, Outlook, Exchange, Nextcloud, Fastmail and others,
  repairs damaged files and reports every repair
- keeps unknown and X- properties, writes calendars back
- keeps the meaning of dates, floating, UTC and zoned times, resolves Windows timezones and custom VTIMEZONE definitions
- expands recurring events lazily: the complete RRULE (including BYSETPOS and BYWEEKNO), RDATE, EXDATE and
  RECURRENCE-ID overrides (moved, cancelled and THISANDFUTURE)
- safe for untrusted input: strict and permissive mode, resource limits, streaming of large files

## Install

```shell
composer require om/icalparser
```

## Usage

```php
use om\ICal;

$calendar = ICal::parseFile('calendar.ics'); // or ICal::parse($content)

foreach ($calendar->events() as $event) {
	echo $event->summary(), ' ', $event->start()?->format('Y-m-d H:i'), PHP_EOL;
}

// every instance of every event in a period, sorted, with moved and cancelled instances applied
$from = new DateTimeImmutable('2026-01-01');
$to = new DateTimeImmutable('2026-02-01');
foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {
	// the local time; ->startTime($timezone) gives an instant (see "Dates and times")
	printf("%s %s%s\n", $occurrence->start->format('j. n. H:i'), $occurrence->summary(), $occurrence->isModified() ? ' (changed)' : '');
}
```

Events, tasks (`todos()`), journal entries (`journals()`) and free/busy components (`freeBusy()`) have typed getters:
`uid()`, `summary()`, `description()`, `location()`, `start()`, `end()`, `duration()`, `status()`, `categories()`,
`organizer()`, `attendees()`, `alarms()`, `recurrenceRule()` and more. Any property, including unknown ones, is available too:

```php
$event->property('X-APPLE-STRUCTURED-LOCATION')?->parameter('X-TITLE');
$event->value('X-MICROSOFT-CDO-BUSYSTATUS'); // typed value, see docs/values.md
```

### Dates and times

`start()`, `end()` and the occurrences return `DateTimeValue`, which keeps the difference between
`20261010` (a date), `20261010T100000` (floating), `20261010T100000Z` (UTC) and `TZID=Europe/Prague:20261010T100000` (zoned).
Floating times and dates are never converted with the PHP default timezone:

```php
$start = $event->start();
$start->format('Y-m-d H:i');                           // the local value, always
$start->toDateTime();                                  // the instant; needs a timezone for dates and floating times
$start->toDateTime(new DateTimeZone('Europe/Prague')); // in a given timezone
$start->isDate(); $start->isFloating(); $start->isUtc(); $start->isZoned();
```

The timezone of dates and floating times is X-WR-TIMEZONE of the calendar or the one configured with
`ICal::parser()->floatingTimezone(...)`. See [values and timezones](docs/values.md).

### Recurring events

```php
foreach ($event->occurrencesBetween($from, $to) as $occurrence) { /* ... */ }
foreach ($event->occurrences(limit: 10) as $occurrence) { /* ... */ }
```

Occurrences are generated lazily and only for a window or up to a limit; there is no unlimited expansion.
The recurrence engine can be used on its own:

```php
use om\RRule\Expander;
use om\RRule\Rule;

$rule = Rule::fromString('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3'); // the last workday
foreach (new Expander($rule, new DateTimeImmutable('2026-01-30 09:00', new DateTimeZone('Europe/Prague'))) as $timestamp) {
	echo date('Y-m-d', $timestamp), PHP_EOL;
}
```

See [recurrence](docs/recurrence.md).

### Strict and permissive parsing

```php
use om\ICal\Parser\ParserMode;

$result = ICal::parser()->mode(ParserMode::Permissive)->parseFile('feed.ics');
$calendar = $result->calendar();
foreach ($result->warnings() as $warning) {
	echo $warning, PHP_EOL; // line 12: END:VEVENT is missing, the component was closed. [syntax.missing-end]
}
```

The permissive mode (default) repairs damaged files and reports each repair as a warning. The strict mode throws an
exception with an error code, line, property and raw value. Limits of the input and of recurrence expansion protect
against pathological files. See [parsing, warnings and limits](docs/parsing.md) and [validation](docs/validation.md).

### Large files

```php
foreach (ICal::stream('huge.ics') as $item) { // events, tasks, ... one by one, constant memory
	echo $item->summary(), PHP_EOL;
}
```

### Writing

```php
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Property;

$event = new Component('VEVENT', [
	Property::create('UID', 'meeting-1@example.org'),
	Property::create('DTSTAMP', '20260101T000000Z'),
	Property::create('DTSTART', '20260105T093000', ['TZID' => 'Europe/Prague']),
	Property::create('SUMMARY', 'Standup'),
]);
echo Calendar::create()->withComponent($event)->serialize();
```

Parsed calendars are serialized with all their properties, lines are folded at 75 octets.

## Examples

The [examples](examples) directory contains a web page listing upcoming events of a sample calendar
(`php -S localhost:8000 -t examples`) and command line scripts for streaming, validation and writing.

## Upgrading from version 4

The array based `IcalParser` of version 4 is still available and deprecated; it keeps its output and fixes many bugs.
See [UPGRADING.md](UPGRADING.md) and [CHANGELOG.md](CHANGELOG.md).

## Development

iCal parser uses [Nette Tester](https://github.com/nette/tester), [PHPStan](https://phpstan.org/) and
[PHP CS Fixer](https://cs.symfony.com/).

```shell
composer install
composer test               # unit tests and tests of the version 4 API
composer test:integration   # public API, parser modes, golden files of tests/Fixtures
composer test:fuzz          # corrupted and pathological input
composer test:differential  # comparison with sabre/vobject and python-dateutil (set ICALPARSER_PYTHON)
composer analyse            # PHPStan
composer cs                 # coding standard (cs:fix fixes it)
composer check              # all of the above except differential tests
```

Every calendar in `tests/Fixtures` has a golden file with the normalized output. After an intended change,
regenerate them with `UPDATE_SNAPSHOTS=1 composer test:integration` and review the diff. Every bug gets a fixture
in `tests/Fixtures/Regression` or a test.
