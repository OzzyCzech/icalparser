# Creating calendars

Calendars are created with named arguments: `Calendar::create()`, `Event::new()`, `Todo::new()`,
`Journal::new()`, `Alarm::display()` and the other factories return the same immutable model the
parser returns, so a created calendar has the same getters as a parsed one.

```php
use om\ICal\Alarm;
use om\ICal\Calendar;
use om\ICal\Event;
use om\ICal\Value\CalAddress;

$calendar = Calendar::create('-//example//standup//EN', name: 'Team A', events: [
	Event::new(
		summary: 'Standup, team A',
		start: new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('Europe/Prague')),
		duration: new DateInterval('PT15M'),
		rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',     // a string or an om\RRule\Rule
		organizer: CalAddress::create('mailto:boss@example.org', name: 'Boss'),
		attendees: [CalAddress::create('mailto:a@example.org', name: 'A', rsvp: true)],
		alarms: [Alarm::display('Standup', trigger: '-PT5M')],
		properties: ['X-EXAMPLE' => 'custom'],
	),
]);

echo $calendar->serialize();          // or $calendar->writeFile('team.ics')
```

The factories take care of the iCalendar format: values are formatted and TEXT is escaped, `VERSION`,
`PRODID`, `UID` (a random UUID) and `DTSTAMP` (now, in UTC) are added, and a `VTIMEZONE` is written
for every timezone used. Invalid combinations of arguments throw an `InvalidArgumentException` when
the object is created, so the result passes the [Validator](validation.md) and can be parsed back by
`ICal::parse()` to the same typed values.

## Calendar

```php
Calendar::create(
	'-//example//team//EN',   // PRODID, the only positional argument (also accepted by version 4 code)
	name: 'Team A',           // NAME (RFC 7986) and X-WR-CALNAME
	description: '...',       // DESCRIPTION (RFC 7986) and X-WR-CALDESC
	color: 'steelblue',       // COLOR (RFC 7986)
	method: 'PUBLISH',        // METHOD (RFC 5546)
	events: [...],            // Event or VEVENT Component objects
	todos: [...],             // Todo or VTODO Component objects
	journals: [...],          // Journal or VJOURNAL Component objects
	components: [...],        // other components, e.g. VFREEBUSY or your own VTIMEZONE
	properties: [...],        // other properties, see below
	timezones: true,          // add a VTIMEZONE for every TZID used
);
```

`NAME` and `DESCRIPTION` are also written as `X-WR-CALNAME` and `X-WR-CALDESC`, which Google Calendar,
Apple Calendar and Outlook read. Components are written in the order VTIMEZONE, events, tasks, journal
entries and other components. `withComponent()` adds a component to a calendar, without a VTIMEZONE.

## Events, tasks and journal entries

All arguments are optional:

| Argument | Property | PHP value |
|----------|----------|-----------|
| `uid` | UID | `string`, a random UUID by default |
| `stamp` | DTSTAMP | date-time (below), now by default; written in UTC |
| `start` | DTSTART | date-time |
| `end` | DTEND (events) | date-time of the value type of `start`, not earlier |
| `due` | DUE (tasks) | date-time of the value type of `start`, not earlier |
| `duration` | DURATION (events, tasks) | `DateInterval` or `'PT1H'`; not with `end`/`due`, whole days for an all-day start |
| `completed`, `percentComplete` | COMPLETED, PERCENT-COMPLETE (tasks) | date-time (written in UTC), `int` 0–100 |
| `summary`, `description`, `location` | SUMMARY, DESCRIPTION, LOCATION | `string` (escaped); no LOCATION in journal entries |
| `url` | URL | `string`, a URI |
| `status` | STATUS | `Status` enum or `string`, checked for the component: TENTATIVE, CONFIRMED, CANCELLED (events), NEEDS-ACTION, COMPLETED, IN-PROCESS, CANCELLED (tasks), DRAFT, FINAL, CANCELLED (journal entries) |
| `transparency` | TRANSP (events) | `Transparency` enum or OPAQUE, TRANSPARENT |
| `classification` | CLASS | `Classification` enum or `string` |
| `priority` | PRIORITY | `int` 0–9 |
| `sequence` | SEQUENCE | `int` ≥ 0 |
| `categories` | CATEGORIES | `list<string>`, one property |
| `organizer`, `attendees` | ORGANIZER, ATTENDEE | `CalAddress` or a URI (an e-mail address becomes `mailto:`) |
| `rrule` | RRULE | `om\RRule\Rule` or `string`, validated; UNTIL must have the value type of `start` (UTC for zoned times) |
| `rdates`, `exdates` | RDATE, EXDATE | lists of date-times (RDATE also `Period`), of the value type of `start`; values of the same kind share a property |
| `recurrenceId` | RECURRENCE-ID | date-time; an override has the UID of its series |
| `geo` | GEO | `[latitude, longitude]` |
| `color` | COLOR (RFC 7986) | `string` |
| `images`, `conferences` | IMAGE, CONFERENCE (RFC 7986) | URIs or `Image`, `Conference` objects (with their parameters) |
| `links`, `relatedTo` | LINK (RFC 9253), RELATED-TO | URIs, UIDs or `Link`, `Relation` objects |
| `alarms` | VALARM (events, tasks) | `Alarm` objects, see below |
| `locations` | VLOCATION (RFC 9073) | `Location::new(name: 'Venue', types: ['hotel'], geo: [...])` |
| `properties` | any | see below |

A **date-time** argument is:

