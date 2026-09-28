<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use om\ICal\Value\CalAddress;
use om\ICal\Value\Classification;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Image;
use om\ICal\Value\Link;
use om\ICal\Value\Period;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Relation;
use om\ICal\Value\Status;
use om\RRule\Rule;

/**
 * VJOURNAL (RFC 5545, section 3.6.3). A journal entry has no duration.
 *
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class Journal extends Item {
	/**
	 * A new journal entry; the arguments are those of Event::new() without the end, the location
	 * and the alarms (a journal entry has none).
	 *
	 * @param ?string $uid UID, a random UUID when null
	 * @param DateTimeInterface|DateTimeValue|string|null $stamp DTSTAMP, now when null; written in UTC
	 * @param DateTimeInterface|DateTimeValue|string|null $start DTSTART: a DateTimeInterface (TZID of its IANA timezone or UTC), a DateTimeValue (also a DATE or a floating time) or an iCalendar string
	 * @param Status|string|null $status DRAFT, FINAL or CANCELLED
	 * @param Classification|string|null $classification
	 * @param iterable<string> $categories
	 * @param CalAddress|string|null $organizer
	 * @param iterable<CalAddress|string> $attendees
	 * @param Rule|string|null $rrule
	 * @param iterable<DateTimeInterface|DateTimeValue|Period|string> $rdates
	 * @param iterable<DateTimeInterface|DateTimeValue|string> $exdates
	 * @param DateTimeInterface|DateTimeValue|string|null $recurrenceId
	 * @param iterable<Image|string> $images
	 * @param iterable<Link|string> $links
	 * @param iterable<Relation|string> $relatedTo
	 * @param PropertyList $properties
	 * @throws InvalidArgumentException for an invalid value or combination of arguments
	 */
	public static function new(
		?string $uid = null,
		DateTimeInterface|DateTimeValue|string|null $stamp = null,
		DateTimeInterface|DateTimeValue|string|null $start = null,
		?string $summary = null,
		?string $description = null,
		?string $url = null,
		Status|string|null $status = null,
		Classification|string|null $classification = null,
		?int $sequence = null,
		iterable $categories = [],
		CalAddress|string|null $organizer = null,
		iterable $attendees = [],
		Rule|string|null $rrule = null,
		iterable $rdates = [],
		iterable $exdates = [],
		DateTimeInterface|DateTimeValue|string|null $recurrenceId = null,
		?string $color = null,
		iterable $images = [],
		iterable $links = [],
		iterable $relatedTo = [],
		array $properties = [],
	): self {
		$builder = ComponentBuilder::item('VJOURNAL', $uid, $stamp);
		$startValue = $builder->start($start);
		$builder->recurrence($startValue, $rrule, $rdates, $exdates, $recurrenceId)
			->descriptive(summary: $summary, description: $description, url: $url, classification: $classification, sequence: $sequence, categories: $categories, organizer: $organizer, attendees: $attendees, color: $color, images: $images, links: $links, relatedTo: $relatedTo)
			->status($status);
		return new self($builder->build($properties), new Calendar());
	}

	public function duration(): DateInterval {
		return new DateInterval('PT0S');
	}
}
