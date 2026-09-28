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
use om\ICal\Value\Transparency;
use om\RRule\Rule;

/**
 * VEVENT (RFC 5545, section 3.6.1).
 *
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class Event extends Item {
	/**
	 * A new event; all arguments are optional, other properties are given by $properties.
	 *
	 *     Event::new(summary: 'Standup', start: new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('Europe/Prague')), duration: 'PT15M')
	 *
	 * @param ?string $uid UID, a random UUID when null
	 * @param DateTimeInterface|DateTimeValue|string|null $stamp DTSTAMP, now when null; written in UTC
	 * @param DateTimeInterface|DateTimeValue|string|null $start DTSTART: a DateTimeInterface (TZID of its IANA timezone or UTC), a DateTimeValue (also a DATE or a floating time) or an iCalendar string
	 * @param DateTimeInterface|DateTimeValue|string|null $end DTEND, not earlier than the start and of the same value type
	 * @param DateInterval|string|null $duration DURATION instead of the end
	 * @param ?string $url URI
	 * @param Status|string|null $status TENTATIVE, CONFIRMED or CANCELLED
	 * @param Transparency|string|null $transparency OPAQUE (busy) or TRANSPARENT (free)
	 * @param Classification|string|null $classification PUBLIC, PRIVATE, CONFIDENTIAL
	 * @param ?int $priority 0 (undefined) to 9 (lowest), 1 is the highest
	 * @param iterable<string> $categories CATEGORIES, written as one property
	 * @param CalAddress|string|null $organizer a CalAddress or a URI (an e-mail address becomes "mailto:")
	 * @param iterable<CalAddress|string> $attendees
	 * @param Rule|string|null $rrule RRULE, validated; UNTIL has the value type of the start (UTC for zoned times)
	 * @param iterable<DateTimeInterface|DateTimeValue|Period|string> $rdates RDATE, of the value type of the start
	 * @param iterable<DateTimeInterface|DateTimeValue|string> $exdates EXDATE, of the value type of the start
	 * @param DateTimeInterface|DateTimeValue|string|null $recurrenceId RECURRENCE-ID of an override
	 * @param array{float|int, float|int}|null $geo latitude and longitude
	 * @param ?string $color COLOR (RFC 7986), a CSS3 color name
	 * @param iterable<Image|string> $images IMAGE (RFC 7986), URIs or Image objects
	 * @param iterable<Conference|string> $conferences CONFERENCE (RFC 7986), URIs or Conference objects
	 * @param iterable<Link|string> $links LINK (RFC 9253), URIs or Link objects
	 * @param iterable<Relation|string> $relatedTo RELATED-TO, UIDs or Relation objects
	 * @param iterable<Alarm|Component> $alarms VALARM components, see Alarm::display()
	 * @param iterable<Location|Component> $locations VLOCATION components (RFC 9073), see Location::new()
	 * @param PropertyList $properties other properties: Property objects, or name => value where a string is the raw
	 *        (escaped) value and other PHP values are converted, see the documentation
	 * @throws InvalidArgumentException for an invalid value or combination of arguments
	 */
	public static function new(
		?string $uid = null,
		DateTimeInterface|DateTimeValue|string|null $stamp = null,
		DateTimeInterface|DateTimeValue|string|null $start = null,
		DateTimeInterface|DateTimeValue|string|null $end = null,
		DateInterval|string|null $duration = null,
		?string $summary = null,
		?string $description = null,
		?string $location = null,
		?string $url = null,
		Status|string|null $status = null,
		Transparency|string|null $transparency = null,
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
		$builder = ComponentBuilder::item('VEVENT', $uid, $stamp);
		$startValue = $builder->start($start);
		$endValue = $builder->end('DTEND', $end, $duration, $startValue);
		$builder->recurrence($startValue, $rrule, $rdates, $exdates, $recurrenceId)
			->descriptive($summary, $description, $location, $url, $classification, $priority, $sequence, $categories, $organizer, $attendees, $geo, $color, $images, $conferences, $links, $relatedTo)
			->status($status)
			->transparency($transparency)
			->alarms($alarms, $startValue !== null, $endValue !== null, 'DTEND')
			->locations($locations);
		return new self($builder->build($properties), new Calendar());
	}

	/**
	 * DTEND, DTSTART + DURATION, or one day after an all-day DTSTART (RFC 5545, section 3.6.1).
	 */
	public function end(): ?DateTimeValue {
		return $this->date('DTEND') ?? parent::end();
	}

	/**
	 * From DTEND or DURATION; one day for all-day events, zero otherwise.
	 */
	public function duration(): DateInterval {
		return $this->remember('duration', $this->calculateDuration(...));
	}

	private function calculateDuration(): DateInterval {
		$start = $this->start();
		$end = $this->date('DTEND');
		if ($start !== null && $end !== null) {
			return self::between($start, $end);
		}
		$duration = $this->property('DURATION');
		if ($duration !== null && ($interval = $this->calendar->values()->duration($duration)) !== null) {
			return $interval;
		}
		return new DateInterval($start?->isDate() ? 'P1D' : 'PT0S');
	}

	/**
	 * TRANSP: OPAQUE (busy, default) or TRANSPARENT (free).
	 */
	public function transparency(): string {
		return strtoupper($this->text('TRANSP') ?? 'OPAQUE');
	}

	/**
	 * @return array{float, float}|null latitude and longitude
	 */
	public function geo(): ?array {
		$property = $this->property('GEO');
		return $property === null ? null : $this->calendar->values()->geo($property);
	}
}
