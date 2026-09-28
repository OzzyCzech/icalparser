<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use om\ICal\Validation\Validator;
use om\ICal\Value\CalAddress;
use om\ICal\Value\Classification;
use om\ICal\Value\Conference;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use om\ICal\Value\Image;
use om\ICal\Value\Link;
use om\ICal\Value\Period;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Relation;
use om\ICal\Value\Status;
use om\ICal\Value\Transparency;
use om\RRule\Rule;

/**
 * Collects the properties of a component created by Event::new(), Alarm::display() and the other
 * factories, and checks the combinations of their arguments.
 *
 * @internal
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class ComponentBuilder {
	/** @var list<Property> */
	private array $properties = [];
	/** @var list<Component> */
	private array $components = [];

	public function __construct(
		private readonly string $name,
	) {
	}

	/**
	 * UID (random when null) and DTSTAMP (now when null) of an event, task or journal entry.
	 *
	 * @param DateTimeInterface|DateTimeValue|string|null $stamp
	 */
	public static function item(string $name, ?string $uid, DateTimeInterface|DateTimeValue|string|null $stamp): self {
		return (new self($name))
			->text('UID', self::uid($uid))
			->add(PropertyFactory::dateTime('DTSTAMP', $stamp ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))));
	}

	/**
	 * The given UID, or a random one (a UUID version 4).
	 */
	public static function uid(?string $uid): string {
		if ($uid !== null) {
			return trim($uid) === '' ? throw new InvalidArgumentException('UID must not be empty.') : $uid;
		}
		$bytes = random_bytes(16);
		$bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
		$bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	public function add(?Property ...$properties): self {
		foreach ($properties as $property) {
			if ($property !== null) {
				$this->properties[] = $property;
			}
		}
		return $this;
	}

	public function text(string $name, ?string $value): self {
		return $value === null ? $this : $this->add(PropertyFactory::text($name, $value));
	}

	public function integer(string $name, ?int $value, int $min, int $max): self {
		if ($value === null) {
			return $this;
		}
		if ($value < $min || $value > $max) {
			throw new InvalidArgumentException("$name must be between $min and $max, $value given.");
		}
		return $this->add(Property::create($name, (string) $value));
	}

	public function uri(string $name, ?string $value): self {
		return $value === null ? $this : $this->add(PropertyFactory::uri($name, $value));
	}

	/**
	 * DTSTART.
	 *
	 * @param DateTimeInterface|DateTimeValue|string|null $start
	 */
	public function start(DateTimeInterface|DateTimeValue|string|null $start): ?DateTimeValue {
		if ($start === null) {
			return null;
		}
		$this->add(PropertyFactory::dateTime('DTSTART', $start));
		return PropertyFactory::dateTimeValue($start);
	}

	/**
	 * DTEND or DUE, or DURATION: not both, not earlier than DTSTART, of the same value type.
	 *
	 * @param DateTimeInterface|DateTimeValue|string|null $end
	 */
	public function end(string $name, DateTimeInterface|DateTimeValue|string|null $end, DateInterval|string|null $duration, ?DateTimeValue $start): ?DateTimeValue {
		if ($end !== null && $duration !== null) {
			throw new InvalidArgumentException("$name and DURATION cannot be used together.");
		}
		if ($duration !== null) {
			$value = PropertyFactory::duration($duration);
			$interval = Duration::parse($value);
			if ($start === null) {
				throw new InvalidArgumentException('DURATION requires a start.');
			}
			if ($interval === null || $interval->invert) {
				throw new InvalidArgumentException("DURATION must not be negative, $value given.");
			}
			if ($start->isDate() && ($interval->h || $interval->i || $interval->s)) {
				throw new InvalidArgumentException("DURATION of an all-day start must be days or weeks, $value given.");
			}
			$this->add(Property::create('DURATION', $value));
			return $start->add($interval);
		}
		if ($end === null) {
			return null;
		}
		$value = PropertyFactory::dateTimeValue($end);
		if ($start !== null) {
			PropertyFactory::sameKind($value, $start, $name);
			if (self::compare($value, $start) < 0) {
				throw new InvalidArgumentException("$name ($value) must not be earlier than the start ($start).");
			}
		} elseif ($name === 'DTEND') {
			throw new InvalidArgumentException('DTEND requires a start.');
		}
		$this->add(PropertyFactory::dateTime($name, $value));
		return $value;
	}

	/**
	 * RRULE, RDATE, EXDATE and RECURRENCE-ID with the value type of DTSTART.
	 *
	 * @param iterable<DateTimeInterface|DateTimeValue|Period|string> $rdates
	 * @param iterable<DateTimeInterface|DateTimeValue|string> $exdates
	 * @param DateTimeInterface|DateTimeValue|string|null $recurrenceId
	 */
	public function recurrence(?DateTimeValue $start, Rule|string|null $rrule, iterable $rdates, iterable $exdates, DateTimeInterface|DateTimeValue|string|null $recurrenceId): self {
		if ($rrule !== null) {
			$rule = PropertyFactory::rule($rrule);
			if ($start === null) {
				throw new InvalidArgumentException('RRULE requires a start.');
			}
			self::checkUntil($rule, $start);
			$this->add(Property::create('RRULE', $rule->toString()));
		}
		foreach (['RDATE' => $rdates, 'EXDATE' => $exdates] as $name => $values) {
			$list = [];
			foreach ($values as $value) {
				if ($start === null) {
					throw new InvalidArgumentException("$name requires a start.");
				}
				$date = PropertyFactory::dateTimeValue($value instanceof Period ? $value->start : $value);
				PropertyFactory::sameKind($date, $start, "$name $date");
				$list[] = $value;
			}
			$this->add(...PropertyFactory::dateTimes($name, $list));
		}
		if ($recurrenceId !== null) {
			$id = PropertyFactory::dateTimeValue($recurrenceId);
			if ($start !== null) {
				PropertyFactory::sameKind($id, $start, 'RECURRENCE-ID');
			}
			$this->add(PropertyFactory::dateTime('RECURRENCE-ID', $id));
		}
		return $this;
	}

	/**
	 * STATUS allowed in the component.
	 */
	public function status(Status|string|null $status): self {
		if ($status === null) {
			return $this;
		}
		$value = $status instanceof Status ? $status : Status::tryFrom(strtoupper(trim($status)));
		if ($value === null || !in_array($value, Status::of($this->name), true)) {
			$allowed = implode(', ', array_map(static fn(Status $status): string => $status->value, Status::of($this->name)));
			throw new InvalidArgumentException(sprintf('STATUS of %s must be one of %s, %s given.', $this->name, $allowed, $status instanceof Status ? $status->value : $status));
		}
		return $this->add(Property::create('STATUS', $value->value));
	}

	/**
	 * TRANSP: OPAQUE or TRANSPARENT.
	 */
	public function transparency(Transparency|string|null $transparency): self {
		if ($transparency === null) {
			return $this;
		}
		$value = $transparency instanceof Transparency ? $transparency : Transparency::tryFrom(strtoupper(trim($transparency)))
			?? throw new InvalidArgumentException("TRANSP must be OPAQUE or TRANSPARENT, $transparency given.");
		return $this->add(Property::create('TRANSP', $value->value));
	}

	/**
	 * CLASS: PUBLIC, PRIVATE, CONFIDENTIAL, or an IANA token or X- name.
	 */
	public function classification(Classification|string|null $classification): self {
		if ($classification === null) {
			return $this;
		}
		$value = $classification instanceof Classification ? $classification->value : strtoupper(trim($classification));
		if (!preg_match('/^[A-Z0-9-]+$/D', $value)) {
			throw new InvalidArgumentException("Invalid CLASS value: $value");
		}
		return $this->add(Property::create('CLASS', $value));
	}

	/**
	 * Properties common to events, tasks and journal entries.
	 *
	 * @param iterable<string> $categories
	 * @param iterable<CalAddress|string> $attendees
	 * @param iterable<Image|string> $images
	 * @param iterable<Conference|string> $conferences
	 * @param iterable<Link|string> $links
	 * @param iterable<Relation|string> $relatedTo
	 * @param array{float|int, float|int}|null $geo
	 */
	public function descriptive(
		?string $summary = null,
		?string $description = null,
		?string $location = null,
		?string $url = null,
		Classification|string|null $classification = null,
		?int $priority = null,
		?int $sequence = null,
		iterable $categories = [],
		CalAddress|string|null $organizer = null,
		iterable $attendees = [],
		?array $geo = null,
		?string $color = null,
		iterable $images = [],
		iterable $conferences = [],
		iterable $links = [],
		iterable $relatedTo = [],
	): self {
		$this->text('SUMMARY', $summary)->text('DESCRIPTION', $description)->text('LOCATION', $location)
			->add($geo === null ? null : PropertyFactory::geo($geo))
			->uri('URL', $url)
			->classification($classification)
			->integer('PRIORITY', $priority, 0, 9)
			->integer('SEQUENCE', $sequence, 0, PHP_INT_MAX)
			->add(PropertyFactory::texts('CATEGORIES', $categories))
			->text('COLOR', $color)
			->add($organizer === null ? null : PropertyFactory::calAddress('ORGANIZER', $organizer));
		foreach ($attendees as $attendee) {
			$this->add(PropertyFactory::calAddress('ATTENDEE', $attendee));
		}
		foreach ($images as $image) {
			$this->add(PropertyFactory::image($image));
		}
		foreach ($conferences as $conference) {
			$this->add(PropertyFactory::conference($conference));
		}
		foreach ($links as $link) {
			$this->add(PropertyFactory::link($link));
		}
		foreach ($relatedTo as $relation) {
			$this->add(PropertyFactory::relation($relation));
		}
		return $this;
	}

	/**
	 * VALARM components; a relative trigger needs the start (or the end) of the item (RFC 5545, section 3.6.6).
	 *
	 * @param iterable<Alarm|Component> $alarms
	 */
	public function alarms(iterable $alarms, bool $hasStart, bool $hasEnd, string $endName): self {
		foreach ($alarms as $alarm) {
			$component = $alarm instanceof Alarm ? $alarm->component : $alarm;
			if ($component->name !== 'VALARM') {
				throw new InvalidArgumentException("Alarms must be VALARM components, $component->name given.");
			}
			$trigger = $component->property('TRIGGER');
			if ($trigger !== null && strtoupper($trigger->parameter('VALUE') ?? 'DURATION') === 'DURATION') {
				$related = strtoupper($trigger->parameter('RELATED') ?? 'START');
				if ($related === 'END' && !$hasEnd) {
					throw new InvalidArgumentException("An alarm related to the end requires $endName, or a start and DURATION.");
				}
				if ($related !== 'END' && !$hasStart) {
					throw new InvalidArgumentException('An alarm related to the start requires a start.');
				}
			}
			$this->components[] = $component;
		}
		return $this;
	}

	/**
	 * VLOCATION components (RFC 9073).
	 *
	 * @param iterable<Location|Component> $locations
	 */
	public function locations(iterable $locations): self {
		foreach ($locations as $location) {
			$component = $location instanceof Location ? $location->component : $location;
			if ($component->name !== 'VLOCATION') {
				throw new InvalidArgumentException("Locations must be VLOCATION components, $component->name given.");
			}
			$this->components[] = $component;
		}
		return $this;
	}

	/**
	 * The component with the properties of the "properties:" argument; a property that may occur
	 * only once cannot repeat one set by an argument.
	 *
	 * @param PropertyList $properties
	 */
	public function build(array $properties = []): Component {
		$single = Validator::SINGLE[$this->name] ?? [];
		foreach (PropertyFactory::properties($properties) as $property) {
			if (in_array($property->name, $single, true) && array_filter($this->properties, static fn(Property $other): bool => $other->name === $property->name) !== []) {
				throw new InvalidArgumentException("$property->name of $this->name is already set, it must not occur more than once.");
			}
			$this->properties[] = $property;
		}
		return new Component($this->name, $this->properties, $this->components);
	}

	/**
	 * UNTIL has the value type of DTSTART: a date for a date, UTC for zoned and UTC times,
	 * a local time for a floating time (RFC 5545, section 3.3.10).
	 */
	private static function checkUntil(Rule $rule, DateTimeValue $start): void {
		if ($rule->count !== null && $rule->until !== null) {
			throw new InvalidArgumentException('COUNT and UNTIL cannot be used together.');
		}
		if ($rule->until === null) {
			return;
		}
		$kind = is_string($rule->until) ? (strlen($rule->until) === 8 ? 'a DATE' : 'a floating time') : 'a UTC time';
		$expected = match (true) {
			$start->isDate() => 'a DATE',
			$start->isFloating() => 'a floating time',
			default => 'a UTC time',
		};
		if ($kind !== $expected) {
			throw new InvalidArgumentException("UNTIL must be $expected like the start, $kind given.");
		}
	}

	/**
	 * Order of two values: instants for UTC and zoned times, wall-clock times otherwise.
	 */
	private static function compare(DateTimeValue $a, DateTimeValue $b): int {
		if (($a->isUtc() || $a->isZoned()) && ($b->isUtc() || $b->isZoned())) {
			return $a->toDateTime()->getTimestamp() <=> $b->toDateTime()->getTimestamp();
		}
		return $a->wallClockAsUtc() <=> $b->wallClockAsUtc();
	}
}
