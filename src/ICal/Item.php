<?php
declare(strict_types=1);

namespace om\ICal;

use DateInterval;
use DateTimeInterface;
use Generator;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;
use om\RRule\RecurrenceSet;
use om\RRule\Rule;

/**
 * Common part of events, tasks, journal entries and free/busy components.
 *
 * All properties stay available through property(); getters return typed values.
 * A recurring item knows its overrides (components with the same UID and a RECURRENCE-ID),
 * so occurrencesBetween() returns the complete series.
 */
abstract class Item {
	/** @var array<string, mixed> typed values already converted (the component is immutable) */
	private array $memo = [];
	/**
	 * @param list<Item> $overrides
	 * @internal use Calendar::events() and similar methods
	 */
	public function __construct(
		public readonly Component $component,
		protected readonly Calendar $calendar,
		private readonly array $overrides = [],
	) {
	}

	/**
	 * Duration of one occurrence.
	 */
	abstract public function duration(): DateInterval;

	/**
	 * End of the (first) occurrence.
	 */
	public function end(): ?DateTimeValue {
		return $this->start()?->add($this->duration());
	}

	public function property(string $name): ?Property {
		return $this->component->property($name);
	}

	/**
	 * @return list<Property>
	 */
	public function properties(string $name): array {
		return $this->component->properties($name);
	}

	/**
	 * Typed value of the first property with the name, see ValueParser::value().
	 */
	public function value(string $name): mixed {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->value($property);
	}

	public function calendar(): Calendar {
		return $this->calendar;
	}

	public function uid(): ?string {
		return $this->text('UID');
	}

	public function summary(): ?string {
		return $this->text('SUMMARY');
	}

	public function description(): ?string {
		return $this->text('DESCRIPTION');
	}

	public function location(): ?string {
		return $this->text('LOCATION');
	}

	/**
	 * STATUS in upper case, e.g. CONFIRMED, TENTATIVE, CANCELLED, NEEDS-ACTION, COMPLETED.
	 */
	public function status(): ?string {
		$status = $this->text('STATUS');
		return $status === null ? null : strtoupper($status);
	}

	public function isCancelled(): bool {
		return $this->remember('cancelled', fn() => $this->status() === 'CANCELLED');
	}

	/**
	 * CLASS: PUBLIC (default), PRIVATE or CONFIDENTIAL.
	 */
	public function classification(): string {
		return strtoupper($this->text('CLASS') ?? 'PUBLIC');
	}

	public function url(): ?string {
		return $this->property('URL')?->value;
	}

	public function sequence(): int {
		return $this->integer('SEQUENCE') ?? 0;
	}

	public function priority(): ?int {
		return $this->integer('PRIORITY');
	}

	/**
	 * @return list<string>
	 */
	public function categories(): array {
		$categories = [];
		foreach ($this->properties('CATEGORIES') as $property) {
			array_push($categories, ...$this->calendar->values()->texts($property));
		}
		return $categories;
	}

	public function created(): ?DateTimeValue {
		return $this->date('CREATED');
	}

	public function lastModified(): ?DateTimeValue {
		return $this->date('LAST-MODIFIED');
	}

	public function stamp(): ?DateTimeValue {
		return $this->date('DTSTAMP');
	}

	public function organizer(): ?CalAddress {
		$property = $this->property('ORGANIZER');
		return $property === null ? null : $this->calendar->values()->calAddress($property);
	}

	/**
	 * @return list<CalAddress>
	 */
	public function attendees(): array {
		return array_map(fn(Property $property): CalAddress => $this->calendar->values()->calAddress($property), $this->properties('ATTENDEE'));
	}

	public function start(): ?DateTimeValue {
		return $this->remember('start', fn() => $this->date('DTSTART'));
	}

	public function isAllDay(): bool {
		return $this->start()?->isDate() ?? false;
	}

	/**
	 * @return list<Alarm>
	 */
	public function alarms(): array {
		return array_map(fn(Component $component): Alarm => new Alarm($component, $this->calendar), $this->component->components('VALARM'));
	}

	public function recurrenceRule(): ?Rule {
		return $this->recurrenceRules()[0] ?? null;
	}

