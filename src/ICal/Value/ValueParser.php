<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;
use om\ICal\Component;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\InvalidValueException;
use om\ICal\Property;
use om\ICal\Timezone\CompositeTimezoneResolver;
use om\ICal\Timezone\ResolvedTimezone;
use om\ICal\Timezone\TimezoneResolver;
use om\RRule\Rule;

/**
 * Converts raw property values to typed values (RFC 5545, section 3.3).
 *
 * In strict mode an invalid value throws InvalidValueException; otherwise the accessor
 * returns null (or skips the invalid item of a list).
 */
final class ValueParser {
	/** Default value types of properties (RFC 5545, section 3.8), TEXT otherwise. */
	public const array TYPES = [
		'DTSTART' => 'DATE-TIME', 'DTEND' => 'DATE-TIME', 'DUE' => 'DATE-TIME', 'DTSTAMP' => 'DATE-TIME',
		'CREATED' => 'DATE-TIME', 'LAST-MODIFIED' => 'DATE-TIME', 'COMPLETED' => 'DATE-TIME',
		'RECURRENCE-ID' => 'DATE-TIME', 'EXDATE' => 'DATE-TIME', 'RDATE' => 'DATE-TIME',
		'DURATION' => 'DURATION', 'TRIGGER' => 'DURATION', 'FREEBUSY' => 'PERIOD',
		'SEQUENCE' => 'INTEGER', 'PRIORITY' => 'INTEGER', 'PERCENT-COMPLETE' => 'INTEGER', 'REPEAT' => 'INTEGER',
		'RRULE' => 'RECUR', 'EXRULE' => 'RECUR', 'ATTENDEE' => 'CAL-ADDRESS', 'ORGANIZER' => 'CAL-ADDRESS',
		'URL' => 'URI', 'TZURL' => 'URI', 'ATTACH' => 'URI', 'GEO' => 'FLOAT',
		'TZOFFSETFROM' => 'UTC-OFFSET', 'TZOFFSETTO' => 'UTC-OFFSET',
	];

	/** Properties with a list of values. */
	private const array LISTS = ['EXDATE' => true, 'RDATE' => true, 'FREEBUSY' => true, 'CATEGORIES' => true, 'RESOURCES' => true];

	/** @var array<string, ?ResolvedTimezone> */
	private array $timezones = [];
	/** @var list<array{string, string}>|null problems found by diagnose() */
	private ?array $problems = null;
	private readonly TimezoneResolver $resolver;

	/**
	 * @param Component $calendar the VCALENDAR component, used to resolve TZID parameters
	 * @param ?TimezoneResolver $resolver CompositeTimezoneResolver::default() when null
	 */
	public function __construct(
		private readonly Component $calendar = new Component('VCALENDAR'),
		?TimezoneResolver $resolver = null,
		private readonly bool $strict = false,
	) {
		$this->resolver = $resolver ?? CompositeTimezoneResolver::default();
	}

	/**
	 * Value type from the VALUE parameter or the default type of the property.
	 */
	public static function type(Property $property): string {
		return strtoupper($property->parameter('VALUE') ?? self::TYPES[$property->name] ?? 'TEXT');
	}

	/**
	 * Value converted according to its type: DateTimeValue (list for EXDATE and RDATE), Period list,
	 * DateInterval, int, float, bool, Rule, CalAddress, GEO pair, list of TEXT (CATEGORIES) or string.
	 */
	public function value(Property $property): mixed {
		$list = isset(self::LISTS[$property->name]);
		return match (self::type($property)) {
			'DATE-TIME', 'DATE' => $list ? $this->dateTimes($property) : $this->dateTime($property),
			'PERIOD' => $this->periods($property),
			'DURATION' => $this->duration($property),
			'INTEGER' => $this->integer($property),
			'FLOAT' => $property->name === 'GEO' ? $this->geo($property) : $this->float($property),
			'BOOLEAN' => $this->boolean($property),
			'RECUR' => $this->recur($property),
			'CAL-ADDRESS' => $this->calAddress($property),
			'UTC-OFFSET' => $this->utcOffset($property),
			'BINARY' => $this->binary($property),
			'URI' => $this->uri($property),
			default => $list ? $this->texts($property) : $this->text($property),
		};
	}

	public function text(Property $property): string {
		return Text::unescape($this->decoded($property));
	}

	/**
	 * @return list<string>
	 */
	public function texts(Property $property): array {
		return Text::split($this->decoded($property));
	}

