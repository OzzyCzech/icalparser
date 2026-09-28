<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use om\ICal\Parameters;
use om\ICal\Property;
use om\RRule\Rule;

/**
 * Converts PHP values to properties, the reverse of ValueParser; used by Calendar::create(),
 * Event::new() and the other factories.
 *
 * Date-time values: a DateTimeInterface with a named (IANA) timezone keeps its local time and
 * gets a TZID parameter, a UTC one is written with "Z", other ones (offsets like "+02:00" and
 * abbreviations like "CEST" are not timezones) are converted to UTC. A DateTimeValue keeps its
 * kind (DATE, floating, UTC, zoned), a string is an iCalendar value like "20260105T093000Z".
 *
 * @internal
 * @phpstan-type DateInput DateTimeInterface|DateTimeValue|string
 * @phpstan-type PropertyValue string|int|float|bool|DateTimeInterface|DateTimeValue|DateInterval|Rule|CalAddress|Period|array{float, float}|list<string|DateTimeInterface|DateTimeValue|Period>
 * @phpstan-type PropertyList array<int|string, Property|PropertyValue>
 */
final class PropertyFactory {
	/** Names of UTC in PHP, written with "Z". */
	private const array UTC = ['UTC', 'Z', 'GMT', '+00:00', 'Etc/UTC', 'Etc/GMT', 'Etc/Universal', 'Etc/Zulu', 'Etc/UCT', 'UCT', 'Universal', 'Zulu'];

	/** Properties whose DATE-TIME value must be in UTC (RFC 5545, section 3.8.7, RFC 9074). */
	public const array UTC_PROPERTIES = ['DTSTAMP' => true, 'CREATED' => true, 'LAST-MODIFIED' => true, 'COMPLETED' => true, 'ACKNOWLEDGED' => true];

	/**
	 * A DATE or DATE-TIME value of a PHP value, see the class description.
	 *
	 * @param DateInput $value
	 * @throws InvalidArgumentException for an invalid string
	 */
	public static function dateTimeValue(DateTimeInterface|DateTimeValue|string $value): DateTimeValue {
		if (is_string($value)) {
			return DateTimeValue::parse($value, strlen(trim($value)) === 8);
		}
		if ($value instanceof DateTimeValue) {
			if (!$value->isZoned()) {
				return $value->isFloating() && $value->tzid !== null ? DateTimeValue::floating($value->base()) : $value;
			}
			$timezone = $value->timezone() ?? new DateTimeZone('UTC');
			// the TZID must name the timezone, e.g. a resolved Windows TZID is written as its IANA zone
			$tzid = $value->tzid !== null && self::isTimezoneName($value->tzid) ? $value->tzid : $timezone->getName();
			return self::isTimezoneName($tzid) ? DateTimeValue::fromDateTime($value->toDateTime(), $tzid) : DateTimeValue::fromDateTime($value->toDateTime(new DateTimeZone('UTC')));
		}
		$value = DateTimeImmutable::createFromInterface($value);
		$name = $value->getTimezone()->getName();
		if (in_array($name, self::UTC, true) || !self::isTimezoneName($name)) {
			return DateTimeValue::fromDateTime($value->setTimezone(new DateTimeZone('UTC')));
		}
		return DateTimeValue::fromDateTime($value, $name);
	}

	/**
	 * A UTC instant, e.g. for DTSTAMP; dates and floating times are rejected.
	 *
	 * @param DateInput $value
	 * @throws InvalidArgumentException
	 */
	public static function utcValue(DateTimeInterface|DateTimeValue|string $value, string $name): DateTimeValue {
		$value = self::dateTimeValue($value);
		if ($value->isDate() || $value->isFloating()) {
			throw new InvalidArgumentException("$name must be an instant (a UTC or zoned time), $value given.");
		}
		return DateTimeValue::fromDateTime($value->toDateTime(new DateTimeZone('UTC')));
	}

	/**
	 * DATE-TIME property: TZID for zoned times, VALUE=DATE for dates; UTC for DTSTAMP, CREATED, ....
	 *
	 * @param DateInput $value
	 * @param array<string, string|list<string>> $parameters
	 */
	public static function dateTime(string $name, DateTimeInterface|DateTimeValue|string $value, array $parameters = []): Property {
		$name = strtoupper($name);
		$value = isset(self::UTC_PROPERTIES[$name]) ? self::utcValue($value, $name) : self::dateTimeValue($value);
		return Property::create($name, (string) $value, [...self::dateParameters($value), ...$parameters]);
	}

	/**
	 * EXDATE or RDATE: values of the same kind share one property (RDATE also takes periods).
	 *
	 * @param iterable<DateTimeInterface|DateTimeValue|Period|string> $values
	 * @return list<Property>
	 */
	public static function dateTimes(string $name, iterable $values): array {
		$groups = [];
		foreach ($values as $value) {
			if ($value instanceof Period) {
				$start = self::dateTimeValue($value->start);
				$end = $value->duration !== null ? self::duration($value->duration) : (string) self::sameKind(self::dateTimeValue($value->end), $start, "The end of the period $value");
				$parameters = [...self::dateParameters($start), 'VALUE' => 'PERIOD'];
				$text = $start . '/' . $end;
			} else {
				$value = self::dateTimeValue($value);
				$parameters = self::dateParameters($value);
				$text = (string) $value;
			}
			$key = (string) Parameters::from($parameters);
			$groups[$key] ??= [$parameters, []];
			$groups[$key][1][] = $text;
		}
		return array_values(array_map(static fn(array $group): Property => Property::create($name, implode(',', $group[1]), $group[0]), $groups));
	}

