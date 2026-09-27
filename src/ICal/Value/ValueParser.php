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
		return Text::unescape($property->value);
	}

	/**
	 * @return list<string>
	 */
	public function texts(Property $property): array {
		return Text::split($property->value);
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
			return null;
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

	private function parseDate(string $value, Property $property): ?DateTimeValue {
		$tzid = $property->parameter('TZID');
		if (!$this->strict && preg_match('/^\s*\d{8}Z\s*$/Di', $value)) {
			$value = substr(trim($value), 0, 8); // a date with "Z" (written by Google) is a date
		}
		try {
			return DateTimeValue::parse(
				$value,
				strtoupper($property->parameter('VALUE') ?? '') === 'DATE',
				$tzid,
				$tzid === null ? null : $this->timezone($tzid)?->timezone,
			);
		} catch (InvalidValueException $e) {
			return $this->fail($e, $property);
		}
	}

	private function invalid(string $type, Property $property): null {
		return $this->fail(InvalidValueException::create('value.invalid-' . strtolower($type), "Invalid $type value", $property->line, $property->name, $property->value), $property);
	}

	private function fail(InvalidValueException $e, Property $property): null {
		if ($this->strict) {
			throw InvalidValueException::create($e->errorCode(), $e->getMessage() . " in $property->name", $property->line, $property->name, $property->value, $e);
		}
		return null;
	}
}
