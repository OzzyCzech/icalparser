# Parsing: strict and permissive mode, warnings, limits

```php
use om\ICal;
use om\ICal\Parser\ParseLimits;
use om\ICal\Parser\ParserMode;
use om\RRule\RecurrenceLimits;

$result = ICal::parser()
	->mode(ParserMode::Strict)                    // default: ParserMode::Permissive
	->limits(new ParseLimits(maxFileSize: 5_000_000))
	->recurrenceLimits(new RecurrenceLimits(maxInstances: 10_000))
	->floatingTimezone(new DateTimeZone('Europe/Prague'))
	->parse($ics);                                // or parseFile(), parseStream()

$calendar = $result->calendar();                  // the first VCALENDAR
$result->calendars();                             // all of them
$result->warnings();                              // list<ParseWarning>
```

`ICal::parse($ics)` is a shortcut for `ICal::parser()->parse($ics)->calendar()`.
The parser configuration is immutable, every setter returns a new parser.

## Permissive mode (default)

For real-world feeds. Recoverable problems are repaired and every repair is reported as
a `ParseWarning` with a stable `code`, a `message`, the `line` and the `property`:

| Code | Repair |
|------|--------|
| `syntax.line-ending` | line breaks other than CRLF were normalized (reported once) |
| `syntax.invalid-line` | a line that is not a content line was skipped |
| `syntax.outside-calendar` | a property outside of VCALENDAR was skipped |
| `syntax.missing-calendar` | a component outside of VCALENDAR got an implicit VCALENDAR |
| `syntax.unexpected-end` | an END without its BEGIN was skipped |
| `syntax.missing-end` | an END was missing, the component was closed (also when a new VEVENT, VTODO, ... starts inside an open component) |
| `syntax.no-calendar` | the input contains no VCALENDAR |
| `timezone.unresolved` | a TZID cannot be resolved, its times stay floating |
| `value.invalid` | a value does not match its type; it is ignored (`null`), an invalid RRULE makes the item a single one |
| `value.nonstandard` | a value breaking the RFC was accepted: a date with a `Z` suffix (Google), a date without `VALUE=DATE`, `VALUE=DATE` with a time or `VALUE=DATE-TIME` with a date, a TZID on a UTC time, a PERIOD where it is not allowed (its start is used) |
| `value.leap-second` | a leap second (allowed by the RFC) was read as second 59, PHP cannot represent it |

Unknown properties, parameters and components are kept. Values of known types are converted during
parsing to report the `value.*` warnings; `->checkValues(false)` skips that for faster parsing of
large files, values are then converted only when they are read (see [values](values.md)).
The [validator](validation.md) reports RFC violations that are not repaired.

## Strict mode

For validation, tests and debugging. Every problem of the table above throws: a `SyntaxException`,
a `TimezoneResolutionException` for `timezone.unresolved`, an `InvalidValueException` for an invalid
or nonstandard DATE-TIME, DURATION, INTEGER, ... and an `InvalidRecurrenceRuleException` for an
invalid RRULE. A leap second is only a warning.

## Exceptions

All exceptions implement `om\ICal\Exception\ICalException` with `errorCode()`, `line()`,
`property()` and `rawValue()`:

| Exception | Parent | Codes |
|-----------|--------|-------|
| `SyntaxException` | `InvalidArgumentException` | `syntax.*` |
| `InvalidValueException` | `InvalidArgumentException` | `value.invalid-date-time`, `value.invalid-duration`, `value.invalid-integer`, `value.invalid-float`, `value.invalid-boolean`, `value.invalid-geo`, `value.invalid-binary`, `value.invalid-utc-offset` |
| `InvalidRecurrenceRuleException` | `InvalidValueException` | `recurrence.invalid-rule` |
| `TimezoneResolutionException` | `RuntimeException` | `timezone.unresolved`, `timezone.floating` |
| `ResourceLimitException` | `RuntimeException` | `limit.*`, `recurrence.limit`, `recurrence.iterations` |
| `ValidationException` | `RuntimeException` | codes of the [validator](validation.md) |

## Limits

Untrusted input is limited; exceeding a limit throws `ResourceLimitException` in both modes.

| Limit | Default | Code |
|-------|---------|------|
| `ParseLimits::$maxFileSize` | 100 MB | `limit.file-size` |
| `ParseLimits::$maxLineLength` (unfolded) | 1 MB | `limit.line-length` |
| `ParseLimits::$maxComponents` | 1 000 000 | `limit.components` |
| `ParseLimits::$maxProperties` | 10 000 000 | `limit.properties` |
| `ParseLimits::$maxNestingDepth` | 32 | `limit.nesting` |
| `RecurrenceLimits::$maxInstances` (per item) | 100 000 | `recurrence.limit` |
| `RecurrenceLimits::$maxIterations` (periods per rule) | 1 000 000 | `recurrence.iterations` |

`ParseLimits::unlimited()` removes the parse limits.

## Streaming

`ICal::stream($file)` and `Parser::stream($fileOrResource, $onWarning)` return events, tasks,
journal entries and free/busy components one by one, so memory does not depend on the size of
the file. Timezones (VTIMEZONE) and calendar properties seen before an item are used for its
values, which are checked like in `parse()` (warnings go to `$onWarning`, strict mode throws).
Overrides (RECURRENCE-ID) are separate items in a stream, parse the whole calendar to get
complete series.
