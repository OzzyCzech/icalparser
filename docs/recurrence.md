# Recurrence

```php
foreach ($calendar->events() as $event) {
	foreach ($event->occurrencesBetween($from, $to) as $occurrence) {
		$occurrence->start;          // DateTimeValue
		$occurrence->end;
		$occurrence->startTime();    // DateTimeImmutable
		$occurrence->item;           // the event, or its override
		$occurrence->recurrenceId;   // the original start of a recurring instance
		$occurrence->isModified();
	}
}
$calendar->occurrencesBetween($from, $to);  // all events, sorted
$event->occurrences(limit: 10);             // the first ten, lazily
```

There is no method expanding a series without a window or a limit. Occurrences are generated
lazily, so iterating stops the calculation as soon as the loop ends.

## The recurrence set

An item recurs with RRULE or RDATE (RFC 5545, section 3.8.5). The set is DTSTART, the RRULE
occurrences and the RDATE values, minus EXDATE values, sorted and without duplicates:

- DTSTART is always the first instance, even when it does not match the rule, and counts for COUNT,
- several RRULE properties are combined (RFC 2445 allowed that, the validator warns),
- a date-only EXDATE removes all instances of that day,
- an invalid RRULE is ignored in permissive mode; the item keeps DTSTART and RDATE.

## Rules

`om\RRule\Rule` validates every rule part: FREQ (SECONDLY to YEARLY), INTERVAL, COUNT, UNTIL,
BYSECOND, BYMINUTE, BYHOUR, BYDAY (with ordinals), BYMONTHDAY, BYYEARDAY, BYWEEKNO, BYMONTH,
BYSETPOS and WKST. `om\RRule\Expander` generates timestamps: every FREQ period is expanded to
candidate days and times, the BY parts filter them (covering the "expand" and "limit" columns of
the RFC table) and BYSETPOS selects from the sorted period.

- calculations use the wall-clock time of DTSTART, so DST changes keep the local time,
- invalid dates such as February 30 are skipped,
- UNTIL of a date or floating DTSTART is local, a date-only UNTIL includes the whole day,
- a rule that cannot produce any further occurrence ends after an empty 400-year Gregorian cycle.

## Series

Components with the same UID form a series. A component with RECURRENCE-ID replaces the instance
starting at that time (compared as an instant in any timezone, or as a day for date-only IDs):

- a moved instance is returned at its new time, also when it moved into or out of the window,
- `RecurrenceLimits::$maxInstances` counts the returned occurrences, not the instances before the window,
- `STATUS:CANCELLED` instances are skipped unless `includeCancelled: true` is passed,
- `RECURRENCE-ID;RANGE=THISANDFUTURE` changes the instance and all later ones: their local time
  changes like the one of the override (also over DST changes) and they get its duration; an
  override without DTSTART keeps the time and duration of the instances,
- a moved instance may move into the window from outside of it, in both directions,
- an override without its recurring item is a single item.

## Durations

The end of an instance is its start plus the duration of the item: DTEND - DTSTART (exact elapsed
time, RFC 5545, section 3.8.5.3), DURATION (days keep the local time), one day for all-day events,
DUE - DTSTART for tasks.

## Verification

The engine is tested with the examples of RFC 5545, property-based tests (sorted, unique, within
UNTIL and COUNT, EXDATE, serialization) and differential tests against
python-dateutil (`composer test:differential`); its known deviations from the RFC are documented
in `tests/Differential`.
