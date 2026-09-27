<?php
declare(strict_types=1);

namespace om\RRule;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use IteratorAggregate;
use RuntimeException;

/**
 * Expands a recurrence rule into occurrence timestamps (RFC 5545, section 3.3.10).
 *
 * Each FREQ period (year, month, week, day, hour, ...) is turned into a set of
 * candidate days and times, which the BYxxx rule parts then filter. Filtering
 * a whole period covers both the "expand" and the "limit" behaviour described
 * in the RFC table, and BYSETPOS then picks from the sorted period set.
 *
 * Calculations use wall-clock dates in the DTSTART timezone, so daylight saving
 * transitions keep the local time, and the process default timezone is never changed.
 * DTSTART always counts as the first occurrence, even when it does not match the rule.
 *
 * @implements IteratorAggregate<int, int>
 */
final class Expander implements IteratorAggregate {
	/** Stop searching after this many consecutive periods without any candidate. */
	public const int MAX_EMPTY_PERIODS = 100000;
	private const int MAX_DAYS = 2932897; // 10000-01-01, the first day that is not expanded

	private readonly DateTimeZone $timezone;
	private readonly ?int $fixedOffset;
	private readonly DateTime $probe;
	/** UTC offset valid for instants in [$offsetFrom, $offsetUntil), see toTimestamp() */
	private int $offset = 0;
	private int $offsetFrom = PHP_INT_MAX;
	private int $offsetUntil = PHP_INT_MIN;

	/** @var array<int, true> */
	private array $months = [];
	/** @var array<int, true> */
	private array $monthDays = [];
	/** @var array<int, true> */
	private array $yearDays = [];
	/** @var array<int, true> */
	private array $weekNumbers = [];
	/** @var array<int, true> weekdays without an ordinal */
	private array $weekdays = [];
	/** @var array<int, list<int>> ordinals by weekday */
	private array $weekdayOrdinals = [];
	private bool $hasDayFilter;
	private string $ordinalScope;
	/** @var list<int> */
	private array $hours;
	/** @var list<int> */
	private array $minutes;
	/** @var list<int> */
	private array $seconds;
	/** @var list<int> seconds of the day, for FREQ coarser than HOURLY */
	private array $timeOfDay = [];
	/** @var array<int, int> */
	private array $week1Cache = [];

	/**
	 * @param ?int $horizon additional inclusive end of the expansion (timestamp)
	 * @param int $limit maximal number of occurrences; exceeding it throws RuntimeException
	 */
	public function __construct(
		private readonly Rule $rule,
		private readonly DateTimeInterface $start,
		private readonly ?int $horizon = null,
		private readonly int $limit = 100000,
	) {
		$timezone = $start->getTimezone();
		$name = $timezone->getName();
		if ($name === 'Z' || $name === 'UTC' || $name === 'GMT' || preg_match('/^[+-]\d{2}:\d{2}$/D', $name)) {
			$this->fixedOffset = $timezone->getOffset(new DateTimeImmutable('@0'));
			$timezone = $name === 'Z' ? new DateTimeZone('UTC') : $timezone;
		} else {
			$this->fixedOffset = null;
		}
		$this->timezone = $timezone;
		$this->probe = (new DateTime('@0'))->setTimezone($timezone);
	}