	/**
	 * A DURATION value; months and years are not allowed (RFC 5545, section 3.3.6).
	 *
	 * @throws InvalidArgumentException
	 */
	public static function duration(DateInterval|string $duration): string {
		if (is_string($duration)) {
			if (Duration::parse($duration) === null) {
				throw new InvalidArgumentException("Invalid DURATION value: $duration");
			}
			return strtoupper(trim($duration));
		}
		if (($duration->y || $duration->m) && $duration->days === false) {
			throw new InvalidArgumentException('A DURATION cannot have months or years, use days or weeks.');
		}
		return Duration::format($duration);
	}

	/**
	 * A validated recurrence rule.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function rule(Rule|string $rule): Rule {
		return is_string($rule) ? Rule::fromString($rule) : Rule::fromString($rule->toString());
	}

	/**
	 * @param array<string, string|list<string>> $parameters
	 */
	public static function text(string $name, string $value, array $parameters = []): Property {
		return Property::create($name, Text::escape($value), $parameters);
	}

	/**
	 * A list of TEXT values (CATEGORIES, RESOURCES, LOCATION-TYPE): every item is escaped.
	 *
	 * @param iterable<string> $values
	 */
	public static function texts(string $name, iterable $values): ?Property {
		$escaped = [];
		foreach ($values as $value) {
			$escaped[] = Text::escape($value);
		}
		return $escaped === [] ? null : Property::create($name, implode(',', $escaped));
	}

	/**
	 * A URI; whitespace and control characters are not allowed.
	 *
	 * @param array<string, string|list<string>>|Parameters $parameters
	 */
	public static function uri(string $name, string $uri, array|Parameters $parameters = []): Property {
		$trimmed = trim($uri);
		if ($trimmed === '' || preg_match('/[\x00-\x20\x7F]/', $trimmed)) {
			throw new InvalidArgumentException("Invalid URI in $name: $uri");
		}
		return Property::create($name, $trimmed, $parameters);
	}

	public static function calAddress(string $name, CalAddress|string $address): Property {
		$address = is_string($address) ? CalAddress::create($address) : $address;
		return self::uri($name, $address->uri, $address->parameters);
	}

	/**
	 * GEO: latitude and longitude with at most six decimals.
	 *
	 * @param array{float|int, float|int} $geo
	 */
	public static function geo(array $geo): Property {
		[$latitude, $longitude] = $geo;
		if (abs($latitude) > 90 || abs($longitude) > 180) {
			throw new InvalidArgumentException('GEO must be [latitude, longitude] within ±90 and ±180 degrees.');
		}
		return Property::create('GEO', self::float((float) $latitude) . ';' . self::float((float) $longitude));
	}

	public static function image(Image|string $image): Property {
		if (is_string($image)) {
			return self::uri('IMAGE', $image, ['VALUE' => 'URI']);
		}
		$parameters = $image->parameters->with('VALUE', $image->isBinary() ? 'BINARY' : 'URI');
		return $image->isBinary()
			? new Property('IMAGE', $parameters->with('ENCODING', 'BASE64'), base64_encode((string) $image->data))
			: self::uri('IMAGE', (string) $image->uri, $parameters);
	}

	public static function conference(Conference|string $conference): Property {
		return is_string($conference)
			? self::uri('CONFERENCE', $conference, ['VALUE' => 'URI'])
			: self::uri('CONFERENCE', $conference->uri, $conference->parameters->with('VALUE', 'URI'));
	}

	/**
	 * LINK (RFC 9253): a URI, or a UID or an XML reference by the VALUE parameter of the Link.
	 */
	public static function link(Link|string $link): Property {
		if (is_string($link)) {
			return self::uri('LINK', $link);
		}
		return $link->valueType() === 'UID'
			? new Property('LINK', $link->parameters, Text::escape($link->value))
			: self::uri('LINK', $link->value, $link->parameters);
	}

	/**
	 * RELATED-TO: a UID (default), or a URI by the VALUE parameter of the Relation.
	 */
	public static function relation(Relation|string $relation): Property {
		if (is_string($relation)) {
			return self::text('RELATED-TO', $relation);
		}
		return $relation->valueType() === 'URI'
			? self::uri('RELATED-TO', $relation->value, $relation->parameters)
			: new Property('RELATED-TO', $relation->parameters, Text::escape($relation->value));
	}