	/**
	 * The value, decoded when it has ENCODING=QUOTED-PRINTABLE (vCalendar 1.0, not RFC 5545).
	 */
	private function decoded(Property $property): string {
		if (strtoupper($property->parameter('ENCODING') ?? '') !== 'QUOTED-PRINTABLE') {
			return $property->value;
		}
		$this->nonstandard('The value uses ENCODING=QUOTED-PRINTABLE of vCalendar 1.0, it was decoded.', $property);
		return quoted_printable_decode($property->value);
	}

	public function dateTime(Property $property): ?DateTimeValue {
		return $this->dateTimes($property)[0] ?? null;
	}

	/**
	 * All DATE and DATE-TIME values; a PERIOD is represented by its start.
	 *
	 * @return list<DateTimeValue>
	 */
	public function dateTimes(Property $property): array {
		$result = [];
		foreach (explode(',', $property->value) as $item) {
			$this->periodSuffix($item, $property);
			$value = $this->parseDate(explode('/', $item, 2)[0], $property);
			if ($value !== null) {
				$result[] = $value;
			}
		}
		return $result;
	}

	/**
	 * @return list<Period>
	 */
	public function periods(Property $property): array {
		$result = [];
		foreach (explode(',', $property->value) as $item) {
			[$start, $end] = array_pad(explode('/', $item, 2), 2, '');
			$start = $this->parseDate($start, $property);
			if ($start === null) {
				continue;
			}
			$duration = Duration::parse($end);
			$end = $duration !== null ? $start->add($duration) : $this->parseDate($end, $property);
			if ($end !== null) {
				$result[] = new Period($start, $end, $duration);
			}
		}
		return $result;
	}

	public function duration(Property $property): ?DateInterval {
		return Duration::parse($property->value) ?? $this->invalid('DURATION', $property);
	}

	public function integer(Property $property): ?int {
		$value = trim($property->value);
		return preg_match('/^[+-]?\d{1,18}$/D', $value) ? (int) $value : $this->invalid('INTEGER', $property);
	}

	public function float(Property $property): ?float {
		$value = trim($property->value);
		return preg_match('/^[+-]?\d+(\.\d+)?$/D', $value) ? (float) $value : $this->invalid('FLOAT', $property);
	}

	public function boolean(Property $property): ?bool {
		return match (strtoupper(trim($property->value))) {
			'TRUE' => true,
			'FALSE' => false,
			default => $this->invalid('BOOLEAN', $property),
		};
	}

