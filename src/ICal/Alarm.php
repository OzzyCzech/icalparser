<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use om\ICal\Value\PropertyFactory;

/**
 * VALARM (RFC 5545, section 3.6.6, with UID and ACKNOWLEDGED of RFC 9074).
 *
 * The trigger of the factories is a duration relative to the start of the event or task
 * (e.g. "-PT15M", or to its end with related: 'END'), or an absolute time written in UTC.
 * An alarm repeats $repeat more times after $duration; both are given or neither.
 *
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class Alarm {
	/**
	 * @internal use Item::alarms() or the factories
	 */
	public function __construct(
		public readonly Component $component,
		private readonly Calendar $calendar,
	) {
	}

	/**
	 * An alarm displaying the description.
	 *
	 * @param DateInterval|DateTimeInterface|DateTimeValue|string $trigger
	 * @param 'START'|'END'|'start'|'end' $related
	 * @param PropertyList $properties other properties, see Event::new()
	 * @throws InvalidArgumentException
	 */
	public static function display(
		string $description,
		DateInterval|DateTimeInterface|DateTimeValue|string $trigger,
		string $related = 'START',
		?int $repeat = null,
		DateInterval|string|null $duration = null,
		?string $uid = null,
		array $properties = [],
	): self {
		return self::build('DISPLAY', $trigger, $related, $repeat, $duration, $uid, $properties, static fn(ComponentBuilder $builder) => $builder->text('DESCRIPTION', $description));
	}

	/**
	 * An alarm playing a sound, the default one of the program when $sound (a URI) is null.
	 *
	 * @param DateInterval|DateTimeInterface|DateTimeValue|string $trigger
	 * @param 'START'|'END'|'start'|'end' $related
	 * @param PropertyList $properties
	 * @throws InvalidArgumentException
	 */
	public static function audio(
		DateInterval|DateTimeInterface|DateTimeValue|string $trigger,
		?string $sound = null,
		string $related = 'START',
		?int $repeat = null,
		DateInterval|string|null $duration = null,
		?string $uid = null,
		array $properties = [],
	): self {
		return self::build('AUDIO', $trigger, $related, $repeat, $duration, $uid, $properties, static fn(ComponentBuilder $builder) => $builder->uri('ATTACH', $sound));
	}

	/**
	 * An alarm sending an e-mail with the summary as the subject and the description as the body
	 * to at least one attendee.
	 *
	 * @param iterable<CalAddress|string> $attendees
	 * @param DateInterval|DateTimeInterface|DateTimeValue|string $trigger
	 * @param iterable<string> $attachments URIs
	 * @param 'START'|'END'|'start'|'end' $related
	 * @param PropertyList $properties
	 * @throws InvalidArgumentException
	 */
	public static function email(
		string $summary,
		string $description,
		iterable $attendees,
		DateInterval|DateTimeInterface|DateTimeValue|string $trigger,
		iterable $attachments = [],
		string $related = 'START',
		?int $repeat = null,
		DateInterval|string|null $duration = null,
		?string $uid = null,
		array $properties = [],
	): self {
		return self::build('EMAIL', $trigger, $related, $repeat, $duration, $uid, $properties, static function (ComponentBuilder $builder) use ($summary, $description, $attendees, $attachments): void {
			$builder->text('DESCRIPTION', $description)->text('SUMMARY', $summary);
			$count = 0;
			foreach ($attendees as $attendee) {
				$builder->add(PropertyFactory::calAddress('ATTENDEE', $attendee));
				$count++;
			}
			if ($count === 0) {
				throw new InvalidArgumentException('An EMAIL alarm requires at least one attendee.');
			}
			foreach ($attachments as $attachment) {
				$builder->uri('ATTACH', $attachment);
			}
		});
	}

	public function property(string $name): ?Property {
		return $this->component->property($name);
	}

	/**
	 * UID of the alarm (RFC 9074, section 4).
	 */
	public function uid(): ?string {
		$property = $this->property('UID');
		return $property === null ? null : $this->calendar->values()->text($property);
	}

	/**
	 * ACTION: AUDIO, DISPLAY or EMAIL.
	 */
	public function action(): ?string {
		$action = $this->property('ACTION')?->value;
		return $action === null ? null : strtoupper(trim($action));
	}

	/**
	 * TRIGGER: a duration relative to the start (or end, see related()), or an absolute time.
	 */
	public function trigger(): DateInterval|DateTimeValue|null {
		$property = $this->property('TRIGGER');
		if ($property === null) {
			return null;
		}
		$value = $this->calendar->values()->value($property);
		return $value instanceof DateInterval || $value instanceof DateTimeValue ? $value : null;
	}

	/**
	 * RELATED parameter of a relative trigger: START (default) or END.
	 */
	public function related(): string {
		return strtoupper($this->property('TRIGGER')?->parameter('RELATED') ?? 'START');
	}

	/**
	 * ACKNOWLEDGED (RFC 9074, section 6): when the alarm was last acknowledged (or sent), in UTC.
	 */
	public function acknowledged(): ?DateTimeValue {
		$property = $this->property('ACKNOWLEDGED');
		return $property === null ? null : $this->calendar->values()->dateTime($property);
	}

	/**
	 * When the alarm of the occurrence goes off.
	 */
	public function triggerTime(Occurrence $occurrence, ?DateTimeZone $timezone = null): ?DateTimeImmutable {
		$trigger = $this->trigger();
		if ($trigger instanceof DateTimeValue) {
			return $trigger->toDateTime($timezone, $this->calendar->floatingTimezone());
		}
		if ($trigger === null) {
			return null;
		}
		$base = $this->related() === 'END' ? $occurrence->endTime($timezone) : $occurrence->startTime($timezone);
		return $base->add($trigger);
	}

	public function description(): ?string {
		$property = $this->property('DESCRIPTION');
		return $property === null ? null : $this->calendar->values()->text($property);
	}

	public function summary(): ?string {
		$property = $this->property('SUMMARY');
		return $property === null ? null : $this->calendar->values()->text($property);
	}

	/** REPEAT: number of additional repetitions. */
	public function repeat(): int {
		$property = $this->property('REPEAT');
		return ($property === null ? null : $this->calendar->values()->integer($property)) ?? 0;
	}

	/** DURATION: delay between repetitions. */
	public function duration(): ?DateInterval {
		$property = $this->property('DURATION');
		return $property === null ? null : $this->calendar->values()->duration($property);
	}

	/**
	 * @return list<CalAddress>
	 */
	public function attendees(): array {
		return array_map(fn(Property $property): CalAddress => $this->calendar->values()->calAddress($property), $this->component->properties('ATTENDEE'));
	}

	/**
	 * @param PropertyList $properties
	 * @param callable(ComponentBuilder): mixed $content
	 */
	private static function build(
		string $action,
		DateInterval|DateTimeInterface|DateTimeValue|string $trigger,
		string $related,
		?int $repeat,
		DateInterval|string|null $duration,
		?string $uid,
		array $properties,
		callable $content,
	): self {
		$builder = (new ComponentBuilder('VALARM'))->text('UID', $uid === null ? null : ComponentBuilder::uid($uid));
		$builder->add(Property::create('ACTION', $action), self::triggerProperty($trigger, strtoupper($related)));
		$content($builder);
		if (($repeat === null) !== ($duration === null)) {
			throw new InvalidArgumentException('REPEAT and DURATION of an alarm must be given together.');
		}
		if ($repeat !== null && $duration !== null) {
			$builder->integer('REPEAT', $repeat, 1, PHP_INT_MAX);
			$delay = PropertyFactory::duration($duration);
			if (Duration::parse($delay)?->invert) {
				throw new InvalidArgumentException("DURATION between repetitions must not be negative, $delay given.");
			}
			$builder->add(Property::create('DURATION', $delay));
		}
		return new self($builder->build($properties), new Calendar());
	}

	private static function triggerProperty(DateInterval|DateTimeInterface|DateTimeValue|string $trigger, string $related): Property {
		if ($related !== 'START' && $related !== 'END') {
			throw new InvalidArgumentException("RELATED must be START or END, $related given.");
		}
		if ($trigger instanceof DateInterval || (is_string($trigger) && Duration::parse($trigger) !== null)) {
			return Property::create('TRIGGER', PropertyFactory::duration($trigger), $related === 'END' ? ['RELATED' => 'END'] : []);
		}
		if ($related === 'END') {
			throw new InvalidArgumentException('RELATED=END is allowed only for a relative trigger.');
		}
		if (is_string($trigger) && !preg_match('/^\d{8}T\d{6}Z$/Di', trim($trigger))) {
			throw new InvalidArgumentException("TRIGGER must be a duration or a UTC time, $trigger given.");
		}
		return Property::create('TRIGGER', (string) PropertyFactory::utcValue($trigger, 'TRIGGER'), ['VALUE' => 'DATE-TIME']);
	}
}