	/**
	 * @return Generator<int, int>
	 */
	public function getIterator(): Generator {
		$rule = $this->rule;
		$local = DateTimeImmutable::createFromInterface($this->start)->setTimezone($this->timezone);
		[$year, $month, $day, $hour, $minute, $second] = array_map('intval', explode(' ', $local->format('Y n j G i s')));
		$startDays = self::daysFromCivil($year, $month, $day);
		$startTs = $this->start->getTimestamp();

		$until = $rule->untilTimestamp($this->timezone);
		if ($this->horizon !== null) {
			$until = $until === null ? $this->horizon : min($until, $this->horizon);
		}
		$until ??= PHP_INT_MAX;

		// DTSTART is always the first instance, even when UNTIL or the horizon is earlier
		yield $startTs;
		$emitted = 1;
		if ($startTs > $until || ($rule->count !== null && $emitted >= $rule->count)) {
			return;
		}

		if (!$this->prepare($month, $day, self::weekday($startDays), $hour, $minute, $second)) {
			return;
		}

		$freq = $rule->freq;
		$interval = $rule->interval;
		$periodDays = match ($freq) {
			Frequency::Weekly => $startDays - ((self::weekday($startDays) - $rule->wkst + 7) % 7),
			default => $startDays,
		};
		$periodSeconds = match ($freq) {
			Frequency::Hourly => $hour * 3600,
			Frequency::Minutely => $hour * 3600 + $minute * 60,
			Frequency::Secondly => $hour * 3600 + $minute * 60 + $second,
			default => 0,
		};
		$step = $interval * match ($freq) {
			Frequency::Hourly => 3600,
			Frequency::Minutely => 60,
			default => 1,
		};
		$subDaily = !$freq->isCoarserThan(Frequency::Hourly);
		$last = $startTs;
		$emptyPeriods = 0;

		while (true) {
			// sub-daily periods of a day (or hour) that cannot match are skipped at once
			$skip = $subDaily ? $this->secondsToSkip($freq, $periodDays, $periodSeconds) : 0;
			$candidates = $skip === 0 ? $this->candidates($freq, $year, $month, $periodDays, $periodSeconds) : [];

			if ($candidates === []) {
				if (++$emptyPeriods > self::MAX_EMPTY_PERIODS || $this->periodStart($freq, $year, $month, $periodDays, $periodSeconds) > $until) {
					return;
				}
			} else {
				$emptyPeriods = 0;
				if ($rule->bySetPos !== []) {
					$candidates = $this->applySetPos($candidates);
				}
				foreach ($candidates as [$days, $time]) {
					if ($days >= self::MAX_DAYS) {
						return;
					}
					$ts = $this->toTimestamp($days, $time);
					if ($ts > $until) {
						return;
					}
					// skips times before DTSTART and wall-clock times repeated by a DST transition
					if ($ts <= $last) {
						continue;
					}
					yield $ts;
					$last = $ts;
					if (++$emitted > $this->limit) {
						throw new RuntimeException('Recurrence occurrence limit exceeded.');
					}
					if ($rule->count !== null && $emitted >= $rule->count) {
						return;
					}
				}
			}

			switch ($freq) {
				case Frequency::Yearly:
					$year += $interval;
					break;
				case Frequency::Monthly:
					$month += $interval;
					$year += intdiv($month - 1, 12);
					$month = ($month - 1) % 12 + 1;
					break;
				case Frequency::Weekly:
					$periodDays += 7 * $interval;
					break;
				case Frequency::Daily:
					$periodDays += $interval;
					break;
				default:
					$periodSeconds += $skip > 0 ? intdiv($skip + $step - 1, $step) * $step : $step;
					$periodDays += intdiv($periodSeconds, 86400);
					$periodSeconds %= 86400;
			}
			if ($year > 9999 || $periodDays >= self::MAX_DAYS) {
				return;
			}
		}
	}

	/**
	 * For sub-daily rules: seconds until the next day (or hour, or minute) that may match,
	 * 0 when the current period may produce an occurrence.
	 */
	private function secondsToSkip(Frequency $freq, int $days, int $seconds): int {
		if (!$this->matchesDayNumber($days)) {
			return 86400 - $seconds;
		}
		if ($freq !== Frequency::Hourly && $this->hours !== [] && !in_array(intdiv($seconds, 3600), $this->hours, true)) {
			return 3600 - $seconds % 3600;
		}
		if ($freq === Frequency::Secondly && $this->minutes !== [] && !in_array(intdiv($seconds % 3600, 60), $this->minutes, true)) {
			return 60 - $seconds % 60;
		}
		return 0;
	}

	/**
	 * Earliest possible instant of a period (a day early, to be safe with any UTC offset).
	 */
	private function periodStart(Frequency $freq, int $year, int $month, int $days, int $seconds): int {
		$first = match ($freq) {
			Frequency::Yearly => self::daysFromCivil($year, 1, 1),
			Frequency::Monthly => self::daysFromCivil($year, $month, 1),
			default => $days,
		};
		return ($first - 1) * 86400 + $seconds;
	}

