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

Unknown properties, parameters and components are kept. Values are converted only when they
are read (see [values](values.md)); an invalid value is `null` then. Use the
[validator](validation.md) to find invalid values and RFC violations.

## Strict mode

For validation, tests and debugging. Every problem of the table above throws a `SyntaxException`
(`TimezoneResolutionException` for `timezone.unresolved`), and every value of a known type is
converted during parsing, so an invalid DATE-TIME, DURATION, INTEGER, ... throws an
`InvalidValueException` and an invalid RRULE an `InvalidRecurrenceRuleException`.

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
values. Overrides (RECURRENCE-ID) are separate items in a stream, parse the whole calendar to
get complete series.