	/**
	 * Properties of the "properties:" argument: Property objects, or name => value pairs where
	 * a string is the raw value and other PHP values are converted by their type (a VALUE
	 * parameter is added when it is not the default type of the property, see ValueParser::TYPES).
	 *
	 * @param PropertyList $properties
	 * @return list<Property>
	 * @throws InvalidArgumentException
	 */
	public static function properties(array $properties): array {
		$result = [];
		foreach ($properties as $name => $value) {
			if ($value instanceof Property) {
				$property = $value;
			} elseif (is_int($name)) {
				throw new InvalidArgumentException('A property without a name must be a Property object.');
			} else {
				$property = self::value($name, $value);
			}
			if (in_array($property->name, ['BEGIN', 'END'], true)) {
				throw new InvalidArgumentException("$property->name is not a property, use the components.");
			}
			$result[] = $property;
		}
		return $result;
	}

	/**
	 * A property of a PHP value (PropertyValue), see properties().
	 *
	 * @throws InvalidArgumentException
	 */
	public static function value(string $name, mixed $value): Property {
		$name = strtoupper($name);
		$default = ValueParser::TYPES[$name] ?? 'TEXT';
		[$type, $property] = match (true) {
			is_string($value) => [$default, Property::create($name, $value)],
			$value instanceof DateTimeInterface, $value instanceof DateTimeValue => ['DATE-TIME', self::dateTime($name, $value)],
			$value instanceof Period => ['PERIOD', self::dateTimes($name, [$value])[0]->withParameter('VALUE', null)],
			$value instanceof DateInterval => ['DURATION', Property::create($name, self::duration($value))],
			$value instanceof Rule => ['RECUR', Property::create($name, self::rule($value)->toString())],
			$value instanceof CalAddress => ['CAL-ADDRESS', self::calAddress($name, $value)],
			is_bool($value) => ['BOOLEAN', Property::create($name, $value ? 'TRUE' : 'FALSE')],
			is_int($value) && $default === 'FLOAT' => ['FLOAT', Property::create($name, (string) $value)],
			is_int($value) => ['INTEGER', Property::create($name, (string) $value)],
			is_float($value) => ['FLOAT', Property::create($name, self::float($value))],
			is_array($value) && $name === 'GEO' => ['FLOAT', self::geo(self::pair($value))],
			is_array($value) => self::listValue($name, $value, $default),
			default => throw new InvalidArgumentException("Unsupported value of $name: " . get_debug_type($value)),
		};
		if ($type === 'DATE-TIME' && $property->parameter('VALUE') === 'DATE') {
			$type = 'DATE';
		}
		if ($type !== $default && $property->parameter('VALUE') === null && !($type === 'DATE' && $default === 'DATE-TIME')) {
			$property = $property->withParameter('VALUE', $type);
		}
		return $property;
	}

	/**
	 * Timezone name that other programs understand: an IANA identifier (also a backward link like
	 * US/Eastern), not an offset or an abbreviation.
	 */
	public static function isTimezoneName(string $name): bool {
		return !in_array($name, self::UTC, true) && in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
	}

	/**
	 * A value of the same kind (DATE or DATE-TIME) as the reference.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function sameKind(DateTimeValue $value, DateTimeValue $reference, string $what): DateTimeValue {
		if ($value->isDate() !== $reference->isDate()) {
			throw new InvalidArgumentException(sprintf('%s must be a %s like the start.', $what, $reference->isDate() ? 'DATE' : 'DATE-TIME'));
		}
		return $value;
	}

	/**
	 * @return array<string, string>
	 */
	private static function dateParameters(DateTimeValue $value): array {
		return match (true) {
			$value->isDate() => ['VALUE' => 'DATE'],
			$value->isZoned() && $value->tzid !== null => ['TZID' => $value->tzid],
			default => [],
		};
	}

	/**
	 * @param array<mixed> $values
	 * @return array{string, Property}
	 */
	private static function listValue(string $name, array $values, string $default): array {
		if ($values === [] || !array_is_list($values)) {
			throw new InvalidArgumentException("The value of $name must be a non-empty list.");
		}
		if (array_filter($values, is_string(...)) === $values && !in_array($default, ['DATE-TIME', 'PERIOD'], true)) {
			return ['TEXT', self::texts($name, $values) ?? throw new InvalidArgumentException("Empty list in $name.")];
		}
		foreach ($values as $value) {
			if (!$value instanceof DateTimeInterface && !$value instanceof DateTimeValue && !$value instanceof Period && !is_string($value)) {
				throw new InvalidArgumentException("Unsupported value in the list of $name: " . get_debug_type($value));
			}
		}
		$properties = self::dateTimes($name, $values);
		if (count($properties) > 1) {
			throw new InvalidArgumentException("The values of $name have different types or timezones, use Property objects.");
		}
		return [$properties[0]->parameter('VALUE') ?? 'DATE-TIME', $properties[0]];
	}

	/**
	 * @param array<mixed> $value
	 * @return array{float|int, float|int}
	 */
	private static function pair(array $value): array {
		if (count($value) !== 2 || !array_is_list($value) || !is_int($value[0]) && !is_float($value[0]) || !is_int($value[1]) && !is_float($value[1])) {
			throw new InvalidArgumentException('GEO must be [latitude, longitude].');
		}
		return [$value[0], $value[1]];
	}

	private static function float(float $value): string {
		$formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
		return $formatted === '-0' ? '0' : $formatted;
	}
}