	/**
	 * Apply the RFC 5545 defaults: rule parts missing from the rule are taken from DTSTART.
	 *
	 * @return bool false when the rule cannot produce any further occurrence
	 */
	private function prepare(int $month, int $day, int $weekday, int $hour, int $minute, int $second): bool {
		$rule = $this->rule;
		$byMonth = $rule->byMonth;
		$byMonthDay = $rule->byMonthDay;
		$byDay = $rule->byDay;

		if ($rule->byWeekNo === [] && $rule->byYearDay === [] && $byMonthDay === [] && $byDay === []) {
			switch ($rule->freq) {
				case Frequency::Yearly:
					$byMonth = $byMonth ?: [$month];
					$byMonthDay = [$day];
					break;
				case Frequency::Monthly:
					$byMonthDay = [$day];
					break;
				case Frequency::Weekly:
					$byDay = [[0, $weekday]];
					break;
				default:
			}
		}

		$this->months = array_fill_keys($byMonth, true);
		$this->monthDays = array_fill_keys($byMonthDay, true);
		$this->yearDays = array_fill_keys($rule->byYearDay, true);
		$this->weekNumbers = array_fill_keys($rule->byWeekNo, true);

		// An ordinal BYDAY means "nth weekday of the month" for MONTHLY rules and for YEARLY
		// rules restricted by BYMONTH, "nth weekday of the year" for other YEARLY rules.
		// The RFC does not allow ordinals with other frequencies, so they are ignored there.
		$this->ordinalScope = match (true) {
			$rule->freq === Frequency::Monthly, $rule->freq === Frequency::Yearly && $byMonth !== [] => 'month',
			$rule->freq === Frequency::Yearly => 'year',
			default => 'none',
		};
		foreach ($byDay as [$ordinal, $weekdayNumber]) {
			if ($ordinal === 0 || $this->ordinalScope === 'none') {
				$this->weekdays[$weekdayNumber] = true;
			} else {
				$this->weekdayOrdinals[$weekdayNumber][] = $ordinal;
			}
		}
		$this->hasDayFilter = $this->months || $this->monthDays || $this->yearDays || $this->weekNumbers || $byDay;

		$freq = $rule->freq;
		$this->hours = $rule->byHour ?: ($freq->isCoarserThan(Frequency::Hourly) ? [$hour] : []);
		$this->minutes = $rule->byMinute ?: ($freq->isCoarserThan(Frequency::Minutely) ? [$minute] : []);
		$this->seconds = array_values(array_filter(
			$rule->bySecond ?: ($freq->isCoarserThan(Frequency::Secondly) ? [$second] : []),
			static fn(int $value): bool => $value < 60, // a leap second cannot be represented
		));
		if ($rule->bySecond !== [] && $this->seconds === []) {
			return false;
		}

		if ($freq->isCoarserThan(Frequency::Hourly)) {
			foreach ($this->hours as $h) {
				foreach ($this->minutes as $m) {
					foreach ($this->seconds as $s) {
						$this->timeOfDay[] = $h * 3600 + $m * 60 + $s;
					}
				}
			}
			sort($this->timeOfDay);
		}
		return true;
	}

	/**
	 * Candidate [days, seconds of day] pairs of one period, sorted.
	 *
	 * @return list<array{int, int}>
	 */
	private function candidates(Frequency $freq, int $year, int $month, int $periodDays, int $periodSeconds): array {
		$days = match ($freq) {
			Frequency::Yearly => $this->yearDays($year),
			Frequency::Monthly => $this->monthDaysOf($year, $month),
			Frequency::Weekly => array_values(array_filter(range($periodDays, $periodDays + 6), $this->matchesDayNumber(...))),
			default => $this->matchesDayNumber($periodDays) ? [$periodDays] : [],
		};
		if ($days === []) {
			return [];
		}

		if ($freq->isCoarserThan(Frequency::Hourly)) {
			$times = $this->timeOfDay;
		} else {
			$times = $this->subDailyTimes($freq, $periodSeconds);
		}
		if ($times === []) {
			return [];
		}

		$candidates = [];
		foreach ($days as $dayNumber) {
			foreach ($times as $time) {
				$candidates[] = [$dayNumber, $time];
			}
		}
		return $candidates;
	}

