<?php
declare(strict_types=1);

namespace om\ICal;

/**
 * Keeps occurrences sorted by start until no later instance can come before them.
 *
 * @internal used by Item
 */
final class OccurrenceBuffer {
	/** @var list<array{int, Occurrence}> */
	private array $items = [];

	/**
	 * @param list<Occurrence> $occurrences initial occurrences (e.g. modified instances)
	 */
	public function __construct(
		private readonly TimeSpace $space,
		array $occurrences = [],
	) {
		foreach ($occurrences as $occurrence) {
			$this->add($occurrence);
		}
	}

	public function add(Occurrence $occurrence): void {
		$key = $this->space->toBase($occurrence->start);
		$position = count($this->items);
		while ($position > 0 && $this->items[$position - 1][0] > $key) {
			$position--;
		}
		array_splice($this->items, $position, 0, [[$key, $occurrence]]);
	}

	/**
	 * Remove and return the occurrences starting at or before the timestamp, in order.
	 *
	 * @return list<Occurrence>
	 */
	public function ready(int $until): array {
		$ready = [];
		while ($this->items !== [] && $this->items[0][0] <= $until) {
			$ready[] = array_shift($this->items)[1];
		}
		return $ready;
	}
}
