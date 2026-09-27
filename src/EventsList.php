<?php
declare(strict_types=1);

namespace om;

use ArrayObject;

/**
 * Copyright (c) Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 * @extends ArrayObject<int, array<string, mixed>>
 */
class EventsList extends ArrayObject {

	/**
	 * Return array of Events
	 */
	public function getArrayCopy(): array {
		return array_values(parent::getArrayCopy());
	}

	/**
	 * Sort in place, oldest dates first. Missing dates sort last.
	 */
	public function sorted(): EventsList {
		return $this->sortByStart(true);
	}

	/**
	 * Sort in place, newest dates first. Missing dates sort last.
	 */
	public function reversed(): EventsList {
		return $this->sortByStart(false);
	}

	/**
	 * Stable sort by DTSTART; each timestamp is calculated only once.
	 */
	private function sortByStart(bool $ascending): EventsList {
		$events = parent::getArrayCopy();
		$timestamps = [];
		$undated = [];
		foreach ($events as $key => $event) {
			$start = $event['DTSTART'] ?? null;
			if ($start === null) {
				$undated[] = $key;
			} else {
				$timestamps[$key] = $this->dtTimestamp($start);
			}
		}
		$ascending ? asort($timestamps, SORT_NUMERIC) : arsort($timestamps, SORT_NUMERIC);

		$sorted = [];
		foreach ([...array_keys($timestamps), ...$undated] as $key) {
			$sorted[$key] = $events[$key];
		}
		$this->exchangeArray($sorted);
		return $this;
	}

	/**
	 * Normalize a DTSTART value to an integer timestamp for stable comparisons.
	 */
	private function dtTimestamp(mixed $value): int {
		if ($value instanceof \DateTimeInterface) {
			return $value->getTimestamp();
		}
		if (is_int($value) || is_float($value) || is_numeric($value)) {
			return (int) $value;
		}
		$ts = strtotime((string) $value);
		return $ts === false ? 0 : $ts;
	}

}
