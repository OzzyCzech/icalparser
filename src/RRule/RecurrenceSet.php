<?php
declare(strict_types=1);

namespace om\RRule;

use DateTime;
use DateTimeInterface;
use Generator;
use IteratorAggregate;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\ResourceLimitException;

/**
 * Recurrence set of RFC 5545, section 3.8.5: RRULE occurrences and RDATE values,
 * minus EXDATE values, as sorted unique timestamps.
 *
 * EXDATE values may also be whole days (date-only EXDATE of a DATE-TIME event),
 * which remove every occurrence on that local day.
 *
 * @implements IteratorAggregate<int, int>
 */
final class RecurrenceSet implements IteratorAggregate {
	/** @var list<int> */
	private readonly array $rdates;
	/** @var array<int, true> */
	private readonly array $exdates;
	/** @var array<string, true> */
	private readonly array $exdays;

	/** @var list<Rule> */
	private readonly array $rules;

	/**
	 * @param Rule|list<Rule>|null $rule RRULE (several rules are combined, as in RFC 2445); without it the set starts with DTSTART only
	 * @param list<int> $rdates RDATE timestamps
	 * @param list<int> $exdates EXDATE timestamps
	 * @param list<string> $exdays EXDATE days as "Ymd" in the timezone of DTSTART
	 * @param ?int $until inclusive end of the RRULE expansion (RDATE values are not limited)
	 * @param ?int $from RRULE occurrences before this timestamp are skipped
	 * @param int $limit maximal number of returned occurrences
	 * @param bool $strict throw ResourceLimitException instead of stopping at the limit
	 * @param int $maxIterations see Expander
	 * @throws InvalidRecurrenceRuleException recurrence.unsupported-rscale for a rule of another calendar system than GREGORIAN
	 */
	public function __construct(
		private readonly DateTimeInterface $start,
		Rule|array|null $rule = null,
		array $rdates = [],
		array $exdates = [],
		array $exdays = [],
		private readonly ?int $until = null,
		private readonly ?int $from = null,
		private readonly int $limit = PHP_INT_MAX,
		private readonly bool $strict = false,
		private readonly int $maxIterations = PHP_INT_MAX,
	) {
		$this->rules = $rule === null ? [] : ($rule instanceof Rule ? [$rule] : $rule);
		foreach ($this->rules as $item) {
			$item->assertGregorian();
		}
		$rdates = array_values(array_unique($rdates));
		sort($rdates);
		$this->rdates = $rdates;
		$this->exdates = array_fill_keys($exdates, true);
		$this->exdays = array_fill_keys($exdays, true);
	}

	/**
	 * @return Generator<int, int>
	 */
	public function getIterator(): Generator {
		$last = null;
		$count = 0;
		foreach ($this->merged() as $timestamp) {
			if ($timestamp === $last || isset($this->exdates[$timestamp])
				|| ($this->exdays !== [] && isset($this->exdays[$this->day($timestamp)]))) {
				continue;
			}
			if ($count >= $this->limit) {
				if ($this->strict) {
					throw ResourceLimitException::create('recurrence.limit', "Recurrence occurrence limit of {$this->limit} exceeded.");
				}
				return;
			}
			$count++;
			$last = $timestamp;
			yield $timestamp;
		}
	}

	/**
	 * RRULE occurrences merged with RDATE values, sorted, possibly with duplicates.
	 *
	 * @return Generator<int, int>
	 */
	private function merged(): Generator {
		$rdates = $this->rdates;
		$index = 0;
		foreach ($this->ruleOccurrences() as $timestamp) {
			while (isset($rdates[$index]) && $rdates[$index] < $timestamp) {
				yield $rdates[$index++];
			}
			yield $timestamp;
		}
		while (isset($rdates[$index])) {
			yield $rdates[$index++];
		}
	}

	/**
	 * Occurrences of all rules merged in order (duplicates are removed later).
	 *
	 * @return iterable<int>
	 */
	private function ruleOccurrences(): iterable {
		if ($this->rules === []) {
			return [$this->start->getTimestamp()];
		}
		return (function (): Generator {
			$streams = [];
			foreach ($this->rules as $rule) {
				$stream = (new Expander($rule, $this->start, $this->until, PHP_INT_MAX, $this->maxIterations))->getIterator();
				if ($stream->valid()) {
					$streams[] = $stream;
				}
			}
			while ($streams !== []) {
				$next = 0;
				foreach ($streams as $index => $stream) {
					if ($stream->current() < $streams[$next]->current()) {
						$next = $index;
					}
				}
				$timestamp = $streams[$next]->current();
				$streams[$next]->next();
				if (!$streams[$next]->valid()) {
					array_splice($streams, $next, 1);
				}
				if ($this->from === null || $timestamp >= $this->from) {
					yield $timestamp;
				}
			}
		})();
	}

	private function day(int $timestamp): string {
		return DateTime::createFromInterface($this->start)->setTimestamp($timestamp)->format('Ymd');
	}
}
