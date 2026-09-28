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
| URI, XML-REFERENCE (RFC 9253) | `string` |
| UID (RFC 9253) | `string` (unescaped like TEXT) |
| BINARY | `string` (decoded) |
| GEO | `array{float, float}` |

Properties of the RFC 5545 updates have these default types (an explicit VALUE parameter takes
precedence, e.g. `IMAGE;VALUE=BINARY;ENCODING=BASE64`):

| Property | Type | RFC |
|----------|------|-----|
| IMAGE, CONFERENCE, SOURCE | URI | RFC 7986 |
| REFRESH-INTERVAL | DURATION | RFC 7986 |
| COLOR, NAME | TEXT | RFC 7986 |
| ACKNOWLEDGED | DATE-TIME (UTC) | RFC 9074 |
| LINK (also VALUE=UID and VALUE=XML-REFERENCE), CONCEPT | URI | RFC 9253 |
| REFID | TEXT | RFC 9253 |

## Properties of the RFC 5545 updates

Typed getters for the properties and components of RFC 7986, RFC 9073, RFC 9074 and RFC 9253;
other ones (VAVAILABILITY, PARTICIPANT, VRESOURCE, STRUCTURED-DATA, PROXIMITY, snoozed alarms
related by `RELATED-TO;RELTYPE=SNOOZE`, ...) stay available through `Component` and `Property`.

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

`Item` covers events, tasks, journal entries and free/busy components. The values are immutable:

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
no value can start another content line. Use `Text::escape()` for TEXT values. The factories of
[Creating calendars](creating.md) (`Calendar::create()`, `Event::new()`, ...) format and escape the
values for you.

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
