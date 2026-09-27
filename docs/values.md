# Values and time semantics

## The model

A calendar is a tree of immutable `Component` objects (name, properties, child components)
with immutable `Property` objects (name, `Parameters`, raw value, line number). Every component
and property is kept, including unknown and X- ones:

```php
$event->property('X-APPLE-STRUCTURED-LOCATION')?->parameter('X-TITLE');
$event->component->properties('ATTENDEE');
```

Typed facades (`Calendar`, `Event`, `Todo`, `Journal`, `FreeBusy`, `Alarm`, `TimezoneDefinition`)
convert values on access. `ValueParser` converts any property by its type (the VALUE parameter or
the default type of the property):

| Type | PHP value |
|------|-----------|
| DATE, DATE-TIME | `om\ICal\Value\DateTimeValue` (a list for EXDATE and RDATE) |
| DURATION | `DateInterval` |
| PERIOD | `om\ICal\Value\Period` |
| TEXT | `string` (unescaped), `list<string>` for CATEGORIES and RESOURCES |
| INTEGER, FLOAT, BOOLEAN | `int`, `float`, `bool` |
| RECUR | `om\RRule\Rule` |
| CAL-ADDRESS | `om\ICal\Value\CalAddress` |
| UTC-OFFSET | `int` (seconds) |
| URI | `string` |
| BINARY | `string` (decoded) |
| GEO | `array{float, float}` |

## Dates and times

`DateTimeValue` keeps the meaning of the value (RFC 5545, sections 3.3.4 and 3.3.5):

| Value | Type | `toDateTime()` |
|-------|------|----------------|
| `DTSTART;VALUE=DATE:20261010` | `DateTimeType::Date` | midnight in the given timezone |
| `DTSTART:20261010T100000` | `DateTimeType::Floating` | the local time in the given timezone |
| `DTSTART:20261010T100000Z` | `DateTimeType::Utc` | the instant |
| `DTSTART;TZID=Europe/Prague:20261010T100000` | `DateTimeType::Zoned` | the instant |

Dates and floating times are never converted with the PHP default timezone. They become instants
only with a timezone: the one given to `toDateTime($timezone)`, the one configured with
`Parser::floatingTimezone()`, or X-WR-TIMEZONE of the calendar; otherwise a
`TimezoneResolutionException` is thrown. `format()` always works on the local value.

Local times are converted as RFC 5545 requires, also by `toDateTime()` of dates and floating times:
a time repeated by a DST fall-back is its first occurrence, a time skipped by a DST gap uses the
UTC offset before the gap.

## Writing

`Property::create()` validates the property name, `Parameters` validates parameter names and encodes
values as RFC 6868 requires (`^n`, `^'`, `^^`), and newlines of raw values are written as `\n`, so
no value can start another content line. Use `Text::escape()` for TEXT values.

## Timezones

A TZID is resolved by `CompositeTimezoneResolver::default()`:

1. the VTIMEZONE definition of the calendar – PHP cannot create timezones from rules, so the IANA
   timezone with the same transitions around today is used (the TZID and X-LIC-LOCATION are
   preferred); a definition without transitions becomes a fixed offset,
2. IANA names (case-insensitive),
3. aliases: Windows names from CLDR, Outlook and Exchange display names, prefixed names such as
   `/mozilla.org/20070129_1/Europe/Paris`, names without their first part
   (`Argentina/Buenos_Aires`) and `IntlTimeZone::getIDForWindowsID()` of the intl extension,
4. an optional fallback: `CompositeTimezoneResolver::default(new DateTimeZone('UTC'))`.

An unresolved TZID keeps the time floating (with the TZID in `DateTimeValue::$tzid`). Implement
`om\ICal\Timezone\TimezoneResolver` for other sources and pass it to `Parser::timezoneResolver()`.

The Windows map is generated from CLDR by `composer timezones` (monthly in CI); Outlook display
names are maintained in `resources/timezones/aliases.php`.
