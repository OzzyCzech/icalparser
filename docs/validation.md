# Validation

The parser builds the model without judging it; the validator checks RFC 5545 semantics:

```php
use om\ICal\Validation\Severity;
use om\ICal\Validation\Validator;

foreach ((new Validator())->validate($calendar) as $issue) {
	echo $issue;  // ERROR VEVENT.DURATION (line 12): DTEND and DURATION must not occur together. [component.end-and-duration]
}
(new Validator())->assertValid($calendar); // ValidationException with the first error
```

An `Issue` has a `Severity` (`Info`, `Warning`, `Error`), a `code`, a `message`, the `component`,
the `property`, the `line` and the `uid`.

| Code | Severity | Rule |
|------|----------|------|
| `component.missing-property` | Error | PRODID and VERSION of VCALENDAR, UID and DTSTAMP of VEVENT, VTODO, VJOURNAL and VFREEBUSY, TZID of VTIMEZONE, DTSTART, TZOFFSETFROM and TZOFFSETTO of observances, ACTION and TRIGGER of VALARM |
| `component.duplicate-property` | Error | a property that may occur only once occurs again |
| `component.end-and-duration` | Error | DTEND (DUE) together with DURATION |
| `component.end-type` | Error | DTEND (DUE) has another value type than DTSTART |
| `component.end-before-start` | Error | DTEND (DUE) is before DTSTART |
| `component.duration-without-start` | Error | DURATION without DTSTART |
| `recurrence.count-and-until` | Error | COUNT together with UNTIL |
| `recurrence.until-type` | Error | UNTIL is not a date for a date DTSTART, not UTC for a UTC or zoned DTSTART, not local for a floating DTSTART |
| `recurrence.without-start` | Error | RRULE or RDATE without DTSTART |
| `recurrence.multiple-rrule` | Warning | more than one RRULE |
| `value.invalid` | Error | a value that does not match its type |
| `value.not-utc` | Warning | ACKNOWLEDGED of VALARM (RFC 9074) that is not a UTC time |
| `timezone.not-defined` | Warning | a TZID without a VTIMEZONE definition |
| `timezone.unresolved` | Warning | a TZID that cannot be resolved |
| `timezone.no-observance` | Error | a VTIMEZONE without STANDARD and DAYLIGHT |
| `alarm.outside-item` | Error | a VALARM directly in VCALENDAR |