	/**
	 * @return list<int>
	 */
	private function subDailyTimes(Frequency $freq, int $periodSeconds): array {
		$hour = intdiv($periodSeconds, 3600);
		$minute = intdiv($periodSeconds % 3600, 60);
		$second = $periodSeconds % 60;
		if ($this->hours !== [] && !in_array($hour, $this->hours, true)) {
			return [];
		}
		if ($freq === Frequency::Hourly) {
			$times = [];
			foreach ($this->minutes as $m) {
				foreach ($this->seconds as $s) {
					$times[] = $hour * 3600 + $m * 60 + $s;
				}
			}
			return $times;
		}
		if ($this->minutes !== [] && !in_array($minute, $this->minutes, true)) {
			return [];
		}
		if ($freq === Frequency::Minutely) {
			return array_map(static fn(int $s): int => $hour * 3600 + $minute * 60 + $s, $this->seconds);
		}
		if ($this->seconds !== [] && !in_array($second, $this->seconds, true)) {
			return [];
		}
		return [$periodSeconds];
	}

	/**
	 * @return list<int>
	 */
	private function yearDays(int $year): array {
		$result = [];
		foreach ($this->months !== [] && $this->yearDays === [] && $this->weekNumbers === [] ? array_keys($this->months) : range(1, 12) as $month) {
			foreach ($this->monthDaysOf($year, $month) as $dayNumber) {
				$result[] = $dayNumber;
			}
		}
		sort($result);
		return $result;
	}

	/**
	 * @return list<int>
	 */
	private function monthDaysOf(int $year, int $month): array {
		$first = self::daysFromCivil($year, $month, 1);
		$length = self::monthLength($year, $month);
		$result = [];
		for ($d = 0; $d < $length; $d++) {
			if ($this->matchesDay($first + $d, $year, $month, $d + 1)) {
				$result[] = $first + $d;
			}
		}
		return $result;
	}

	private function matchesDayNumber(int $days): bool {
		if (!$this->hasDayFilter) {
			return true;
		}
		[$year, $month, $day] = self::civilFromDays($days);
		return $this->matchesDay($days, $year, $month, $day);
	}

	private function matchesDay(int $days, int $year, int $month, int $day): bool {
		if ($this->months !== [] && !isset($this->months[$month])) {
			return false;
		}
		if ($this->monthDays !== []) {
			$length = self::monthLength($year, $month);
			if (!isset($this->monthDays[$day]) && !isset($this->monthDays[$day - $length - 1])) {
				return false;
			}
		}
		if ($this->yearDays !== []) {
			$yearDay = $days - self::daysFromCivil($year, 1, 1) + 1;
			if (!isset($this->yearDays[$yearDay]) && !isset($this->yearDays[$yearDay - self::yearLength($year) - 1])) {
				return false;
			}
		}
		if ($this->weekNumbers !== [] && !$this->matchesWeekNumber($days, $year)) {
			return false;
		}
		if ($this->weekdays !== [] || $this->weekdayOrdinals !== []) {
			$weekday = self::weekday($days);
			if (!isset($this->weekdays[$weekday]) && !$this->matchesWeekdayOrdinal($weekday, $days, $year, $month, $day)) {
				return false;
			}
		}
		return true;
	}

