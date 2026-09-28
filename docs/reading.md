# Reading calendars

```php
use om\ICal;

$calendar = ICal::parseFile('calendar.ics');   // or ICal::parse($content)

$calendar->name();          // NAME or X-WR-CALNAME
$calendar->events();        // list<Event>, recurring and single events
$calendar->todos();         // list<Todo>
$calendar->journals();      // list<Journal>
$calendar->freeBusy();      // list<FreeBusy>
$calendar->timezones();     // list<TimezoneDefinition>, the VTIMEZONE definitions
```

`events()` returns every event once: a recurring event is one `Event` with its overrides (components
with a RECURRENCE-ID), not one object per instance. Instances are [occurrences](#occurrences).

## Calendar

| Getter | Property | Value |
|--------|----------|-------|
| `name()`, `description()` | NAME, DESCRIPTION (RFC 7986) or X-WR-CALNAME, X-WR-CALDESC | `?string` |
| `productId()`, `version()`, `method()` | PRODID, VERSION, METHOD | `?string` |
| `timezone()` | X-WR-TIMEZONE | `?DateTimeZone` |
| `color()`, `source()`, `refreshInterval()`, `images()` | COLOR, SOURCE, REFRESH-INTERVAL, IMAGE (RFC 7986) | see [below](#properties-of-the-rfc-5545-updates) |

## Events, tasks and journal entries

`Event`, `Todo`, `Journal` and `FreeBusy` share the getters of `Item`:

| Getter | Property | Value |
|--------|----------|-------|
| `uid()`, `summary()`, `description()`, `location()`, `url()` | UID, SUMMARY, DESCRIPTION, LOCATION, URL | `?string`, TEXT unescaped |
| `start()`, `end()` | DTSTART, DTEND (or DTSTART + DURATION) | `?DateTimeValue`, see [dates and times](values.md#dates-and-times) |
| `duration()` | DURATION or DTEND - DTSTART | `DateInterval` |
| `isAllDay()` | | `bool`, DTSTART is a date |
| `status()`, `isCancelled()` | STATUS | `?string`, `bool` |
| `classification()` | CLASS | `string`, PUBLIC by default |
| `priority()`, `sequence()` | PRIORITY, SEQUENCE | `?int`, `int` |
| `categories()` | CATEGORIES | `list<string>` |
| `organizer()`, `attendees()` | ORGANIZER, ATTENDEE | `?CalAddress`, `list<CalAddress>` |
| `alarms()` | VALARM | `list<Alarm>` |
| `created()`, `lastModified()`, `stamp()` | CREATED, LAST-MODIFIED, DTSTAMP | `?DateTimeValue` |
| `recurrenceRule()`, `recurrenceRules()` | RRULE | `?Rule`, `list<Rule>` |
| `recurrenceDates()`, `exceptionDates()` | RDATE, EXDATE | lists of `DateTimeValue` |
| `isRecurring()`, `overrides()` | | `bool`, `list<Item>` with a RECURRENCE-ID |
| `recurrenceId()`, `isOverride()`, `isThisAndFuture()` | RECURRENCE-ID | of an override |

Getters of one component:

- `Event`: `transparency()` (TRANSP, OPAQUE by default), `geo()` (`?array{float, float}`)
- `Todo`: `due()`, `completed()`, `percentComplete()`, `isCompleted()`
- `FreeBusy`: `periods()` (FREEBUSY, `list<Period>`)

`CalAddress` of an organizer or an attendee:

```php
foreach ($event->attendees() as $attendee) {
	$attendee->uri;       // mailto:a@example.org
	$attendee->email();   // a@example.org
	$attendee->name();    // CN
	$attendee->role();    // ROLE, REQ-PARTICIPANT by default
	$attendee->status();  // PARTSTAT, NEEDS-ACTION by default
	$attendee->type();    // CUTYPE, INDIVIDUAL by default
	$attendee->rsvp();    // RSVP
}
```

## Occurrences

```php
foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {  // all events, sorted
	$occurrence->start;              // DateTimeValue of this instance
	$occurrence->end;
	$occurrence->startTime();        // DateTimeImmutable, an instant
	$occurrence->summary();          // of the instance (an override may change it)
	$occurrence->item;               // the event, or the override of this instance
	$occurrence->master;             // the recurring event
	$occurrence->recurrenceId;       // the original start of a recurring instance
	$occurrence->isRecurring(); $occurrence->isModified(); $occurrence->isAllDay();
}
$event->occurrencesBetween($from, $to);   // one event
$event->occurrences(limit: 10);           // the first ten
```

Occurrences are generated lazily and only for a window or up to a limit. Cancelled instances are skipped
unless `includeCancelled: true` is passed. See [recurrence](recurrence.md).

## Alarms

```php
foreach ($event->alarms() as $alarm) {
	$alarm->action();                    // AUDIO, DISPLAY or EMAIL
	$alarm->trigger();                   // DateInterval (relative) or DateTimeValue (absolute)
	$alarm->related();                   // START or END
	$alarm->triggerTime($occurrence);    // DateTimeImmutable, when it fires for an occurrence
	$alarm->description(); $alarm->summary(); $alarm->attendees();
	$alarm->repeat(); $alarm->duration();
}
```

## Properties of the RFC 5545 updates

Typed getters for the properties and components of RFC 7986, RFC 9073, RFC 9074 and RFC 9253;
other ones (VAVAILABILITY, PARTICIPANT, VRESOURCE, STRUCTURED-DATA, PROXIMITY, snoozed alarms
related by `RELATED-TO;RELTYPE=SNOOZE`, ...) stay available through [any property](#any-property).

| Getter | Property | Value |
|--------|----------|-------|
| `Calendar::color()`, `Item::color()` | COLOR (RFC 7986) | `?string`, a CSS3 color name |
| `Calendar::images()`, `Item::images()` | IMAGE (RFC 7986) | `list<Image>` |
| `Calendar::source()` | SOURCE (RFC 7986) | `?string`, a URI |
| `Calendar::refreshInterval()` | REFRESH-INTERVAL (RFC 7986) | `?DateInterval` |
| `Item::conferences()` | CONFERENCE (RFC 7986) | `list<Conference>` |
| `Item::links()` | LINK (RFC 9253) | `list<Link>` |
| `Item::relatedTo()` | RELATED-TO (RFC 5545, RFC 9253) | `list<Relation>` |
| `Item::locations()` | VLOCATION components (RFC 9073) | `list<Location>`; `location()` stays the LOCATION text |
| `Alarm::uid()` | UID of VALARM (RFC 9074) | `?string` |
| `Alarm::acknowledged()` | ACKNOWLEDGED (RFC 9074) | `?DateTimeValue`, when the alarm was last acknowledged or sent (UTC) |

The values are immutable:

```php
foreach ($event->images() as $image) {
	$image->uri;          // the URI, null for inline data
	$image->data;         // the decoded data of VALUE=BINARY, null for a URI
	$image->display();    // DISPLAY: ['BADGE'] by default, GRAPHIC, FULLSIZE, THUMBNAIL
	$image->mediaType();  // FMTTYPE, e.g. image/png
	$image->altRep();     // ALTREP, the URI launched by a click on the image
}

foreach ($event->conferences() as $conference) {
	$conference->uri;         // e.g. https://video-chat.example.com/;group-id=1234 or tel:+1-412-555-0123,,,654321
	$conference->features();  // FEATURE: AUDIO, CHAT, FEED, MODERATOR, PHONE, SCREEN, VIDEO or an X- value
	$conference->label();     // LABEL, e.g. "Moderator dial-in"
}

foreach ($event->links() as $link) {
	$link->value;        // the target
	$link->valueType();  // URI (default), XML-REFERENCE (a URI with an XPointer anchor) or UID
	$link->relation();   // LINKREL, e.g. latest-version or a URI; there is no default
	$link->label();      // LABEL
	$link->mediaType();  // FMTTYPE
	$link->language();   // LANGUAGE
}

foreach ($task->relatedTo() as $relation) {
	$relation->value;        // the UID of the related component, or a URI
	$relation->valueType();  // UID (default), URI or TEXT
	$relation->type();       // RELTYPE: PARENT (default), CHILD, SIBLING, FINISHTOSTART, FINISHTOFINISH,
	                         // STARTTOFINISH, STARTTOSTART, FIRST, NEXT, DEPENDS-ON, REFID, CONCEPT, ...
	$relation->gap();        // GAP: ?DateInterval, the lag (or the lead when negative) of a temporal relation
}

foreach ($event->locations() as $location) {
	$location->uid();
	$location->name();                        // NAME, e.g. "Parking for the venue"
	$location->description();
	$location->types();                       // LOCATION-TYPE, e.g. ['parking']
	$location->url();
	$location->value('STRUCTURED-DATA');      // any other property, e.g. a link to a vCard
}
```

A VLOCATION without END is closed before the next component (with a `syntax.missing-end` warning),
so it stays inside its event or task.

Google Calendar and Outlook do not write CONFERENCE; the link of a Google Meet is in
`$event->property('X-GOOGLE-CONFERENCE')`.

## Any property

Every property is kept, including unknown and X- ones:

```php
$event->property('X-APPLE-STRUCTURED-LOCATION')?->parameter('X-TITLE');  // the first property
$event->properties('ATTENDEE');                                          // all of them
$event->value('X-MICROSOFT-CDO-BUSYSTATUS');                             // the typed value
$event->component;                                                       // the Component, with child components
```

`value()` converts the value by its type, see [value types](values.md#value-types).