	/**
	 * GEO value as [latitude, longitude].
	 *
	 * @return array{float, float}|null
	 */
	public function geo(Property $property): ?array {
		$parts = explode(';', $property->value);
		return count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])
			? [(float) $parts[0], (float) $parts[1]]
			: $this->invalid('GEO', $property);
	}

	public function uri(Property $property): string {
		return trim($property->value);
	}

	public function binary(Property $property): ?string {
		$decoded = base64_decode($property->value, true);
		return $decoded === false ? $this->invalid('BINARY', $property) : $decoded;
	}

	public function calAddress(Property $property): CalAddress {
		return new CalAddress(trim($property->value), $property->parameters);
	}

	public function utcOffset(Property $property): ?int {
		try {
			return UtcOffset::parse($property->value);
		} catch (InvalidValueException $e) {
			return $this->fail($e, $property);
		}
	}

	public function recur(Property $property): ?Rule {
		try {
			return Rule::fromString($property->value);
		} catch (InvalidRecurrenceRuleException $e) {
			if ($this->strict) {
				throw InvalidRecurrenceRuleException::create($e->errorCode(), $e->getMessage(), $property->line, $property->name, $property->value, $e);
			}
			if ($e->errorCode() === 'recurrence.skip-without-rscale') {
				// the rule without SKIP is the rule of RFC 5545, which omits invalid dates
				$this->problem('value.invalid', $e->getMessage() . ' SKIP was ignored.');
				return $this->recur($property->withValue((string) preg_replace('/(?:^|;)SKIP=[^;]*/i', '', $property->value)));
			}
			$this->problem('value.invalid', $e->getMessage() . ' The RRULE was ignored.');
			return null;
		}
	}

	/**
	 * Problems of a value as [code, message] pairs: "value.invalid" for an invalid value (null in
	 * permissive mode), "value.nonstandard" for a value accepted although it breaks the RFC.
	 *
	 * @return list<array{string, string}>
	 */
	public function diagnose(Property $property): array {
		$this->problems = [];
		try {
			$type = self::type($property);
			if ($type === 'DATE-TIME' || $type === 'DATE') {
				$this->checkDates($property); // the same checks as dateTimes(), without creating objects
			} elseif ($type !== 'CAL-ADDRESS' && $type !== 'URI') {
				$this->value($property);
			}
			return $this->problems ?? [];
		} finally {
			$this->problems = null;
		}
	}

	/**
	 * Timezone of a TZID parameter, see TimezoneResolver.
	 */
	public function timezone(string $tzid): ?ResolvedTimezone {
		if (!array_key_exists($tzid, $this->timezones)) {
			$this->timezones[$tzid] = $this->resolver->resolve($tzid, $this->calendar);
		}
		return $this->timezones[$tzid];
	}

	private function checkDates(Property $property): void {
		$tzid = $property->parameter('TZID');
		if ($tzid !== null) {
			$this->timezone($tzid);
		}
		foreach (explode(',', $property->value) as $item) {
			$this->periodSuffix($item, $property);
			$value = $this->normalizeDate(explode('/', $item, 2)[0], $property, $date);
			if (!DateTimeValue::isValid($value, $date)) {
				$this->fail(InvalidValueException::create('value.invalid-date-time', 'Invalid DATE-TIME value: ' . $value, rawValue: $value), $property);
			}
		}
	}

	/**
	 * Repairs of the permissive mode: a date with "Z", VALUE=DATE with a time. Reports leap seconds.
	 *
	 * @param-out bool $date whether the value is read as a DATE
	 */
	private function normalizeDate(string $value, Property $property, ?bool &$date): string {
		$repaired = false;
		if (!$this->strict && preg_match('/^\s*\d{8}Z\s*$/Di', $value)) {
			$value = substr(trim($value), 0, 8); // a date with "Z" (written by Google) is a date
			$this->problem('value.nonstandard', "The date $value has a \"Z\" suffix, it was read as a date.");
			$repaired = true;
		}
		$date = strtoupper($property->parameter('VALUE') ?? '') === 'DATE';
		if ($date && !$this->strict && preg_match('/^\s*\d{8}T\d{6}Z?\s*$/Di', $value)) {
			$date = false;
			$this->problem('value.nonstandard', "The value $value has VALUE=DATE and a time, it was read as a DATE-TIME.");
		}
		$trimmed = strtoupper(trim($value));
		if (!$date && !$repaired && preg_match('/^\d{8}$/D', $trimmed)) {
			$this->nonstandard("The date $value has no VALUE=DATE parameter.", $property);
		}
		if (str_ends_with($trimmed, 'Z') && strlen($trimmed) === 16 && $property->parameter('TZID') !== null) {
			$this->nonstandard("The UTC value $value has a TZID parameter, it was ignored.", $property);
		}
		if (preg_match('/T\d{4}60Z?$/D', $trimmed)) {
			$this->problem('value.leap-second', "The leap second of $value was read as second 59.");
		}
		return $value;
	}

	private function parseDate(string $value, Property $property): ?DateTimeValue {
		$tzid = $property->parameter('TZID');
		$value = $this->normalizeDate($value, $property, $date);
		try {
			return DateTimeValue::parse($value, $date, $tzid, $tzid === null ? null : $this->timezone($tzid)?->timezone);
		} catch (InvalidValueException $e) {
			return $this->fail($e, $property);
		}
	}

	private function invalid(string $type, Property $property): null {
		return $this->fail(InvalidValueException::create('value.invalid-' . strtolower($type), "Invalid $type value", rawValue: $property->value), $property);
	}

	private function fail(InvalidValueException $e, Property $property): null {
		if ($this->strict) {
			throw InvalidValueException::create($e->errorCode(), $e->getMessage() . " in $property->name", $property->line, $property->name, $property->value, $e);
		}
		$this->problem('value.invalid', $e->getMessage() . " in $property->name, it was ignored.");
		return null;
	}

	/**
	 * A PERIOD where only RDATE;VALUE=PERIOD and FREEBUSY allow it; its start is used.
	 */
	private function periodSuffix(string $item, Property $property): void {
		if (str_contains($item, '/') && $property->name !== 'FREEBUSY' && !($property->name === 'RDATE' && strtoupper($property->parameter('VALUE') ?? '') === 'PERIOD')) {
			$this->nonstandard("The period $item is not allowed in $property->name, its start was used.", $property);
		}
	}

	/**
	 * A value breaking the RFC that is accepted in permissive mode.
	 */
	private function nonstandard(string $message, Property $property): void {
		if ($this->strict) {
			throw InvalidValueException::create('value.nonstandard', $message, $property->line, $property->name, $property->value);
		}
		$this->problem('value.nonstandard', $message);
	}

	private function problem(string $code, string $message): void {
		if ($this->problems !== null) {
			$this->problems[] = [$code, $message];
		}
	}
}
