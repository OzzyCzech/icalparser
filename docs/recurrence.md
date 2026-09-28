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
- an invalid RRULE is ignored in permissive mode; the item keeps DTSTART and RDATE,
- an RRULE of another calendar system than Gregorian is not expanded (see [calendar systems](#calendar-systems)).

## Rules

`om\RRule\Rule` validates every rule part: FREQ (SECONDLY to YEARLY), INTERVAL, COUNT, UNTIL,
BYSECOND, BYMINUTE, BYHOUR, BYDAY (with ordinals), BYMONTHDAY, BYYEARDAY, BYWEEKNO, BYMONTH,
BYSETPOS and WKST, and RSCALE, SKIP and leap months (`BYMONTH=5L`) of RFC 7529. `om\RRule\Expander` generates timestamps: every FREQ period is expanded to
candidate days and times, the BY parts filter them (covering the "expand" and "limit" columns of
the RFC table) and BYSETPOS selects from the sorted period.

- calculations use the wall-clock time of DTSTART, so DST changes keep the local time,
- invalid dates such as February 30 are skipped, unless SKIP (below) moves them,
- UNTIL of a date or floating DTSTART is local, a date-only UNTIL includes the whole day,
- a rule that cannot produce any further occurrence ends after an empty 400-year Gregorian cycle.

## Invalid dates and SKIP

RFC 5545 drops instances on invalid dates: a monthly rule starting on January 31 skips February,
April, June, ... and a yearly rule starting on February 29 recurs every four years. RFC 7529 adds
the `SKIP` rule part (together with `RSCALE`) to move such instances instead:

| Rule (DTSTART 2026-01-31) | Instances |
|---------------------------|-----------|
| `FREQ=MONTHLY;COUNT=3` | 01-31, 03-31, 05-31 |
| `FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=OMIT;COUNT=3` | 01-31, 03-31, 05-31 (the default) |
| `FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=3` | 01-31, 02-28, 03-31 (the last day of the month) |
| `FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=3` | 01-31, 03-01, 03-31 (the first day of the next month) |

`RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=FORWARD` from 2012-02-29 gives 2013-03-01, 2014-03-01, 2015-03-01,
2016-02-29, ... (RFC 7529, section 4.3.4).

- SKIP applies to MONTHLY and YEARLY rules, to the day of DTSTART and to positive BYMONTHDAY values;
  negative BYMONTHDAY values and rules with BYYEARDAY or BYWEEKNO select existing days only,
- the order of RFC 7529, section 4.1 is kept: BYMONTH, BYMONTHDAY, SKIP, BYDAY, the times,
  BYSETPOS, COUNT and UNTIL; instances moved to the same day are one instance,
- `SKIP` without `RSCALE` is invalid: strict mode throws `InvalidRecurrenceRuleException`
  (`recurrence.skip-without-rscale`), permissive mode ignores `SKIP` with a `value.invalid` warning.

## Calendar systems

Only the Gregorian calendar is supported: rules without `RSCALE` and with `RSCALE=GREGORIAN`.
Other calendar systems of RFC 7529 (`CHINESE`, `HEBREW`, `ISLAMIC-CIVIL`, `ETHIOPIC`, ...) and leap
months (`BYMONTH=5L`) would give wrong dates when expanded as Gregorian, so such a rule is not expanded:

- the rule is parsed and available in `recurrenceRules()`, and serialized unchanged,
- the item keeps DTSTART and RDATE (RFC 7529, section 6: the non-recurring fallback); other RRULE
  properties of the item are expanded,
- permissive mode reports a `recurrence.unsupported-rscale` warning with the line and the RSCALE value,
  strict mode throws `InvalidRecurrenceRuleException` with that code, the validator reports a warning,
- `Expander` and `RecurrenceSet` throw `InvalidRecurrenceRuleException`; check `Rule::isGregorian()` first.

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
