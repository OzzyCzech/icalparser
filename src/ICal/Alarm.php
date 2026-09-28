<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;

/**
 * VALARM (RFC 5545, section 3.6.6, with UID and ACKNOWLEDGED of RFC 9074).
 */
final class Alarm {
	/**
	 * @internal use Item::alarms()
	 */
	public function __construct(
		public readonly Component $component,
		private readonly Calendar $calendar,
	) {
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
}
