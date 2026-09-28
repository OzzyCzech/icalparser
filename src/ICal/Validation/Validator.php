<?php
declare(strict_types=1);

namespace om\ICal\Validation;

use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\ValidationException;
use om\ICal\Property;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\ValueParser;
use om\RRule\Rule;

/**
 * Checks RFC 5545 semantics of a parsed calendar. The parser does not validate, so
 * calendars with problems can still be read; use the validator to find them.
 *
 *     $issues = (new Validator())->validate($calendar);
 */
final class Validator {
	/** Properties that must not occur more than once (RFC 5545, section 3.6, RFC 9073 and RFC 9074). */
	private const array SINGLE = [
		'VCALENDAR' => ['PRODID', 'VERSION', 'CALSCALE', 'METHOD'],
		'VEVENT' => ['DTSTAMP', 'UID', 'DTSTART', 'CLASS', 'CREATED', 'DESCRIPTION', 'GEO', 'LAST-MODIFIED', 'LOCATION', 'ORGANIZER', 'PRIORITY', 'SEQUENCE', 'STATUS', 'SUMMARY', 'TRANSP', 'URL', 'RECURRENCE-ID', 'DTEND', 'DURATION'],
		'VTODO' => ['DTSTAMP', 'UID', 'CLASS', 'COMPLETED', 'CREATED', 'DESCRIPTION', 'DTSTART', 'GEO', 'LAST-MODIFIED', 'LOCATION', 'ORGANIZER', 'PERCENT-COMPLETE', 'PRIORITY', 'RECURRENCE-ID', 'SEQUENCE', 'STATUS', 'SUMMARY', 'URL', 'DUE', 'DURATION'],
		'VJOURNAL' => ['DTSTAMP', 'UID', 'CLASS', 'CREATED', 'DTSTART', 'LAST-MODIFIED', 'ORGANIZER', 'RECURRENCE-ID', 'SEQUENCE', 'STATUS', 'SUMMARY', 'URL'],
		'VFREEBUSY' => ['DTSTAMP', 'UID', 'CONTACT', 'DTSTART', 'DTEND', 'ORGANIZER', 'URL'],
		'VTIMEZONE' => ['TZID', 'LAST-MODIFIED', 'TZURL'],
		'VALARM' => ['ACTION', 'TRIGGER', 'DURATION', 'REPEAT', 'DESCRIPTION', 'SUMMARY', 'UID', 'ACKNOWLEDGED'],
		'VLOCATION' => ['UID', 'DESCRIPTION', 'GEO', 'LOCATION-TYPE', 'NAME'],
	];

	/** Required properties. */
	private const array REQUIRED = [
		'VCALENDAR' => ['PRODID', 'VERSION'],
		'VEVENT' => ['DTSTAMP', 'UID'],
		'VTODO' => ['DTSTAMP', 'UID'],
		'VJOURNAL' => ['DTSTAMP', 'UID'],
		'VFREEBUSY' => ['DTSTAMP', 'UID'],
		'VTIMEZONE' => ['TZID'],
		'STANDARD' => ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM'],
		'DAYLIGHT' => ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM'],
		'VALARM' => ['ACTION', 'TRIGGER'],
		'VLOCATION' => ['UID'],
	];

	/** Properties with a DATE-TIME value in UTC (RFC 9074). */
	private const array UTC = ['ACKNOWLEDGED' => true];

	/** @var list<Issue> */
	private array $issues = [];
	private ValueParser $values;
	/** @var array<string, true> */
	private array $definedTimezones = [];

	/**
	 * @return list<Issue>
	 */
	public function validate(Calendar $calendar): array {
		$this->issues = [];
		$this->values = new ValueParser($calendar->component, $calendar->timezoneResolver());
		$this->definedTimezones = [];
		foreach ($calendar->timezones() as $timezone) {
			$tzid = $timezone->tzid();
			if ($tzid !== null) {
				$this->definedTimezones[$tzid] = true;
			}
		}
		$this->component($calendar->component, null);
		return $this->issues;
	}

	/**
	 * @throws ValidationException with the first error
	 */
	public function assertValid(Calendar $calendar): void {
		foreach ($this->validate($calendar) as $issue) {
			if ($issue->severity === Severity::Error) {
				throw ValidationException::create($issue->code, $issue->message, $issue->line, $issue->property);
			}
		}
	}

