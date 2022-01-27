<?php

namespace om;

/**
 * Copyright (c) 2004-2022 Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */
class EventsList extends \ArrayObject {

	/**
	 * Return array of Events
	 *
	 * @return array
	 */
	public function getArrayCopy(): array {
		return array_values(parent::getArrayCopy());
	}

	/**
	 * Return sorted EventList (the newest dates are first)
	 *
	 * @return $this
	 */
	public function sorted(): EventsList {
		$this->uasort(static function ($a, $b): int {
			if ($a['DTSTART'] === $b['DTSTART']) {
				return 0;
			}
			return ($a['DTSTART'] < $b['DTSTART']) ? -1 : 1;
		});

		return $this;
	}

	/**
	 * Return reversed sorted EventList (the oldest dates are first)
	 *
	 * @return $this
	 */
	public function reversed(): EventsList {
		$this->uasort(static function ($a, $b): int {
			if ($a['DTSTART'] === $b['DTSTART']) {
				return 0;
			}
			return ($a['DTSTART'] > $b['DTSTART']) ? -1 : 1;
		});

		return $this;
	}

}