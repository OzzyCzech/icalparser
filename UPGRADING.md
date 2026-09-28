# Upgrading from 4.x to 5.0

Version 5 adds a new API in the `om\ICal` namespace. The array based API of version 4 (`IcalParser`) still works
and is deprecated; it will be removed in version 5.5 at the latest.

## Staying on the array API

Nothing has to change: `IcalParser`, `EventsList`, `Freq`, `Recurrence` and `ParserOptions` keep their methods and
the shape of the parsed data. The recurrence engine was replaced, so results of calendars hit by bugs of 4.x differ;
[CHANGELOG.md](CHANGELOG.md) lists every change. Most noticeable:

- `_RECURRENCE_IDS` is grouped by UID, the `BEGIN => VCALENDAR` and `0 => null` entries are gone,
- parameter values are unquoted (`ORGANIZER-CN`, attendee parameters),
- `getEvents()` adds `DTEND` from `DURATION` and to all-day events, instance dates are copies,
- an invalid `RRULE` is ignored (`ParserOptions::$strict` throws instead),
- `Freq::previousOccurrence()` and `lastOccurrence()` return `false` when there is no occurrence,
- a rule of another calendar system than Gregorian (`RSCALE=HEBREW`, RFC 7529) or with a leap month (`BYMONTH=5L`)
  is not expanded as Gregorian any more: `IcalParser` keeps only DTSTART and RDATE, `new Freq()` throws
  (`SKIP` without `RSCALE` is still ignored as before),
- exceptions: `om\ICal\Exception\InvalidRecurrenceRuleException` (an `InvalidArgumentException`) and
  `ResourceLimitException` (a `RuntimeException`), so existing `catch` blocks still work.

## Moving to the new API

| 4.x | 5.0 |
|-----|-----|
| `$parser = new IcalParser(); $parser->parseFile($file)` | `$calendar = ICal::parseFile($file)` |
| `$parser->parseString($ics)` | `ICal::parse($ics)` |
| `new IcalParser(new ParserOptions(strict: true))` | `ICal::parser()->mode(ParserMode::Strict)` |
| `ParserOptions(maxOccurrences: ...)` | `ICal::parser()->recurrenceLimits(new RecurrenceLimits(maxInstances: ...))` |
| `ParserOptions(untilInterval: ..., now: ...)` | the window of `occurrencesBetween($from, $to)` |
| `ParserOptions(windowsTimezones: [...])` | `ICal::parser()->timezoneResolver(...)` with `new AliasTimezoneResolver([...])` |
| `$parser->getEvents()->sorted()` | `$calendar->occurrencesBetween($from, $to)` (sorted), or `$calendar->events()` |
| `$event['SUMMARY']` | `$event->summary()` |
| `$event['DTSTART']` (`DateTime`) | `$event->start()` (`DateTimeValue`), `->toDateTime()` for an instant |
| `$event['DTEND']` | `$event->end()` |
| `$event['RRULE']['FREQ']` | `$event->recurrenceRule()->freq` |
| `$event['RECURRENCES']` | `$event->occurrencesBetween($from, $to)` |
| `$event['RECURRING']`, `RECURRENCE_INSTANCE` | `$occurrence->isRecurring()`, `$occurrence->recurrenceId` |
| `$event['ATTENDEES'][0]['CN']` | `$event->attendees()[0]->name()` |
| `$event['ORGANIZER-CN']` | `$event->organizer()?->name()` |
| `$event['CATEGORIES']` | `$event->categories()` |
| `$event['X-FOO']` | `$event->property('X-FOO')?->value`, `$event->value('X-FOO')` |
| `$parser->getAlarms()` | `$event->alarms()` |
| `$parser->data['VTODO']` | `$calendar->todos()` |
| `$parser->getTimezones()` | `$calendar->timezones()` |
| `$parser->data['X-WR-CALNAME']` | `$calendar->name()` |
| callback of `parseString()` | `ICal::stream($file)`, or `Tokenizer::fromFile($file)` for content lines |
| `new Freq($rule, $timestamp)` | `new Expander(Rule::fromString($rule, true), $start)` (`true` ignores `SKIP` without `RSCALE` as `Freq` does) |
| `new Recurrence($rrule)` | `Rule::fromArray($rrule)` |

### Behavior to be aware of

- **Dates and floating times are not instants.** `DateTimeValue::toDateTime()` needs a timezone for them: the argument,
  `Parser::floatingTimezone()` or X-WR-TIMEZONE; the PHP default timezone is never used. `format()` works always.
- **Recurring events have no horizon.** Version 4 expanded rules without an end 3 years ahead; ask for a window
  (`occurrencesBetween()`) or a number of occurrences (`occurrences(limit)`) instead.
- **Overrides belong to their series.** `events()` returns recurring and single events, not the components with a
  RECURRENCE-ID; they are applied to the occurrences and available through `Item::overrides()`.
- **Values are converted on access.** An invalid value is `null` in permissive mode; use the `Validator` to report it,
  or the strict mode to reject it during parsing.
- **Immutable model.** `Component` and `Property` do not change; `with...()` methods return copies.

## Removed and deprecated

Nothing is removed in 5.0. Deprecated (to be removed in 5.5 at the latest): `IcalParser`, `EventsList`, `Freq`, `Recurrence`,
`ParserOptions`, `IcalParser::getSortedEvents()` and `getReverseSortedEvents()` (already deprecated in 4.x).
