<?php

namespace om;

use DateTime;
use Exception;

/**
 * Class taken from https://github.com/coopTilleuls/intouch-iCalendar.git (Recurrence.php)
 *
 * A wrapper for recurrence rules in iCalendar.  Parses the given line and puts the
 * recurrence rules in the correct field of this object.
 *
 * See http://tools.ietf.org/html/rfc2445 for more information.  Page 39 and onward contains more
 * information on the recurrence rules themselves.  Page 116 and onward contains
 * some great examples which were often used for testing.
 *
 * @author Steven Oxley
 * @author Michael Kahn (C) 2013
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class Recurrence {

	public array $rrule;
	protected mixed $freq;
	protected mixed $until;
	protected mixed $count;
	protected mixed $interval;
	protected mixed $bysecond;
	protected mixed $byminute;
	protected mixed $byhour;
	protected mixed $byday;
	protected mixed $bymonthday;
	protected mixed $byyearday;
	protected mixed $byweekno;
	protected mixed $bymonth;
	protected mixed $bysetpos;
	protected mixed $wkst;
	/**
	 * A list of the properties that can have comma-separated lists for values.
	 *
	 * @var array
	 */
	protected array $listProperties = [
		'bysecond', 'byminute', 'byhour', 'byday', 'bymonthday',
		'byyearday', 'byweekno', 'bymonth', 'bysetpos',
	];

	/**
	 * Creates a recurrence object with a passed in line.  Parses the line.
	 *
	 * @param array $rrule an om\icalparser row array which will be parsed to get the
	 * desired information.
	 */
	public function __construct(array $rrule) {
		$this->parseRrule($rrule);
	}

	/**
	 * Parses an 'RRULE' array and sets the member variables of this object.
	 * Expects a string that looks like this:  'FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,TU,WE'
	 *
	 * @param array $rrule
	 */
	protected function parseRrule(array $rrule): void {
		$this->rrule = $rrule;
		//loop through the properties in the line and set their associated
		//member variables
		foreach ($this->rrule as $propertyName => $propertyValue) {
			//need the lower-case name for setting the member variable
			$propertyName = strtolower($propertyName);
			//split up the list of values into an array (if it's a list)
			if (in_array($propertyName, $this->listProperties, true)) {
				$propertyValue = explode(',', $propertyValue);
			}
			$this->$propertyName = $propertyValue;
		}
	}

	/**
	 * Returns the frequency - corresponds to FREQ in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getFreq(): mixed {
		return $this->getMember('freq');
	}

	/**
	 * Retrieves the desired member variable and returns it (if it's set)
	 *
	 * @param string $member name of the member variable
	 * @return mixed  the variable value (if set), false otherwise
	 */
	protected function getMember(string $member): mixed {
		return $this->$member ?? false;
	}

	/**
	 * Returns when the event will go until - corresponds to UNTIL in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getUntil(): mixed {
		return $this->getMember('until');
	}

	/**
	 * Set the $until member
	 *
	 * @param mixed $ts timestamp (int) / Valid DateTime format (string)
	 * @throws Exception
	 */
	public function setUntil(mixed $ts): void {
		if ($ts instanceof DateTime) {
			$dt = $ts;
		} elseif (is_int($ts)) {
			$dt = new DateTime('@' . $ts);
		} else {
			$dt = new DateTime($ts);
		}
		$this->until = $dt->format('Ymd\THisO');
		$this->rrule['until'] = $this->until;
	}

	/**
	 * Returns the count of the times the event will occur (should only appear if 'until'
	 * does not appear) - corresponds to COUNT in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getCount(): mixed {
		return $this->getMember('count');
	}

	/**
	 * Returns the interval - corresponds to INTERVAL in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getInterval(): mixed {
		return $this->getMember('interval');
	}

	/**
	 * Returns the bysecond part of the event - corresponds to BYSECOND in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getBySecond(): mixed {
		return $this->getMember('bysecond');
	}

	/**
	 * Returns the byminute information for the event - corresponds to BYMINUTE in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMinute(): mixed {
		return $this->getMember('byminute');
	}

	/**
	 * Corresponds to BYHOUR in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByHour(): mixed {
		return $this->getMember('byhour');
	}

	/**
	 *Corresponds to BYDAY in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByDay(): mixed {
		return $this->getMember('byday');
	}

	/**
	 * Corresponds to BYMONTHDAY in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMonthDay(): mixed {
		return $this->getMember('bymonthday');
	}

	/**
	 * Corresponds to BYYEARDAY in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByYearDay(): mixed {
		return $this->getMember('byyearday');
	}

	/**
	 * Corresponds to BYWEEKNO in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByWeekNo(): mixed {
		return $this->getMember('byweekno');
	}

	/**
	 * Corresponds to BYMONTH in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMonth(): mixed {
		return $this->getMember('bymonth');
	}

	/**
	 * Corresponds to BYSETPOS in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getBySetPos(): mixed {
		return $this->getMember('bysetpos');
	}

	/**
	 * Corresponds to WKST in RFC 2445.
	 *
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getWkst(): mixed {
		return $this->getMember('wkst');
	}
}
