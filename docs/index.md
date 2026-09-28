# PHP iCal Parser

A lightweight and robust iCalendar ([RFC 5545](https://www.rfc-editor.org/rfc/rfc5545)) parser for PHP.

- reads real-world `.ics` files from Google Calendar, Apple Calendar, Outlook, Exchange, Nextcloud, Fastmail
  and others, repairs damaged files and reports every repair
- keeps unknown and X- properties, writes calendars back
- creates calendars with named arguments (`Event::new(summary: ..., start: ...)`), with VTIMEZONE definitions
- keeps the meaning of dates, floating, UTC and zoned times, resolves Windows timezones and custom VTIMEZONE
  definitions
- expands recurring events lazily: the complete RRULE, RDATE, EXDATE and RECURRENCE-ID overrides
- safe for untrusted input: strict and permissive mode, resource limits, streaming of large files

## Install

```shell
composer require om/icalparser
```

## Quick start

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
	echo $occurrence->start->format('j. n. H:i'), ' ', $occurrence->summary(), PHP_EOL;
}
```

Read on:

- [Reading calendars](reading.md): events, tasks, attendees, alarms, occurrences and any property
- [Values and timezones](values.md): dates and times, timezone resolution, value types
- [Recurrence](recurrence.md): recurrence sets, rules, series with overrides
- [Creating calendars](creating.md): events, tasks, alarms and timezones from named arguments
- [Parsing and limits](parsing.md): strict and permissive mode, warnings, limits, streaming
- [Validation](validation.md): RFC 5545 checks of a parsed calendar
- [API reference](api/index.md): every public class, generated from the source code

The [examples](https://github.com/OzzyCzech/icalparser/tree/main/examples) directory contains a web page and
command line scripts. Upgrading from version 4? See [Upgrading](UPGRADING.md).