	private function component(Component $component, ?string $parent): void {
		$uid = $component->property('UID')?->value;
		foreach (self::REQUIRED[$component->name] ?? [] as $name) {
			if (!$component->has($name)) {
				$this->issue(Severity::Error, 'component.missing-property', "$name is required.", $component, $name, uid: $uid);
			}
		}
		foreach (self::SINGLE[$component->name] ?? [] as $name) {
			$properties = $component->properties($name);
			if (count($properties) > 1) {
				$this->issue(Severity::Error, 'component.duplicate-property', "$name must not occur more than once.", $component, $name, $properties[1]->line, $uid);
			}
		}
		if (count($component->properties('RRULE')) > 1) {
			$this->issue(Severity::Warning, 'recurrence.multiple-rrule', 'RRULE should not occur more than once.', $component, 'RRULE', $component->properties('RRULE')[1]->line, $uid);
		}

		foreach ($component->properties as $property) {
			$this->property($property, $component, $uid);
		}

		match ($component->name) {
			'VEVENT' => $this->ends($component, 'DTEND', $uid),
			'VTODO' => $this->ends($component, 'DUE', $uid),
			'VTIMEZONE' => $component->components('STANDARD') === [] && $component->components('DAYLIGHT') === []
				? $this->issue(Severity::Error, 'timezone.no-observance', 'VTIMEZONE needs a STANDARD or DAYLIGHT component.', $component)
				: null,
			default => null,
		};
		if (in_array($component->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
			$this->recurrence($component, $uid);
		}
		if ($parent === 'VCALENDAR' && $component->name === 'VALARM') {
			$this->issue(Severity::Error, 'alarm.outside-item', 'VALARM must be inside VEVENT or VTODO.', $component);
		}

		foreach ($component->components as $child) {
			$this->component($child, $component->name);
		}
	}

	private function property(Property $property, Component $component, ?string $uid): void {
		if (isset(ValueParser::TYPES[$property->name]) || $property->parameter('VALUE') !== null) {
			$value = $this->values->value($property);
			if ($value === null || $value === []) {
				$this->issue(Severity::Error, 'value.invalid', "Invalid value \"$property->value\".", $component, $property->name, $property->line, $uid);
			} elseif (isset(self::UTC[$property->name]) && $value instanceof DateTimeValue && !$value->isUtc()) {
				$this->issue(Severity::Warning, 'value.not-utc', "$property->name must be a UTC time.", $component, $property->name, $property->line, $uid);
			}
		}
		$tzid = $property->parameter('TZID');
		if ($tzid !== null) {
			if (!isset($this->definedTimezones[$tzid])) {
				$this->issue(Severity::Warning, 'timezone.not-defined', "TZID \"$tzid\" has no VTIMEZONE definition.", $component, $property->name, $property->line, $uid);
			}
			if ($this->values->timezone($tzid) === null) {
				$this->issue(Severity::Warning, 'timezone.unresolved', "TZID \"$tzid\" cannot be resolved, its times are floating.", $component, $property->name, $property->line, $uid);
			}
		}
	}

	/**
	 * DTEND (or DUE) and DURATION are exclusive, have the value type of DTSTART and are not earlier.
	 */
	private function ends(Component $component, string $endName, ?string $uid): void {
		$end = $component->property($endName);
		$duration = $component->property('DURATION');
		if ($end !== null && $duration !== null) {
			$this->issue(Severity::Error, 'component.end-and-duration', "$endName and DURATION must not occur together.", $component, 'DURATION', $duration->line, $uid);
		}
		$start = $this->date($component->property('DTSTART'));
		if ($duration !== null && $start === null) {
			$this->issue(Severity::Error, 'component.duration-without-start', 'DURATION requires DTSTART.', $component, 'DURATION', $duration->line, $uid);
		}
		$endValue = $this->date($end);
		if ($start === null || $endValue === null || $end === null) {
			return;
		}
		if ($start->isDate() !== $endValue->isDate()) {
			$this->issue(Severity::Error, 'component.end-type', "$endName must have the same value type as DTSTART.", $component, $endName, $end->line, $uid);
		} elseif ($endValue->toDateTime(null, $start->timezone() ?? new \DateTimeZone('UTC')) < $start->toDateTime(null, $start->timezone() ?? new \DateTimeZone('UTC'))) {
			$this->issue(Severity::Error, 'component.end-before-start', "$endName must not be earlier than DTSTART.", $component, $endName, $end->line, $uid);
		}
	}

	private function recurrence(Component $component, ?string $uid): void {
		$start = $this->date($component->property('DTSTART'));
		foreach ($component->properties('RRULE') as $property) {
			$rule = $this->values->recur($property);
			if ($rule === null) {
				continue; // reported as an invalid value
			}
			if ($rule->rscale === null && preg_match('/(?:^|;)SKIP=/i', $property->value)) {
				// the parser ignores SKIP, see ValueParser::recur()
				$this->issue(Severity::Error, 'recurrence.skip-without-rscale', 'SKIP must not be present without RSCALE (RFC 7529, section 4).', $component, 'RRULE', $property->line, $uid);
			}
			try {
				$rule->assertGregorian();
			} catch (InvalidRecurrenceRuleException $e) {
				$this->issue(Severity::Warning, $e->errorCode(), $e->getMessage(), $component, 'RRULE', $property->line, $uid);
			}
			if ($rule->count !== null && $rule->until !== null) {
				$this->issue(Severity::Error, 'recurrence.count-and-until', 'COUNT and UNTIL must not occur together.', $component, 'RRULE', $property->line, $uid);
			}
			if ($start !== null && $rule->until !== null) {
				$this->until($rule, $start, $component, $property, $uid);
			}
		}
		if ($start === null && ($component->has('RRULE') || $component->has('RDATE'))) {
			$this->issue(Severity::Error, 'recurrence.without-start', 'A recurring component requires DTSTART.', $component, 'RRULE', uid: $uid);
		}
	}

	/**
	 * UNTIL is a date for date DTSTART, UTC for UTC and zoned DTSTART, local for floating DTSTART.
	 */
	private function until(Rule $rule, DateTimeValue $start, Component $component, Property $property, ?string $uid): void {
		$until = strtoupper($property->value);
		preg_match('/UNTIL=([0-9TZ]+)/', $until, $match);
		$value = $match[1] ?? '';
		$expected = match (true) {
			$start->isDate() => strlen($value) === 8,
			$start->isFloating() => strlen($value) === 15,
			default => str_ends_with($value, 'Z'),
		};
		if (!$expected) {
			$this->issue(Severity::Error, 'recurrence.until-type', 'UNTIL must have the value type of DTSTART (UTC for zoned times).', $component, 'RRULE', $property->line, $uid);
		}
	}

	private function date(?Property $property): ?DateTimeValue {
		return $property === null ? null : $this->values->dateTime($property);
	}

	private function issue(Severity $severity, string $code, string $message, Component $component, ?string $property = null, ?int $line = null, ?string $uid = null): void {
		$this->issues[] = new Issue($severity, $code, $message, $component->name, $property, $line, $uid);
	}
}