	/**
	 * All valid RRULE properties; several rules are combined (RFC 2445 allowed that).
	 *
	 * @return list<Rule>
	 */
	public function recurrenceRules(): array {
		$rules = [];
		foreach ($this->properties('RRULE') as $property) {
			if (($rule = $this->calendar->values()->recur($property)) !== null) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * RDATE values.
	 *
	 * @return list<DateTimeValue>
	 */
	public function recurrenceDates(): array {
		return $this->dates('RDATE');
	}

	/**
	 * EXDATE values.
	 *
	 * @return list<DateTimeValue>
	 */
	public function exceptionDates(): array {
		return $this->dates('EXDATE');
	}

	public function isRecurring(): bool {
		return $this->has('RRULE') || $this->has('RDATE');
	}

	public function recurrenceId(): ?DateTimeValue {
		return $this->remember('recurrenceId', fn() => $this->date('RECURRENCE-ID'));
	}

	/**
	 * True for a component modifying an instance of a recurring item (it has a RECURRENCE-ID).
	 */
	public function isOverride(): bool {
		return $this->has('RECURRENCE-ID');
	}

	/**
	 * RANGE=THISANDFUTURE: the override applies to its instance and all later ones.
	 */
	public function isThisAndFuture(): bool {
		return strtoupper($this->property('RECURRENCE-ID')?->parameter('RANGE') ?? '') === 'THISANDFUTURE';
	}

	/**
	 * Components modifying instances of this recurring item.
	 *
	 * @return list<Item>
	 */
	public function overrides(): array {
		return $this->overrides;
	}

	/**
	 * Occurrences overlapping [$from, $to), in chronological order.
	 *
	 * Dates and floating times are compared with the local time of $from and $to.
	 * Cancelled instances (STATUS:CANCELLED overrides) are skipped unless requested.
	 *
	 * @return Generator<int, Occurrence>
	 */
	public function occurrencesBetween(DateTimeInterface $from, DateTimeInterface $to, bool $includeCancelled = false): Generator {
		return $this->expand($from, $to, $includeCancelled, PHP_INT_MAX);
	}

	/**
	 * The first occurrences, optionally from the given moment.
	 *
	 * @return Generator<int, Occurrence>
	 */
	public function occurrences(int $limit = 1000, ?DateTimeInterface $from = null, bool $includeCancelled = false): Generator {
		return $this->expand($from, null, $includeCancelled, $limit);
	}

	/**
	 * @return Generator<int, Occurrence>
	 */
	private function expand(?DateTimeInterface $from, ?DateTimeInterface $to, bool $includeCancelled, int $limit): Generator {
		$start = $this->start();
		if ($start === null || $limit < 1) {
			return;
		}
		$space = new TimeSpace($start);
		$fromTs = $from === null ? null : $space->window($from);
		$toTs = $to === null ? null : $space->window($to);

		if (!$this->isRecurring()) {
			$occurrence = new Occurrence($start, $start->add($this->duration()), $this, $this);
			if (($includeCancelled || !$this->isCancelled()) && $space->overlaps($occurrence, $fromTs, $toTs)) {
				yield $occurrence;
			}
			return;
		}

		// overrides: single ones replace their instance, RANGE=THISANDFUTURE ones also change later instances
		$singles = $replaced = $replacedDays = $ranges = [];
		$earliest = 0; // the largest shift of a range to an earlier time, in seconds
		foreach ($this->overrides as $override) {
			$id = $override->recurrenceId();
			if ($id === null) {
				continue;
			}
			$overrideStart = $override->start();
			$durationOf = $overrideStart === null ? $this : $override; // without DTSTART the instance keeps its time and length
			if ($override->isThisAndFuture()) {
				$shift = $overrideStart === null ? 0 : $overrideStart->wallClockAsUtc()->getTimestamp() - $id->wallClockAsUtc()->getTimestamp();
				$ranges[$space->toBase($id)] = [$override, $shift, $durationOf];
				$earliest = max($earliest, -$shift);
				continue;
			}
			$id->isDate() && !$start->isDate() ? $replacedDays[$id->format('Ymd')] = true : $replaced[$space->toBase($id)] = true;
			$overrideStart ??= $space->fromBase($space->toBase($id));
			$occurrence = new Occurrence($overrideStart, $overrideStart->add($durationOf->duration()), $override, $this, $id);
			if (($includeCancelled || !$override->isCancelled()) && $space->overlaps($occurrence, $fromTs, $toTs)) {
				$singles[] = $occurrence;
			}
		}
		ksort($ranges);
		// an instance may move into the window from its end (DST changes add at most a few hours)
		$slack = $earliest === 0 ? 0 : $earliest + 7200;

		$exdays = $exdates = [];
		foreach ($this->exceptionDates() as $date) {
			if ($date->isDate() && !$start->isDate()) {
				$exdays[] = $date->format('Ymd');
			} else {
				$exdates[] = $space->toBase($date);
			}
		}
		$limits = $this->calendar->recurrenceLimits();
		$set = new RecurrenceSet(
			$space->start(),
			$this->recurrenceRules(),
			rdates: array_map($space->toBase(...), $this->recurrenceDates()),
			exdates: $exdates,
			exdays: $exdays,
			until: $toTs === null ? null : $toTs - 1 + $slack,
			maxIterations: $limits->maxIterations,
		);

		// occurrences are buffered while a later instance may still move before them
		$buffer = new OccurrenceBuffer($space, $singles);
		$count = 0;
		foreach ($set as $timestamp) {
			if ($toTs !== null && $timestamp >= $toTs + $slack) {
				break;
			}
			$id = $space->fromBase($timestamp);
			if (!isset($replaced[$timestamp]) && ($replacedDays === [] || !isset($replacedDays[$id->format('Ymd')]))) {
				[$item, $occurrenceStart, $durationOf] = [$this, $id, $this];
				foreach ($ranges as $rangeId => [$range, $shift, $rangeDuration]) {
					if ($rangeId > $timestamp) {
						break;
					}
					[$item, $occurrenceStart, $durationOf] = [$range, $space->shift($id, $shift), $rangeDuration];
				}
				$occurrence = new Occurrence($occurrenceStart, $occurrenceStart->add($durationOf->duration()), $item, $this, $id);
				if (($includeCancelled || !$item->isCancelled()) && $space->overlaps($occurrence, $fromTs, $toTs)) {
					$buffer->add($occurrence);
				}
			}
			foreach ($buffer->ready($timestamp - $slack) as $occurrence) {
				yield $occurrence;
				if (++$count >= $limit) {
					return;
				}
				if ($count > $limits->maxInstances) {
					throw ResourceLimitException::create('recurrence.limit', "Recurrence occurrence limit of {$limits->maxInstances} exceeded.");
				}
			}
		}
		foreach ($buffer->ready(PHP_INT_MAX) as $occurrence) {
			yield $occurrence;
			if (++$count >= $limit) {
				return;
			}
			if ($count > $limits->maxInstances) {
				throw ResourceLimitException::create('recurrence.limit', "Recurrence occurrence limit of {$limits->maxInstances} exceeded.");
			}
		}
	}

	/**
	 * @template T
	 * @param callable(): T $calculate
	 * @return T
	 */
	protected function remember(string $key, callable $calculate): mixed {
		if (!array_key_exists($key, $this->memo)) {
			$this->memo[$key] = $calculate();
		}
		return $this->memo[$key];
	}

	protected function has(string $name): bool {
		return $this->component->has($name);
	}

	protected function text(string $name): ?string {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->text($property);
	}

	protected function integer(string $name): ?int {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->integer($property);
	}

	protected function date(string $name): ?DateTimeValue {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->dateTime($property);
	}

	/**
	 * @return list<DateTimeValue>
	 */
	protected function dates(string $name): array {
		$dates = [];
		foreach ($this->properties($name) as $property) {
			array_push($dates, ...$this->calendar->values()->dateTimes($property));
		}
		return $dates;
	}

	/**
	 * Duration from DTSTART to DTEND (or DUE): the exact elapsed time for UTC and zoned values,
	 * the wall-clock difference for dates and floating times (RFC 5545, section 3.8.5.3).
	 */
	protected static function between(DateTimeValue $start, DateTimeValue $end): DateInterval {
		if ($start->isDate() || $start->isFloating()) {
			return $start->wallClockAsUtc()->diff($end->wallClockAsUtc());
		}
		$seconds = $end->toDateTime(null, $start->timezone())->getTimestamp() - $start->toDateTime()->getTimestamp();
		// hours, minutes and seconds are elapsed time, days would keep the local time instead
		$absolute = abs($seconds);
		$interval = new DateInterval(sprintf('PT%dH%dM%dS', intdiv($absolute, 3600), intdiv($absolute % 3600, 60), $absolute % 60));
		$interval->invert = $seconds < 0 ? 1 : 0;
		return $interval;
	}
}
