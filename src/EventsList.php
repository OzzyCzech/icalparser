<?php
declare(strict_types=1);

namespace om;

use ArrayObject;

/**
 * Copyright (c) Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */
class EventsList extends ArrayObject {

	/**
	 * Return array of Events
	 */
	public function getArrayCopy(): array {
		return array_values(parent::getArrayCopy());
	}

	/**
	 * Return sorted EventList (the newest dates are first)
	 */
	public function sorted(): EventsList {
		$this->uasort($this->comparator(true));

		return $this;
	}

	/**
	 * Return reversed sorted EventList (the oldest dates are first)
	 */
	public function reversed(): EventsList {
		$this->uasort($this->comparator(false));

		return $this;
	}

	/**
	 * Return a comparator callable for DTSTART values.
	 * @param bool $ascending When true, sorts ascending (older first) like the original implementation; false inverts order.
	 */
	private function comparator(bool $ascending): callable {
		return function ($a, $b) use ($ascending): int {
			$ad = $a['DTSTART'] ?? null;
			$bd = $b['DTSTART'] ?? null;

			// both equal (including both null)
			if ($ad === $bd) {
				return 0;
			}

			// decide ordering for nulls: nulls sort last
			if ($ad === null) {
				return 1;
			}
			if ($bd === null) {
				return -1;
			}

			$at = $this->dtTimestamp($ad);
			$bt = $this->dtTimestamp($bd);

			if ($at === $bt) {
				return 0;
			}

			if ($ascending) {
				return ($at < $bt) ? -1 : 1;
			}
			return ($at > $bt) ? -1 : 1;
		};
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
