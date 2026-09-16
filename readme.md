[![Packagist Version](https://img.shields.io/packagist/v/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist Downloads](https://img.shields.io/packagist/dm/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist License](https://img.shields.io/packagist/l/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/om/icalparser/php?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![PHP Tests](https://img.shields.io/github/actions/workflow/status/OzzyCzech/icalparser/php.yml?style=for-the-badge)](https://github.com/OzzyCzech/icalparser/actions/workflows/php.yml)

# PHP iCal Parser

An iCalendar parser that converts calendar data into PHP arrays. The format is defined by [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545); not all recurrence rules are supported.

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

Each property of each event is available using the property name (in capital letters) as a key. 
There are some special cases:

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

You can run example with [PHP Built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php) as follow:

```shell
php -S localhost:8000 -t example
```

## Requirements

- PHP version see `composer.json`

## Run tests

iCal parser using [Nette Tester](https://github.com/nette/tester). The tests can be invoked via [composer](https://getcomposer.org/).

```shell script
composer install
composer test
```

## Development on `next`

See [the modernization analysis](docs/modernization.md) for implemented changes,
remaining limitations, and the proposed migration stages.

`parseFile()` reads the source once and throws `RuntimeException` when reading fails.
`parseString()` ignores blank and unrecognized lines; its callback receives property
rows, not component delimiters, with a zero-based component counter (zero for calendar
properties). `sorted()` sorts the existing event list oldest first; `reversed()` sorts
newest first. Both put missing dates last.

Recurrence `INTERVAL` and `COUNT` must be positive integers. Invalid values and
unsupported frequencies throw `InvalidArgumentException`. Supported frequencies are
`YEARLY`, `MONTHLY`, `WEEKLY`, `DAILY`, `HOURLY`, and `MINUTELY`.

`RDATE` also works without `RRULE`. Occurrences are deduplicated and sorted;
`EXDATE` takes precedence over both generated dates and `RDATE`. Overrides are
matched within the same UID. Empty recurrence sets produce no events, and
`getEvents()` does not modify the parsed calendar.

`Freq` limits expansion to 100,000 occurrences by default. Direct callers may
override this with its `maxOccurrences` constructor argument. The same budget
limits search steps per next-occurrence lookup; recursive search also stops at
256 nested calls. Exceeding a budget or failing to advance time throws
`RuntimeException`, with no truncated result returned. These are per-rule limits,
not a limit on total calendar size or memory. `BYSETPOS` and `BYSECOND` are
explicitly rejected until implemented. `Freq::lastOccurrence()` returns `false`
for an empty set.
