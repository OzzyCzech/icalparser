<?php

namespace om;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Class taken from https://github.com/coopTilleuls/intouch-iCalendar.git (Freq.php)
 *
 * @author PC Drew <pc@schoolblocks.com>
 */

/**
 * A class to store Frequency-rules in. Will allow a easy way to find the
 * last and next occurrence of the rule.
 *
 * No - this is so not pretty. But.. ehh.. You do it better, and I will
 * gladly accept patches.
 *
 * Created by trail-and-error on the examples given in the RFC.
 *
 * TODO: Update to a better way of doing calculating the different options.
 * Instead of only keeping track of the best of the current dates found
 * it should instead keep a array of all the calculated dates within the
 * period.
 * This should fix the issues with multi-rule + multi-rule interference,
 * and make it possible to implement the SETPOS rule.
 * By pushing the next period onto the stack as the last option will
 * (hopefully) remove the need for the awful simpleMode
 *
 * @author Morten Fangel (C) 2008
 * @author Michael Kahn (C) 2013
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class Freq {

	/** @var bool */
	public static bool $debug = false;

	protected array $weekdays = [
		'MO' => 'monday',
		'TU' => 'tuesday',
		'WE' => 'wednesday',
		'TH' => 'thursday',
		'FR' => 'friday',
		'SA' => 'saturday',
		'SU' => 'sunday',
	];
	protected array $knownRules = [
		'month',
		'weekno',
		'day',
		'monthday',
		'yearday',
		'hour',
		'minute',
	]; //others : 'setpos', 'second'

	protected array $ruleModifiers = ['wkst'];
	protected bool $simpleMode = true;

	protected array $rules = ['freq' => 'yearly', 'interval' => 1];
	protected int $start = 0;
	protected string $freq = '';

	protected array $excluded; //EXDATE
	protected array $added;    //RDATE

	protected array $cache; // getAllOccurrences()

	/**
	 * Constructs a new Frequency-rule
	 *
	 * @param array|string $rule
	 * @param int $start Unix-timestamp (important : Need to be the start of Event)
	 * @param array $excluded of int (timestamps), see EXDATE documentation
	 * @param array $added of int (timestamps), see RDATE documentation
	 * @throws Exception
	 */
	public function __construct(array|string $rule, int $start, array $excluded = [], array $added = []) {
		$this->start = $start;
		$this->excluded = [];

		$rules = [];
		foreach ($rule as $k => $v) {
			$this->rules[strtolower($k)] = $v;
		}

		if (isset($this->rules['until']) && is_string($this->rules['until'])) {
			$this->rules['until'] = strtotime($this->rules['until']);
		} elseif ($this->rules['until'] instanceof DateTime) {
			$this->rules['until'] = $this->rules['until']->getTimestamp();
		}
		$this->freq = strtolower($this->rules['freq']);

		foreach ($this->knownRules as $rule) {
			if (isset($this->rules['by' . $rule])) {
				if ($this->isPrerule($rule, $this->freq)) {
					$this->simpleMode = false;
				}
			}
		}

		if (!$this->simpleMode) {
			if (!(isset($this->rules['byday']) || isset($this->rules['bymonthday']) || isset($this->rules['byyearday']))) {
				$this->rules['bymonthday'] = date('d', $this->start);
			}
		}

		//set until, and cache
		if (isset($this->rules['count'])) {

			$cache[$ts] = $ts = $this->start;
			for ($n = 1; $n < $this->rules['count']; $n++) {
				$ts = $this->findNext($ts);
				$cache[$ts] = $ts;
			}
			$this->rules['until'] = $ts;

			//EXDATE
			if (!empty($excluded)) {
				foreach ($excluded as $ts) {
					unset($cache[$ts]);
				}
			}
			//RDATE
			if (!empty($added)) {
				$cache = array_unique(array_merge(array_values($cache), $added));
				asort($cache);
			}

			$this->cache = array_values($cache);
		}

		$this->excluded = $excluded;
		$this->added = $added;
	}

	private function isPrerule(string $rule, string $freq): bool {
		if ($rule === 'year') {
			return false;
		}
		if ($rule === 'month' && $freq === 'yearly') {
			return true;
		}
		if ($rule === 'monthday' && in_array($freq, ['yearly', 'monthly']) && !isset($this->rules['byday'])) {
			return true;
		}
		// TODO: is it faster to do monthday first, and ignore day if monthday exists? - prolly by a factor of 4..
		if ($rule === 'yearday' && $freq === 'yearly') {
			return true;
		}
		if ($rule === 'weekno' && $freq === 'yearly') {
			return true;
		}
		if ($rule === 'day' && in_array($freq, ['yearly', 'monthly', 'weekly'])) {
			return true;
		}
		if ($rule === 'hour' && in_array($freq, ['yearly', 'monthly', 'weekly', 'daily'])) {
			return true;
		}
		if ($rule === 'minute') {
			return true;
		}

		return false;
	}

	/**
	 * Calculates the next time after the given offset that the rule
	 * will apply.
	 *
	 * The approach to finding the next is as follows:
	 * First we establish a timeframe to find timestamps in. This is
	 * between $offset and the end of the period that $offset is in.
	 *
	 * We then loop though all the rules (that is a Prerule in the
	 * current freq.), and finds the smallest timestamp inside the
	 * timeframe.
	 *
	 * If we find something, we check if the date is a valid recurrence
	 * (with validDate). If it is, we return it. Otherwise we try to
	 * find a new date inside the same timeframe (but using the new-
	 * found date as offset)
	 *
	 * If no new timestamps were found in the period, we try in the
	 * next period
	 *
	 * @param int $offset
	 * @return int|bool
	 * @throws Exception
	 */
	public function findNext(int $offset): bool|int {
		if (!empty($this->cache)) {
			foreach ($this->cache as $ts) {
				if ($ts > $offset) {
					return $ts;
				}
			}
		}

		//make sure the offset is valid
		if ($offset === false || (isset($this->rules['until']) && $offset > $this->rules['until'])) {
			if (static::$debug) printf("STOP: %s\n", date('r', $offset));
			return false;
		}

		$found = true;

		//set the timestamp of the offset (ignoring hours and minutes unless we want them to be
		//part of the calculations.
		if (static::$debug) printf("O: %s\n", date('r', $offset));
		$hour = (in_array($this->freq, ['hourly', 'minutely']) && $offset>$this->start) ? date('H', $offset) : date('H', $this->start);
		$minute = (($this->freq === 'minutely' || isset($this->rules['byminute'])) && $offset > $this->start) ? date('i', $offset) : date('i', $this->start);
		$t = mktime($hour, $minute, date('s', $this->start), date('m', $offset), date('d', $offset), date('Y', $offset));
		if (static::$debug) printf("START: %s\n", date('r', $t));

		if ($this->simpleMode) {
			if ($offset < $t) {
				$ts = $t;
				if ($ts && in_array($ts, $this->excluded, true)) {
					$ts = $this->findNext($ts);
				}
			} else {
				$ts = $this->findStartingPoint($t, $this->rules['interval'], false);
				if (!$this->validDate($ts)) {
					$ts = $this->findNext($ts);
				}
			}

			return $ts;
		}

		//EOP needs to have the same TIME as START ($t)
		$tO = new DateTime('@' . $t, new DateTimeZone('UTC'));

		$eop = $this->findEndOfPeriod($offset);
		$eopO = new DateTime('@' . $eop, new DateTimeZone('UTC'));
		$eopO->setTime($tO->format('H'), $tO->format('i'), $tO->format('s'));
		$eop = $eopO->getTimestamp();
		unset($eopO, $tO);

		if (static::$debug) {
			echo 'EOP: ' . date('r', $eop) . "\n";
		}
		foreach ($this->knownRules as $rule) {
			if ($found && isset($this->rules['by' . $rule])) {
				if ($this->isPrerule($rule, $this->freq)) {
					$subRules = explode(',', $this->rules['by' . $rule]);
					$_t = null;
					foreach ($subRules as $subRule) {
						$imm = call_user_func_array([$this, "ruleBy$rule"], [$subRule, $t]);
						if ($imm === false) {
							break;
						}
						if (static::$debug) {
							printf("%s: %s A: %d\n", strtoupper($rule), date('r', $imm), intval($imm > $offset && $imm < $eop));
						}
						if ($imm > $offset && $imm <= $eop && ($_t == null || $imm < $_t)) {
							$_t = $imm;
						}
					}
					if ($_t !== null) {
						$t = $_t;
					} else {
						$found = $this->validDate($t);
					}
				}
			}
		}

		if ($offset < $this->start && $this->start < $t) {
			$ts = $this->start;
		} elseif ($found && ($t != $offset)) {
			if ($this->validDate($t)) {
				if (static::$debug) echo 'OK' . "\n";
				$ts = $t;
			} else {
				if (static::$debug) echo 'Invalid' . "\n";
				$ts = $this->findNext($t);
			}
		} else {
			if (static::$debug) echo 'Not found' . "\n";
			$ts = $this->findNext($this->findStartingPoint($offset, $this->rules['interval']));
		}
		if ($ts && in_array($ts, $this->excluded, true)) {
			return $this->findNext($ts);
		}

		return $ts;
	}

	/**
	 * Finds the starting point for the next rule. It goes $interval
	 * 'freq' forward in time since the given offset
	 *
	 * @param int $offset
	 * @param int $interval
	 * @param boolean $truncate
	 * @return int
	 */
	private function findStartingPoint(int $offset, int $interval, bool $truncate = true): int {
		$_freq = ($this->freq === 'daily') ? 'day__' : $this->freq;
		$t = '+' . $interval . ' ' . substr($_freq, 0, -2) . 's';
		if ($_freq === 'monthly' && $truncate) {
			if ($interval > 1) {
				$offset = strtotime('+' . ($interval - 1) . ' months ', $offset); // FIXME return type int|false
			}
			$t = '+' . (date('t', $offset) - date('d', $offset) + 1) . ' days';
		}

		$sp = strtotime($t, $offset);

		if ($truncate) {
			$sp = $this->truncateToPeriod($sp, $this->freq);
		}

		return $sp;
	}

	/**
	 * Resets the timestamp to the beginning of the
	 * period specified by freq
	 *
	 * Yes - the fall-through is on purpose!
	 *
	 * @param int $time
	 * @param string $freq
	 * @return int
	 */
	private function truncateToPeriod(int $time, string $freq): int {
		$date = getdate($time);
		switch ($freq) {
			case 'yearly':
				$date['mon'] = 1;
			case 'monthly':
				$date['mday'] = 1;
			case 'daily':
				$date['hours'] = 0;
			case 'hourly':
				$date['minutes'] = 0;
			case 'minutely':
				$date['seconds'] = 0;
				break;
			case 'weekly':
				if (date('N', $time) == 1) { // FIXME wrong compare, date return string|false
					$date['hours'] = 0;
					$date['minutes'] = 0;
					$date['seconds'] = 0;
				} else {
					$date = getdate(strtotime('last monday 0:00', $time));
				}
				break;
		}
		return mktime($date['hours'], $date['minutes'], $date['seconds'], $date['mon'], $date['mday'], $date['year']);
	}

	private function validDate($t): bool {
		if (isset($this->rules['until']) && $t > $this->rules['until']) {
			return false;
		}

		if (in_array($t, $this->excluded, true)) {
			return false;
		}

		if (isset($this->rules['bymonth'])) {
			$months = explode(',', $this->rules['bymonth']);
			if (!in_array(date('m', $t), $months, true)) {
				return false;
			}
		}
		if (isset($this->rules['byday'])) {
			$days = explode(',', $this->rules['byday']);
			foreach ($days as $i => $k) {
				$days[$i] = $this->weekdays[preg_replace('/[^A-Z]/', '', $k)];
			}
			if (!in_array(strtolower(date('l', $t)), $days, true)) {
				return false;
			}
		}
		if (isset($this->rules['byweekno'])) {
			$weeks = explode(',', $this->rules['byweekno']);
			if (!in_array(date('W', $t), $weeks, true)) {
				return false;
			}
		}
		if (isset($this->rules['bymonthday'])) {
			$weekdays = explode(',', $this->rules['bymonthday']);
			foreach ($weekdays as $i => $k) {
				if ($k < 0) {
					$weekdays[$i] = date('t', $t) + $k + 1;
				}
			}
			if (!in_array(date('d', $t), $weekdays, true)) {
				return false;
			}
		}
		if (isset($this->rules['byhour'])) {
			$hours = explode(',', $this->rules['byhour']);
			if (!in_array(date('H', $t), $hours, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Finds the earliest timestamp possible outside this period.
	 *
	 * @param int $offset
	 * @return int
	 */
	public function findEndOfPeriod(int $offset = 0): int {
		return $this->findStartingPoint($offset, 1, false);
	}

	/**
	 * Returns the previous (most recent) occurrence of the rule from the
	 * given offset
	 *
	 * @param int $offset
	 * @return int
	 * @throws Exception
	 */
	public function previousOccurrence(int $offset): bool|int {
		if (!empty($this->cache)) {
			$t2 = $this->start;
			foreach ($this->cache as $ts) {
				if ($ts >= $offset) {
					return $t2;
				}
				$t2 = $ts;
			}
		} else {
			$ts = $this->start;
			while (($t2 = $this->findNext($ts)) < $offset) {
				if ($t2 == false) {
					break;
				}
				$ts = $t2;
			}
		}

		return $ts;
	}

	/**
	 * Returns the next occurrence of this rule after the given offset
	 *
	 * @param int $offset
	 * @return int
	 * @throws Exception
	 */
	public function nextOccurrence(int $offset): bool|int {
		if ($offset < $this->start) {
			return $this->firstOccurrence();
		}
		return $this->findNext($offset);
	}

	/**
	 * Finds the first occurrence of the rule.
	 *
	 * @return int timestamp
	 * @throws Exception
	 */
	public function firstOccurrence(): bool|int {
		$t = $this->start;
		if (in_array($t, $this->excluded)) {
			$t = $this->findNext($t);
		}

		return $t;
	}

	/**
	 * Finds the absolute last occurrence of the rule from the given offset.
	 * Builds also the cache, if not set before...
	 *
	 * @return int timestamp
	 * @throws Exception
	 */
	public function lastOccurrence(): int {
		//build cache if not done
		$this->getAllOccurrences();
		//return last timestamp in cache
		return end($this->cache);
	}

	/**
	 * Returns all timestamps array(), build the cache if not made before
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getAllOccurrences(): array {
		if (empty($this->cache)) {
			$cache = [];

			//build cache
			$next = $this->firstOccurrence();
			while ($next) {
				$cache[] = $next;
				$next = $this->findNext($next);
			}
			if (!empty($this->added)) {
				$cache = array_unique(array_merge($cache, $this->added));
				asort($cache);
			}
			$this->cache = $cache;
		}

		return $this->cache;
	}

	/**
	 * Applies the BYDAY rule to the given timestamp
	 *
	 * @param string $rule
	 * @param int $t
	 * @return int
	 */
	private function ruleByDay(string $rule, int $t): int {
		$dir = ($rule[0] === '-') ? -1 : 1;
		$dir_t = ($dir === 1) ? 'next' : 'last';

		$d = $this->weekdays[substr($rule, -2)];
		$s = $dir_t . ' ' . $d . ' ' . date('H:i:s', $t);

		if ($rule == substr($rule, -2)) {
			if (date('l', $t) == ucfirst($d)) {
				$s = 'today ' . date('H:i:s', $t);
			}

			$_t = strtotime($s, $t);

			if ($_t == $t && in_array($this->freq, ['weekly', 'monthly', 'yearly'])) {
				// Yes. This is not a great idea.. but hey, it works.. for now
				$s = 'next ' . $d . ' ' . date('H:i:s', $t);
				$_t = strtotime($s, $_t);
			}

			return $_t;
		} else {
			$_f = $this->freq;
			if (isset($this->rules['bymonth']) && $this->freq === 'yearly') {
				$this->freq = 'monthly';
			}
			if ($dir === -1) {
				$_t = $this->findEndOfPeriod($t);
			} else {
				$_t = $this->truncateToPeriod($t, $this->freq);
			}
			$this->freq = $_f;

			$c = preg_replace('/[^0-9]/', '', $rule);
			$c = ($c == '') ? 1 : $c;

			$n = $_t;
			while ($c > 0) {
				if ($dir === 1 && $c == 1 && date('l', $t) == ucfirst($d)) {
					$s = 'today ' . date('H:i:s', $t);
				}
				$n = strtotime($s, $n);
				$c--;
			}

			return $n;
		}
	}

	private function ruleByMonth($rule, int $t): bool|int {
		$_t = mktime(date('H', $t), date('i', $t), date('s', $t), $rule, date('d', $t), date('Y', $t));
		if ($t == $_t && isset($this->rules['byday'])) {
			// TODO: this should check if one of the by*day's exists, and have a multi-day value
			return false;
		} else {
			return $_t;
		}
	}

	private function ruleByMonthday($rule, int $t): bool|int {
		if ($rule < 0) {
			$rule = date('t', $t) + $rule + 1;
		}

		return mktime(date('H', $t), date('i', $t), date('s', $t), date('m', $t), $rule, date('Y', $t));
	}

	private function ruleByYearday($rule, int $t): bool|int {
		if ($rule < 0) {
			$_t = $this->findEndOfPeriod();
			$d = '-';
		} else {
			$_t = $this->truncateToPeriod($t, $this->freq);
			$d = '+';
		}
		$s = $d . abs($rule - 1) . ' days ' . date('H:i:s', $t);

		return strtotime($s, $_t);
	}

	private function ruleByWeekno($rule, int $t): bool|int {
		if ($rule < 0) {
			$_t = $this->findEndOfPeriod();
			$d = '-';
		} else {
			$_t = $this->truncateToPeriod($t, $this->freq);
			$d = '+';
		}

		$sub = (date('W', $_t) == 1) ? 2 : 1;
		$s = $d . abs($rule - $sub) . ' weeks ' . date('H:i:s', $t);
		$_t = strtotime($s, $_t);

		return $_t;
	}

	private function ruleByHour($rule, int $t): bool|int {
		return mktime($rule, date('i', $t), date('s', $t), date('m', $t), date('d', $t), date('Y', $t));
	}

	private function ruleByMinute($rule, int $t): bool|int {
		return mktime(date('h', $t), $rule, date('s', $t), date('m', $t), date('d', $t), date('Y', $t));
	}
}
