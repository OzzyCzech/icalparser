<?php
declare(strict_types=1);

namespace om\ICal;

use DateTimeInterface;
use DateTimeZone;
use om\ICal\Timezone\TimezoneResolver;
use om\ICal\Value\ValueParser;
use om\RRule\RecurrenceLimits;

/**
 * VCALENDAR (RFC 5545, section 3.4) with typed access to its components.
 *
 * Components with the same UID form a series: events(), todos() and journals() return the
 * recurring (or single) items, their overrides (RECURRENCE-ID) are available through
 * Item::overrides() and are applied by the occurrence methods.
 */
final class Calendar {
	private const array ITEMS = ['VEVENT' => Event::class, 'VTODO' => Todo::class, 'VJOURNAL' => Journal::class, 'VFREEBUSY' => FreeBusy::class];

	private readonly ValueParser $values;
	/** @var array<string, list<Item>> */
	private array $items = [];

	/**
	 * @param ?DateTimeZone $floatingTimezone timezone of dates and floating times; X-WR-TIMEZONE when null
	 */
	public function __construct(
		public readonly Component $component = new Component('VCALENDAR'),
		private readonly ?TimezoneResolver $timezoneResolver = null,
		private readonly ?DateTimeZone $floatingTimezone = null,
		private readonly RecurrenceLimits $recurrenceLimits = new RecurrenceLimits(),
		private readonly bool $strict = false,
	) {
		$this->values = new ValueParser($component, $timezoneResolver, $strict);
	}

	/**
	 * A new, empty calendar.
	 */
	public static function create(string $productId = '-//om//icalparser//EN'): self {
		return new self(new Component('VCALENDAR', [Property::create('VERSION', '2.0'), Property::create('PRODID', $productId)]));
	}

	public function values(): ValueParser {
		return $this->values;
	}

	public function recurrenceLimits(): RecurrenceLimits {
		return $this->recurrenceLimits;
	}

	public function property(string $name): ?Property {
		return $this->component->property($name);
	}

	public function value(string $name): mixed {
		$property = $this->property($name);
		return $property === null ? null : $this->values->value($property);
	}

	/**
	 * NAME (RFC 7986) or X-WR-CALNAME.
	 */
	public function name(): ?string {
		return $this->text('NAME') ?? $this->text('X-WR-CALNAME');
	}

	/**
	 * DESCRIPTION (RFC 7986) or X-WR-CALDESC.
	 */
	public function description(): ?string {
		return $this->text('DESCRIPTION') ?? $this->text('X-WR-CALDESC');
	}

	public function productId(): ?string {
		return $this->text('PRODID');
	}

	public function version(): ?string {
		return $this->text('VERSION');
	}

	/** METHOD (RFC 5546), e.g. PUBLISH or REQUEST. */
	public function method(): ?string {
		$method = $this->text('METHOD');
		return $method === null ? null : strtoupper($method);
	}

	/**
	 * Timezone declared by X-WR-TIMEZONE.
	 */
	public function timezone(): ?DateTimeZone {
		$tzid = $this->text('X-WR-TIMEZONE');
		return $tzid === null ? null : $this->values->timezone($tzid)?->timezone;
	}

	/**
	 * Timezone used for dates and floating times: the configured one or X-WR-TIMEZONE.
	 */
	public function floatingTimezone(): ?DateTimeZone {
		return $this->floatingTimezone ?? $this->timezone();
	}

	/**
	 * @return list<Event>
	 */
	public function events(): array {
		return array_values(array_filter($this->items('VEVENT'), static fn(Item $item): bool => $item instanceof Event));
	}

	/**
	 * @return list<Todo>
	 */
	public function todos(): array {
		return array_values(array_filter($this->items('VTODO'), static fn(Item $item): bool => $item instanceof Todo));
	}

	/**
	 * @return list<Journal>
	 */
	public function journals(): array {
		return array_values(array_filter($this->items('VJOURNAL'), static fn(Item $item): bool => $item instanceof Journal));
	}

	/**
	 * @return list<FreeBusy>
	 */
	public function freeBusy(): array {
		return array_values(array_filter($this->items('VFREEBUSY'), static fn(Item $item): bool => $item instanceof FreeBusy));
	}

	/**
	 * @return list<TimeZone>
	 */
	public function timezones(): array {
		return array_map(fn(Component $component): TimeZone => new TimeZone($component, $this), $this->component->components('VTIMEZONE'));
	}

	/**
	 * Occurrences of all events overlapping [$from, $to), sorted by start.
	 *
	 * @return list<Occurrence>
	 */
	public function occurrencesBetween(DateTimeInterface $from, DateTimeInterface $to, bool $includeCancelled = false): array {
		$occurrences = [];
		foreach ($this->events() as $event) {
			foreach ($event->occurrencesBetween($from, $to, $includeCancelled) as $occurrence) {
				$occurrences[] = $occurrence;
			}
		}
		$timezone = $this->floatingTimezone() ?? $from->getTimezone();
		usort($occurrences, static fn(Occurrence $a, Occurrence $b): int => $a->startTime($timezone) <=> $b->startTime($timezone));
		return $occurrences;
	}

	/**
	 * A copy with another component (event, task, VTIMEZONE, ...).
	 */
	public function withComponent(Component|Item $component): self {
		$component = $component instanceof Item ? $component->component : $component;
		return new self($this->component->withComponent($component), $this->timezoneResolver, $this->floatingTimezone, $this->recurrenceLimits, $this->strict);
	}

	/**
	 * Serialize to iCalendar data, see Serializer.
	 */
	public function serialize(): string {
		return Serializer::serialize($this->component);
	}

	/**
	 * Typed item of a component (without overrides), null for other components.
	 *
	 * @internal
	 */
	public function itemOf(Component $component): ?Item {
		$class = self::ITEMS[$component->name] ?? null;
		return $class === null ? null : new $class($component, $this);
	}

	/**
	 * Items of one component type grouped into series by UID.
	 *
	 * @return list<Item>
	 */
	private function items(string $name): array {
		if (isset($this->items[$name])) {
			return $this->items[$name];
		}
		$class = self::ITEMS[$name];
		$masters = $overrides = $result = [];
		foreach ($this->component->components($name) as $component) {
			$uid = $component->property('UID')?->value;
			if ($uid !== null && $component->has('RECURRENCE-ID')) {
				$overrides[$uid][] = $component;
			} elseif ($uid !== null && !isset($masters[$uid])) {
				$masters[$uid] = count($result);
				$result[] = $component;
			} else {
				$result[] = $component;
			}
		}

		$items = [];
		foreach ($result as $component) {
			$uid = $component->property('UID')?->value;
			$ownOverrides = $uid !== null && ($masters[$uid] ?? null) === count($items) ? $overrides[$uid] ?? [] : [];
			unset($overrides[$uid ?? '']);
			$items[] = new $class($component, $this, array_map(fn(Component $override): Item => new $class($override, $this), $ownOverrides));
		}
		// overrides without a recurring item are single items
		foreach ($overrides as $orphans) {
			foreach ($orphans as $component) {
				$items[] = new $class($component, $this);
			}
		}
		return $this->items[$name] = $items;
	}

	private function text(string $name): ?string {
		$property = $this->property($name);
		return $property === null ? null : $this->values->text($property);
	}
}
