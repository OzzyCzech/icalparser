# Changelog

## 5.0.0 (2026-09-29)

Version 5 adds a new, layered API (`om\ICal`) for reading and creating calendars and keeps the array based API of
version 4 (`IcalParser`, `EventsList`, `Freq`, `Recurrence`, `ParserOptions`) with the shape of its data, now deprecated
and to be removed in 5.5 at the latest. Both use a new recurrence engine and content line parser, which fixes many bugs;
the results of affected calendars differ from 4.x.
See [UPGRADING.md](UPGRADING.md). The fixes of 4.1.4 (#37, #88) are included.

### New API

- `ICal::parse()`, `ICal::parseFile()`, `ICal::stream()` and the configurable `ICal::parser()` with
  `ParserMode::Strict` / `Permissive`, `ParseLimits`, `RecurrenceLimits` and a `ParseResult` with structured warnings
- syntax layer: `ContentLine`, `Parameters`, `LineReader` (streams in chunks), `Tokenizer`
- immutable generic model `Component` / `Property` keeping unknown and X- properties; typed facades `Calendar`, `Event`,
  `Todo`, `Journal`, `FreeBusy`, `Alarm`, `TimezoneDefinition`
- `DateTimeValue` keeps DATE, floating, UTC and zoned times apart; floating times need an explicit timezone
- `ValueParser` for all RFC 5545 value types
- timezone resolvers: VTIMEZONE definitions (custom Outlook/Exchange zones are matched to IANA timezones), IANA names,
  aliases (CLDR Windows names, Outlook display names, prefixed and shortened names, intl), fallback
- series: overrides grouped by UID, moved, cancelled and `RANGE=THISANDFUTURE` instances, lazy `occurrencesBetween()`
  and `occurrences(limit)`
- `Validator` with severities, `Serializer` with UTF-8 safe folding
- vCalendar 1.0 `ENCODING=QUOTED-PRINTABLE` text values are decoded (with a warning), as by `IcalParser`
- exceptions with error code, line, property and raw value (`SyntaxException`, `InvalidValueException`,
  `InvalidRecurrenceRuleException`, `TimezoneResolutionException`, `ResourceLimitException`, `ValidationException`)
- documentation at [ozzyczech.github.io/icalparser](https://ozzyczech.github.io/icalparser/) (guides, recipes and
  the API reference), examples in `examples/`
- default value types of RFC 7986, RFC 9074 and RFC 9253 properties: `IMAGE`, `CONFERENCE`, `SOURCE`, `LINK` and
  `CONCEPT` are URIs, `REFRESH-INTERVAL` is a `DURATION`, `ACKNOWLEDGED` is a `DATE-TIME` (the `Validator` reports a
  non-UTC value); `VALUE=UID` and `VALUE=XML-REFERENCE` of RFC 9253 (#91)
- typed getters of RFC 7986: `color()` and `images()` (`Image`) of calendars and items, `Calendar::source()`,
  `Calendar::refreshInterval()` and `Item::conferences()` (`Conference`); of RFC 9253: `Item::links()` (`Link`) and
  `Item::relatedTo()` (`Relation` with `RELTYPE` and `GAP`); of RFC 9073: `Item::locations()` (`Location` of
  VLOCATION components, kept inside their event or task also when their END is missing); of RFC 9074:
  `Alarm::uid()` and `Alarm::acknowledged()` (#92)
- creating calendars with named arguments: `Calendar::create()` with `name`, `description`, `color`, `method`,
  `events`, `todos`, `journals`, `components` and `properties` (backward compatible), `Event::new()`,
  `Todo::new()`, `Journal::new()`, `Location::new()`, `Alarm::display()`, `audio()` and `email()`,
  `CalAddress::create()`; values are formatted and escaped, invalid combinations are rejected, a VTIMEZONE is
  generated for every TZID used (`VTimezoneBuilder`), `Calendar::writeFile()`; `Status`, `Classification` and
  `Transparency` enums (#108)

### Added

- RFC 5545 recurrence engine `om\RRule\Rule` and `om\RRule\Expander` with all rule parts: `BYSETPOS`, `BYSECOND`,
  `SECONDLY`, negative `BYWEEKNO` and `BYYEARDAY`, `WKST` for weekly intervals, date-only `UNTIL`
- RFC 7529: `RSCALE` and `SKIP=OMIT|BACKWARD|FORWARD` for `RSCALE=GREGORIAN`; other calendar systems and leap months
  are not expanded as Gregorian (`recurrence.unsupported-rscale`) (#90)
- `IcalParser::__construct()` accepts `ParserOptions`; `untilInterval` and `shiftEventDates` now work and new
  options are `now` (reproducible results), `maxOccurrences` and `strict`
- `DTEND` of events and instances is derived from `DURATION`; all-day events without `DTEND` last one day
- `IcalParser::parseDuration()` for `DURATION` values
- `getTodos()` and `getJournals()`; `DUE` and `COMPLETED` are parsed as dates
- `RDATE;VALUE=PERIOD` values (represented by their start)
- `EXDATE;VALUE=DATE` removes the occurrence of that day
- quoted parameter values containing `:`, `;` or `,` (e.g. `CN="Doe, John"`, `ALTREP="http://…"`)
- case-insensitive property, parameter and component names; CR line endings; UTF-8 byte order mark;
  folding with a tab
- `om\TimezoneResolver` (TZID resolution with a cache)

### Changed

- the parsed data no longer contains `BEGIN => VCALENDAR` and `0 => null` entries created by blank or invalid lines
- `_RECURRENCE_IDS` is grouped by UID: `[uid][recurrence-id] => event`
- parameter values are unquoted (`ORGANIZER-CN`, attendee parameters)
- properties following a nested component (e.g. after `END:VALARM`) belong to the parent component;
  properties of unknown and `X-` components are stored under the component name
- `CATEGORIES` items are trimmed and escaped commas no longer split them
- an invalid `RRULE` is ignored (the event keeps `DTSTART` and `RDATE`); with `strict: true` it throws
  `InvalidArgumentException`
- events with an `EXDATE` but no `RRULE` or `RDATE` get `RECURRENCES` as well; an empty recurrence set produces no event
- `DTSTART` and `DTEND` of recurring instances are copies, changing them does not modify the parsed data
- the callback of `parseString()` receives property rows only (not `BEGIN:VCALENDAR`) and counter `0` for calendar
  properties
- `parseFile()` throws `RuntimeException` when the file cannot be read; invalid input keeps previously parsed data
- `IcalParser::$timezone` is reset for every calendar that is not appended
- `Freq` is an adapter over the new engine: invalid rules throw `InvalidArgumentException`, `maxOccurrences` limits
  the expansion, `Freq::$debug` has no effect, `lastOccurrence()` returns `false` for an empty set and
  `previousOccurrence()` returns `false` when there is no earlier occurrence (4.x returned DTSTART)

### Fixed

- VTIMEZONE rules with `UNTIL` are compared in UTC: definitions east of UTC whose daylight saving time ended
  (e.g. Europe/Moscow before 2011) are resolved again, west of UTC no transition after `UNTIL` is kept (#110)
- `RDATE` without `RRULE` no longer fails with `TypeError` and adds no yearly occurrences (#37, also in 4.1.4)
- `RDATE` values are always part of the recurrence set (one was lost together with `COUNT`)
- a `RECURRENCE-ID` replaces only the matching instance of the same UID, compared as an instant in any timezone
  (4.x compared strings for all events, so it could hide instances of other events or a wrong instance)
- an excluded or overridden first occurrence is no longer returned with the original `DTSTART`
- series with `COUNT` reaching beyond the 3 year horizon are complete (4.x failed with `TypeError`)
- yearly rules in January return every year, not only the first occurrence (#59)
- rules the previous engine expanded incorrectly, for example negative weekday ordinals (`BYDAY=-2MO` returned
  every third Monday), `BYHOUR` combined with `BYMINUTE` (minutes were lost) and `INTERVAL` of weekly rules
  (worked around in the parser only partially); `Freq` with a string rule no longer loops forever
- the process default timezone is never changed during expansion
- ambiguous local times (DST fall-back) are their first occurrence and nonexistent times (DST gap) use the offset before
  the gap, as RFC 5545 requires; PHP alone is not consistent
- sub-daily rules no longer repeat an instant over a DST gap and skip days and hours that cannot match
- impossible rules end after an empty 400-year Gregorian cycle; BYSETPOS selecting nothing no longer loops
- the Windows timezone map is generated from CLDR (`UTC` is `Etc/UTC`, `Pacific Standard Time (Mexico)` is
  `America/Tijuana`, 40 new names); Outlook display names are kept in a separate file

### Performance

Compared with 4.1.3 on the sample calendars: expanding recurring events is about 4x faster, parsing a 27 MB calendar
with 50 000 events is about 20 % faster with lower peak memory, and sorting 50 000 events is about 13x faster.
The new API parses the same 27 MB calendar in 1.1 s with value checks (0.95 s without, 226 MB), streams it in 0.5 s
with 2 MB of memory, and converts values lazily.

### Tests and tooling

- a fixture corpus (RFC 5545 examples, Google, Apple, Outlook, Exchange, Nextcloud and Fastmail style calendars, broken
  input, regressions) with golden files
- property-based, fuzz and pathological input tests; differential tests against python-dateutil
- PHPStan level 8, PHP CS Fixer, CI jobs for tests, coding standard and differential tests
