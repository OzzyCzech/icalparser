<?php
declare(strict_types=1);

namespace om;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use om\RRule\Expander;
use om\RRule\Frequency;
use om\RRule\Rule;
use RuntimeException;

/**
 * Timestamp based access to a recurrence set (RRULE plus RDATE, minus EXDATE).
 *
 * Wall-clock calculations use the process default timezone, as in previous versions.
 * The recurrence engine itself lives in {@see Expander}.
 *
 * Originally based on https://github.com/coopTilleuls/intouch-iCalendar.git (Freq.php)
 * by Morten Fangel (C) 2008 and Michael Kahn (C) 2013, CC-BY-SA-DK.
 */
class Freq {
	/** @deprecated has no effect */
	public static bool $debug = false;

	protected Rule $rule;
	protected int $start;
	protected string $freq;

	/** @var array<int, true> EXDATE timestamps */
	protected array $excluded;
	/** @var list<int> RDATE timestamps, sorted */
	protected array $added;

	/** @var list<int>|null null means not calculated; [] is a valid result */
	protected ?array $cache = null;

	/**
	 * @param array<string, mixed>|string $rule RRULE parts or an RRULE value like "FREQ=DAILY;COUNT=3"
	 * @param int $start Unix timestamp of DTSTART
	 * @param list<int> $excluded EXDATE timestamps
	 * @param list<int> $added RDATE timestamps
	 * @param int $maxOccurrences expanding more occurrences throws RuntimeException
	 * @throws InvalidArgumentException for an invalid rule
	 */
	public function __construct(array|string $rule, int $start, array $excluded = [], array $added = [], private readonly int $maxOccurrences = 100000) {
		if ($maxOccurrences < 1) {
			throw new InvalidArgumentException('maxOccurrences must be positive.');
		}
		$this->rule = is_string($rule) ? Rule::fromString($rule) : Rule::fromArray($rule);
		if (($this->rule->count ?? 0) > $maxOccurrences || count($added) > $maxOccurrences) {
			throw new RuntimeException('Recurrence occurrence limit exceeded.');
		}
		$this->start = $start;
		$this->freq = strtolower($this->rule->freq->value);
		$this->excluded = array_fill_keys($excluded, true);
		$added = array_values(array_unique($added));
		sort($added);
		$this->added = $added;
		if ($this->rule->count !== null) {
			$this->getAllOccurrences(); // finite series are calculated eagerly, as before
		}
	}

	/**
	 * Next occurrence after the given timestamp, or false when there is none.
	 */
	public function findNext(int|bool $offset): bool|int {
		if ($offset === false) {
			return false;
		}
		foreach ($this->occurrences() as $timestamp) {
			if ($timestamp > $offset) {
				return $timestamp;
			}
		}
		return false;
	}

	/**
	 * Start of the next FREQ period after the given timestamp (e.g. +1 month for MONTHLY).
	 */
	public function findEndOfPeriod(int $offset = 0): int {
		$unit = match ($this->rule->freq) {
			Frequency::Yearly => 'year',
			Frequency::Monthly => 'month',
			Frequency::Weekly => 'week',
			Frequency::Daily => 'day',
			Frequency::Hourly => 'hour',
			Frequency::Minutely => 'minute',
			Frequency::Secondly => 'second',
		};
		return $this->localDate($offset)->modify("+1 $unit")->getTimestamp();
	}

	/**
	 * Most recent occurrence before the given timestamp, or false when there is none.
	 */
	public function previousOccurrence(int $offset): bool|int {
		$previous = false;
		foreach ($this->occurrences() as $timestamp) {
			if ($timestamp >= $offset) {
				break;
			}
			$previous = $timestamp;
		}
		return $previous;
	}

	/**
	 * Next occurrence after the given timestamp, or false when there is none.
	 */
	public function nextOccurrence(int $offset): bool|int {
		return $this->findNext($offset);
	}

	/**
	 * First occurrence of the recurrence set, or false for an empty set.
	 */
	public function firstOccurrence(): bool|int {
		foreach ($this->occurrences() as $timestamp) {
			return $timestamp;
		}
		return false;
	}

	/**
	 * Last occurrence of the recurrence set, or false for an empty set.
	 */
	public function lastOccurrence(): int|false {
		$all = $this->getAllOccurrences();
		return $all === [] ? false : $all[array_key_last($all)];
	}

	/**
	 * All occurrences, sorted. Unbounded rules throw RuntimeException after maxOccurrences.
	 *
	 * @return list<int>
	 */
	public function getAllOccurrences(): array {
		if ($this->cache === null) {
			$this->cache = iterator_to_array($this->occurrences(), false);
		}
		return $this->cache;
	}

	/**
	 * Sorted recurrence set: RRULE occurrences merged with RDATE, without EXDATE.
	 *
	 * @return Generator<int, int>
	 */
	private function occurrences(): Generator {
		if ($this->cache !== null) {
			yield from $this->cache;
			return;
		}
		$added = $this->added;
		$index = 0;
		$last = null;
		$count = 0;
		$expander = new Expander($this->rule, $this->localDate($this->start), limit: $this->maxOccurrences);
		foreach ($expander as $timestamp) {
			while (isset($added[$index]) && $added[$index] <= $timestamp) {
				yield from $this->emit($added[$index++], $last, $count);
			}
			yield from $this->emit($timestamp, $last, $count);
		}
		while (isset($added[$index])) {
			yield from $this->emit($added[$index++], $last, $count);
		}
	}

	/**
	 * @return Generator<int, int>
	 */
	private function emit(int $timestamp, ?int &$last, int &$count): Generator {
		if ($timestamp !== $last && !isset($this->excluded[$timestamp])) {
			if (++$count > $this->maxOccurrences) {
				throw new RuntimeException('Recurrence occurrence limit exceeded.');
			}
			yield $timestamp;
		}
		$last = $timestamp;
	}

	private function localDate(int $timestamp): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