	private function matchesWeekdayOrdinal(int $weekday, int $days, int $year, int $month, int $day): bool {
		if (!isset($this->weekdayOrdinals[$weekday])) {
			return false;
		}
		if ($this->ordinalScope === 'month') {
			$position = $day;
			$length = self::monthLength($year, $month);
		} else {
			$position = $days - self::daysFromCivil($year, 1, 1) + 1;
			$length = self::yearLength($year);
		}
		$fromStart = intdiv($position - 1, 7) + 1;
		$fromEnd = -(intdiv($length - $position, 7) + 1);
		foreach ($this->weekdayOrdinals[$weekday] as $ordinal) {
			if ($ordinal === $fromStart || $ordinal === $fromEnd) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Week numbering per RFC 5545: week 1 is the first week (starting on WKST)
	 * with at least four days in the year.
	 */
	private function matchesWeekNumber(int $days, int $year): bool {
		$weekYear = $year;
		if ($days < $this->week1Start($year)) {
			$weekYear--;
		} elseif ($days >= $this->week1Start($year + 1)) {
			$weekYear++;
		}
		$week1 = $this->week1Start($weekYear);
		$weeks = intdiv($this->week1Start($weekYear + 1) - $week1, 7);
		$number = intdiv($days - $week1, 7) + 1;
		return isset($this->weekNumbers[$number]) || isset($this->weekNumbers[$number - $weeks - 1]);
	}

	private function week1Start(int $year): int {
		if (!isset($this->week1Cache[$year])) {
			$january1 = self::daysFromCivil($year, 1, 1);
			$weekStart = $january1 - ((self::weekday($january1) - $this->rule->wkst + 7) % 7);
			$this->week1Cache[$year] = $january1 - $weekStart <= 3 ? $weekStart : $weekStart + 7;
		}
		return $this->week1Cache[$year];
	}

	/**
	 * @param list<array{int, int}> $candidates
	 * @return list<array{int, int}>
	 */
	private function applySetPos(array $candidates): array {
		$count = count($candidates);
		$selected = [];
		foreach ($this->rule->bySetPos as $position) {
			$index = $position > 0 ? $position - 1 : $count + $position;
			if ($index >= 0 && $index < $count) {
				$selected[$index] = $candidates[$index];
			}
		}
		ksort($selected);
		return array_values($selected);
	}

	/**
	 * Convert a wall-clock time in the DTSTART timezone to a timestamp.
	 *
	 * The UTC offset only changes at timezone transitions, so the last offset is reused
	 * while the result stays more than a day away from any transition. Times close to
	 * a transition (including nonexistent and ambiguous times) are resolved by PHP.
	 */
	private function toTimestamp(int $days, int $secondsOfDay): int {
		$local = $days * 86400 + $secondsOfDay;
		if ($this->fixedOffset !== null) {
			return $local - $this->fixedOffset;
		}
		$timestamp = $local - $this->offset;
		if ($timestamp >= $this->offsetFrom && $timestamp < $this->offsetUntil) {
			return $timestamp;
		}

		// A new object resolves ambiguous times (DST fall-back) to the first occurrence as
		// RFC 5545 requires; nonexistent times (DST gap) move forward by the gap.
		[$year, $month, $day] = self::civilFromDays($days);
		[$hour, $minute, $second] = [intdiv($secondsOfDay, 3600), intdiv($secondsOfDay % 3600, 60), $secondsOfDay % 60];
		$timestamp = $year >= 0 && $year <= 9999
			? (new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $this->timezone))->getTimestamp()
			: $this->probe->setDate($year, $month, $day)->setTime($hour, $minute, $second)->getTimestamp();
		$this->rememberOffset($timestamp);
		return $timestamp;
	}

	private function rememberOffset(int $timestamp): void {
		$window = 200 * 86400;
		$previous = $timestamp - $window;
		$next = $timestamp + $window;
		foreach ($this->timezone->getTransitions($timestamp - $window, $timestamp + $window) ?: [] as $index => $transition) {
			if ($index === 0) {
				continue; // the first entry describes the start of the range, not a transition
			}
			if ($transition['ts'] <= $timestamp) {
				$previous = $transition['ts'];
			} elseif ($transition['ts'] < $next) {
				$next = $transition['ts'];
				break;
			}
		}
		$this->offset = $this->timezone->getOffset($this->probe->setTimestamp($timestamp));
		$this->offsetFrom = $previous + 86400;
		$this->offsetUntil = $next - 86400;
	}

	/**
	 * Days since 1970-01-01 for a proleptic Gregorian date.
	 */
	public static function daysFromCivil(int $year, int $month, int $day): int {
		$year -= $month <= 2 ? 1 : 0;
		$era = intdiv($year >= 0 ? $year : $year - 399, 400);
		$yearOfEra = $year - $era * 400;
		$dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
		$dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
		return $era * 146097 + $dayOfEra - 719468;
	}

	/**
	 * @return array{int, int, int} year, month, day
	 */
	public static function civilFromDays(int $days): array {
		$days += 719468;
		$era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
		$dayOfEra = $days - $era * 146097;
		$yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
		$dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
		$monthPart = intdiv(5 * $dayOfYear + 2, 153);
		$day = $dayOfYear - intdiv(153 * $monthPart + 2, 5) + 1;
		$month = $monthPart < 10 ? $monthPart + 3 : $monthPart - 9;
		return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
	}

	/**
	 * ISO-8601 weekday (1 = Monday ... 7 = Sunday).
	 */
	public static function weekday(int $days): int {
		return (($days % 7) + 10) % 7 + 1;
	}

	private static function monthLength(int $year, int $month): int {
		return $month === 2 ? (self::isLeapYear($year) ? 29 : 28) : (in_array($month, [4, 6, 9, 11], true) ? 30 : 31);
	}

	private static function yearLength(int $year): int {
		return self::isLeapYear($year) ? 366 : 365;
	}

	private static function isLeapYear(int $year): bool {
		return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
	}
}