- a `DateTimeInterface`: a named timezone (`Europe/Prague`) keeps its local time and gets a `TZID`,
  UTC is written with `Z`; offsets (`+02:00`) and abbreviations (`CEST`) are not timezones, such
  values are converted to UTC
- a `DateTimeValue` for dates (`DateTimeValue::date(2026, 12, 24)`, all-day events) and floating times
  (`DateTimeValue::floating(...)`, the same local time in every timezone)
- an iCalendar string: `'20261224'` (a date), `'20260105T093000'` (floating) or `'20260105T093000Z'`

These combinations are rejected: `end` (or `due`) with `duration`, an end before the start, a date start
with a date-time end (and the other way round, also for RDATE, EXDATE, RECURRENCE-ID and UNTIL), `end`,
`duration`, `rrule` or `rdates` without a start, an alarm without the start (or end) its trigger is related
to, values out of range, and a property of `properties:` that repeats a single property set by an argument.

```php
use om\ICal\Journal;
use om\ICal\Journal;
use om\ICal\Todo;
use om\ICal\Value\DateTimeValue;

Event::new(summary: 'Offsite', start: DateTimeValue::date(2026, 10, 22), end: DateTimeValue::date(2026, 10, 24));
Todo::new(summary: 'Report', due: new DateTimeImmutable('2026-03-31 17:00', new DateTimeZone('Europe/Prague')), priority: 1);
Journal::new(summary: 'Minutes', start: '20260105', description: '...');
```

## Alarms

```php
Alarm::display('Standup in 5 minutes', trigger: '-PT5M');
Alarm::display('Ends soon', trigger: '-PT10M', related: 'END', repeat: 2, duration: 'PT5M');
Alarm::audio(trigger: new DateTimeImmutable('2026-01-05 09:00', new DateTimeZone('Europe/Prague')), sound: 'https://example.org/ding.wav');
Alarm::email('Report is due', 'The report is due tomorrow.', ['boss@example.org'], trigger: '-P1D');
```

The factories write the properties RFC 5545 requires for the action (section 3.6.6): DESCRIPTION for
DISPLAY, DESCRIPTION, SUMMARY and at least one ATTENDEE for EMAIL. The trigger is a duration relative to the
start (`related: 'END'` for the end) or an absolute time, written in UTC with `VALUE=DATE-TIME`. `repeat`
and `duration` are given together. `uid:` sets the UID of RFC 9074.

## Other properties

`properties:` takes `Property` objects, e.g. for repeated properties and parameters such as `LANGUAGE`,
and `name => value` pairs:

```php
use om\ICal\Property;

Event::new(summary: 'Standup', properties: [
	Property::create('COMMENT', 'Weekly', ['LANGUAGE' => 'en']),
	'CREATED' => new DateTimeImmutable('2025-12-01'),   // converted: DTSTAMP, CREATED, LAST-MODIFIED, COMPLETED in UTC
	'RESOURCES' => ['Projector', 'Room 1'],               // a list of TEXT values, escaped one by one
	'X-EXAMPLE' => 'raw\, value',                         // a string is the raw value: escape TEXT with Text::escape()
	'X-COUNT' => 5,                                       // X-COUNT;VALUE=INTEGER:5
]);
```

A string is written as it is, so TEXT is not escaped (`'a, b'` would be read as a list); use
`Text::escape()` or pass the value through an argument. Other PHP values are converted by their type:
date-times, `DateInterval` (DURATION), `Rule` (RECUR), `CalAddress`, `Period`, `int`, `float`, `bool`,
lists of strings (TEXT) or date-times, and `[latitude, longitude]` for GEO. A `VALUE` parameter is added
when the type is not the default type of the property (`ValueParser::TYPES`).

## Timezones

`Calendar::create()` adds a VTIMEZONE for every IANA TZID used by the components (DTSTART, DTEND, DUE,
RDATE, EXDATE, RECURRENCE-ID and others, also in nested components) unless the calendar gets its own
definition in `components:` or `timezones: false` is given. Programs that know IANA names use their own
data, others (Outlook, older clients) need the definition.

The definition is created from the transitions of PHP (`DateTimeZone::getTransitions()`) for the dates
of the TZID with a year before and after; the definition of a recurrence without an end covers
`VTimezoneBuilder::OPEN_YEARS` (10) years after its last date, a recurrence with COUNT or UNTIL its last
occurrence. Transitions on the same weekday of a month (e.g. the last Sunday of March) become an observance
with a yearly RRULE, which has no UNTIL while the timezone keeps the rule after the range, so the definition
usually stays correct also for later dates:

```text
BEGIN:VTIMEZONE
TZID:Europe/Prague
X-LIC-LOCATION:Europe/Prague
BEGIN:STANDARD
DTSTART:20241027T030000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
TZNAME:CET
END:STANDARD
BEGIN:DAYLIGHT
...
```

Other transitions (a timezone changing its offset, rules like "the Sunday on or after the 2nd") are
observances of their own; they are exact in the range. `VTimezoneBuilder::build($timezone, $from, $to)`
creates a definition for any range.

## Output

`serialize()` returns the iCalendar data with CRLF line breaks and lines folded at 75 octets, `writeFile()`
writes it to a file. Serve it with the media type of RFC 5545:

```php
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="team.ics"');
echo $calendar->serialize();
```

For iTIP messages (RFC 5546, e.g. an invitation sent by e-mail) set `method: 'REQUEST'` (or `PUBLISH`,
`REPLY`, `CANCEL`); the media type then has the method too: `text/calendar; charset=utf-8; method=REQUEST`.
