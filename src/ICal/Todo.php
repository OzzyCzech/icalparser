<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use om\ICal\Value\CalAddress;
use om\ICal\Value\Classification;
use om\ICal\Value\Conference;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Image;
use om\ICal\Value\Link;
use om\ICal\Value\Period;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Relation;
use om\ICal\Value\Status;
use om\RRule\Rule;

/**
 * VTODO (RFC 5545, section 3.6.2).
 *
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class Todo extends Item {
	/**
	 * A new task; the arguments are those of Event::new() with DUE instead of DTEND.
	 *
	 * @param ?string $uid UID, a random UUID when null
	 * @param DateTimeInterface|DateTimeValue|string|null $stamp DTSTAMP, now when null; written in UTC
	 * @param DateTimeInterface|DateTimeValue|string|null $start DTSTART: a DateTimeInterface (TZID of its IANA timezone or UTC), a DateTimeValue (also a DATE or a floating time) or an iCalendar string
	 * @param DateTimeInterface|DateTimeValue|string|null $due DUE, not earlier than the start and of the same value type
	 * @param DateInterval|string|null $duration DURATION instead of DUE, requires a start
	 * @param DateTimeInterface|DateTimeValue|string|null $completed COMPLETED, written in UTC
	 * @param ?int $percentComplete PERCENT-COMPLETE, 0 to 100
	 * @param Status|string|null $status NEEDS-ACTION, COMPLETED, IN-PROCESS or CANCELLED
	 * @param Classification|string|null $classification
	 * @param iterable<string> $categories
	 * @param CalAddress|string|null $organizer
	 * @param iterable<CalAddress|string> $attendees
	 * @param Rule|string|null $rrule
	 * @param iterable<DateTimeInterface|DateTimeValue|Period|string> $rdates
	 * @param iterable<DateTimeInterface|DateTimeValue|string> $exdates
	 * @param DateTimeInterface|DateTimeValue|string|null $recurrenceId
	 * @param array{float|int, float|int}|null $geo
	 * @param iterable<Image|string> $images
	 * @param iterable<Conference|string> $conferences
	 * @param iterable<Link|string> $links
	 * @param iterable<Relation|string> $relatedTo
	 * @param iterable<Alarm|Component> $alarms
	 * @param iterable<Location|Component> $locations
	 * @param PropertyList $properties
	 * @throws InvalidArgumentException for an invalid value or combination of arguments
	 */
	public static function new(
		?string $uid = null,
		DateTimeInterface|DateTimeValue|string|null $stamp = null,
		DateTimeInterface|DateTimeValue|string|null $start = null,
		DateTimeInterface|DateTimeValue|string|null $due = null,
		DateInterval|string|null $duration = null,
		DateTimeInterface|DateTimeValue|string|null $completed = null,
		?int $percentComplete = null,
		?string $summary = null,
		?string $description = null,
		?string $location = null,
		?string $url = null,
		Status|string|null $status = null,
		Classification|string|null $classification = null,
		?int $priority = null,
		?int $sequence = null,
		iterable $categories = [],
		CalAddress|string|null $organizer = null,
		iterable $attendees = [],
		Rule|string|null $rrule = null,
		iterable $rdates = [],
		iterable $exdates = [],
		DateTimeInterface|DateTimeValue|string|null $recurrenceId = null,
		?array $geo = null,
		?string $color = null,
		iterable $images = [],
		iterable $conferences = [],
		iterable $links = [],
		iterable $relatedTo = [],
		iterable $alarms = [],
		iterable $locations = [],
		array $properties = [],
	): self {
		$builder = ComponentBuilder::item('VTODO', $uid, $stamp);
		$startValue = $builder->start($start);
		$dueValue = $builder->end('DUE', $due, $duration, $startValue);
		$builder->recurrence($startValue, $rrule, $rdates, $exdates, $recurrenceId)
			->add($completed === null ? null : PropertyFactory::dateTime('COMPLETED', $completed))
			->integer('PERCENT-COMPLETE', $percentComplete, 0, 100)
			->descriptive($summary, $description, $location, $url, $classification, $priority, $sequence, $categories, $organizer, $attendees, $geo, $color, $images, $conferences, $links, $relatedTo)
			->status($status)
			->alarms($alarms, $startValue !== null, $dueValue !== null, 'DUE')
			->locations($locations);
		return new self($builder->build($properties), new Calendar());
	}

	/**
	 * DUE, or DTSTART + DURATION.
	 */
	public function due(): ?DateTimeValue {
		return $this->date('DUE') ?? ($this->has('DURATION') ? parent::end() : null);
	}

	public function end(): ?DateTimeValue {
		return $this->due() ?? $this->start();
	}

	public function duration(): DateInterval {
		$start = $this->start();
		$due = $this->date('DUE');
		if ($start !== null && $due !== null) {
			return self::between($start, $due);
		}
		$duration = $this->property('DURATION');
		return ($duration === null ? null : $this->calendar->values()->duration($duration)) ?? new DateInterval('PT0S');
	}

	public function completed(): ?DateTimeValue {
		return $this->date('COMPLETED');
	}

	public function percentComplete(): ?int {
		return $this->integer('PERCENT-COMPLETE');
	}

	public function isCompleted(): bool {
		return $this->status() === 'COMPLETED' || $this->has('COMPLETED');
	}
}
