<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use om\ICal\Timezone\TimezoneResolver;
use om\ICal\Timezone\VTimezoneBuilder;
use om\ICal\Value\Image;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Text;
use om\ICal\Value\ValueParser;
use om\RRule\RecurrenceLimits;
use RuntimeException;

/**
 * VCALENDAR (RFC 5545, section 3.4) with typed access to its components.
 *
 * Components with the same UID form a series: events(), todos() and journals() return the
 * recurring (or single) items, their overrides (RECURRENCE-ID) are available through
 * Item::overrides() and are applied by the occurrence methods.
 *
 * @phpstan-import-type PropertyList from PropertyFactory
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
	 * A new calendar (VERSION 2.0 and PRODID) with the given properties and components.
	 *
	 *     Calendar::create('-//example//team//EN', name: 'Team', events: [Event::new(summary: 'Standup', start: ...)])
	 *
	 * A VTIMEZONE is added for every IANA TZID used by the components (see VTimezoneBuilder::forComponents())
	 * unless $timezones is false or $components already define it. Components are written in the order:
	 * VTIMEZONE, events, tasks, journal entries, other components.
	 *
	 * @param ?string $name NAME (RFC 7986), also written as X-WR-CALNAME for older programs
	 * @param ?string $description DESCRIPTION (RFC 7986), also written as X-WR-CALDESC
	 * @param ?string $color COLOR (RFC 7986), a CSS3 color name
	 * @param ?string $method METHOD (RFC 5546), e.g. PUBLISH or REQUEST
	 * @param iterable<Event|Component> $events VEVENT components, see Event::new()
	 * @param iterable<Todo|Component> $todos VTODO components, see Todo::new()
	 * @param iterable<Journal|Component> $journals VJOURNAL components, see Journal::new()
	 * @param iterable<Item|Component> $components other components, e.g. VTIMEZONE or VFREEBUSY
	 * @param PropertyList $properties other properties, see Event::new()
	 * @param bool $timezones add a VTIMEZONE for every TZID used
	 * @throws InvalidArgumentException for an invalid value or a component of another type
	 */
	public static function create(
		string $productId = '-//om//icalparser//EN',
		?string $name = null,
		?string $description = null,
		?string $color = null,
		?string $method = null,
		iterable $events = [],
		iterable $todos = [],
		iterable $journals = [],
		iterable $components = [],
		array $properties = [],
		bool $timezones = true,
	): self {
		if ($method !== null && !preg_match('/^[A-Za-z0-9-]+$/D', $method)) {
			throw new InvalidArgumentException("Invalid METHOD value: $method");
		}
		$calendar = (new ComponentBuilder('VCALENDAR'))
			->add(Property::create('VERSION', '2.0'))
			->text('PRODID', $productId)
			->add($method === null ? null : Property::create('METHOD', strtoupper($method)))
			->text('NAME', $name)
			->text('X-WR-CALNAME', $name)
			->text('DESCRIPTION', $description)
			->text('X-WR-CALDESC', $description)
			->text('COLOR', $color)
			->build($properties);

		$items = [...self::components($events, 'VEVENT'), ...self::components($todos, 'VTODO'), ...self::components($journals, 'VJOURNAL')];
		$defined = $others = [];
		foreach (self::components($components, null) as $component) {
			$component->name === 'VTIMEZONE' ? $defined[] = $component : $others[] = $component;
		}
		$generated = $timezones ? VTimezoneBuilder::forComponents([...$items, ...$others], array_map(
			static fn(Component $definition): string => Text::unescape($definition->property('TZID')->value ?? ''),
			$defined,
		)) : [];
		return new self($calendar->withComponents([...$defined, ...$generated, ...$items, ...$others]));
	}

	/**
	 * Write the calendar to a file.
	 *
	 * @throws RuntimeException when the file cannot be written
	 */
	public function writeFile(string $file): void {
		if (@file_put_contents($file, $this->serialize(), LOCK_EX) === false) {
			throw new RuntimeException("Unable to write the file $file.");
		}
	}

	public function values(): ValueParser {
		return $this->values;
	}

	public function timezoneResolver(): ?TimezoneResolver {
		return $this->timezoneResolver;
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

	/**
	 * COLOR (RFC 7986): a CSS3 color name.
	 */
	public function color(): ?string {
		return $this->text('COLOR');
	}

	/**
	 * IMAGE properties (RFC 7986); images with invalid binary data are skipped.
	 *
	 * @return list<Image>
	 */
	public function images(): array {
		return array_values(array_filter(array_map($this->values->image(...), $this->component->properties('IMAGE'))));
	}

	/**
	 * SOURCE (RFC 7986): the URI the calendar data can be refreshed from.
	 */
	public function source(): ?string {
		$property = $this->property('SOURCE');
		return $property === null ? null : $this->values->uri($property);
	}

	/**
	 * REFRESH-INTERVAL (RFC 7986): the suggested minimum polling interval.
	 */
	public function refreshInterval(): ?DateInterval {
		$property = $this->property('REFRESH-INTERVAL');
		return $property === null ? null : $this->values->duration($property);
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
	 * @return list<TimezoneDefinition>
	 */
	public function timezones(): array {
		return array_map(fn(Component $component): TimezoneDefinition => new TimezoneDefinition($component, $this), $this->component->components('VTIMEZONE'));
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
		// sort by the instant, dates and floating times in the floating timezone (or the one of $from)
		$timezone = $this->floatingTimezone() ?? $from->getTimezone();
		$keys = array_map(static fn(Occurrence $occurrence): int => $occurrence->start->toDateTime($timezone, $timezone)->getTimestamp(), $occurrences);
		array_multisort($keys, SORT_NUMERIC, array_keys($occurrences), $occurrences);
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

	/**
	 * @param iterable<Item|Component> $components
	 * @return list<Component>
	 */
	private static function components(iterable $components, ?string $name): array {
		$result = [];
		foreach ($components as $component) {
			$component = $component instanceof Item ? $component->component : $component;
			if ($name !== null && $component->name !== $name) {
				throw new InvalidArgumentException("Expected a $name component, $component->name given.");
			}
			if ($component->name === 'VCALENDAR') {
				throw new InvalidArgumentException('A VCALENDAR cannot be a component of a calendar.');
			}
			$result[] = $component;
		}
		return $result;
	}

	private function text(string $name): ?string {
		$property = $this->property($name);
		return $property === null ? null : $this->values->text($property);
	}
}
