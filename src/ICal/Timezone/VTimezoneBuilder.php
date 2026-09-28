<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Property;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\PropertyFactory;
use om\ICal\Value\Text;
use om\ICal\Value\UtcOffset;
use om\RRule\Rule;

/**
 * Creates a VTIMEZONE component (RFC 5545, section 3.6.5) of a PHP timezone from its transitions
 * (DateTimeZone::getTransitions()) in a range of time.
 *
 * Transitions of the same kind (offsets, abbreviation, DST) on the same weekday of a month
 * (e.g. the last Sunday of March) in consecutive years become one observance with a yearly
 * RRULE; other transitions are observances of their own. A rule still in use after the range
 * has no UNTIL, so the definition also covers later dates while the timezone keeps the rule.
 * The first observance is the transition in effect at the start of the range.
 */
final class VTimezoneBuilder {
	/** Years after the last date of a recurrence without an end (RRULE without COUNT and UNTIL) covered by the definition. */
	public const int OPEN_YEARS = 10;

	private const int YEAR = 366 * 86400;

	/**
	 * VTIMEZONE components of the IANA TZIDs used by the components (also in their child
	 * components), in the order of first use. Every definition covers the dates of its TZID with
	 * a year before and after; a recurrence also its last occurrence, and OPEN_YEARS after its
	 * start when it has no end. Other TZIDs (not IANA names) are skipped.
	 *
	 * @param iterable<Component> $components
	 * @param list<string> $defined TZIDs that already have a definition
	 * @return list<Component>
	 */
	public static function forComponents(iterable $components, array $defined = []): array {
		$ranges = [];
		foreach ($components as $component) {
			self::collect($component, $ranges);
		}
		$result = [];
		foreach ($ranges as $tzid => [$min, $max]) {
			if (!in_array($tzid, $defined, true)) {
				$result[] = self::build(new DateTimeZone($tzid), $min - self::YEAR, $max + self::YEAR, $tzid);
			}
		}
		return $result;
	}

	/**
	 * @param ?string $tzid TZID of the definition, the name of the timezone by default
	 * @throws InvalidArgumentException when $to is before $from
	 */
	public static function build(DateTimeZone $timezone, DateTimeInterface|int $from, DateTimeInterface|int $to, ?string $tzid = null): Component {
		$from = $from instanceof DateTimeInterface ? $from->getTimestamp() : $from;
		$to = $to instanceof DateTimeInterface ? $to->getTimestamp() : $to;
		if ($to < $from) {
			throw new InvalidArgumentException('The end of the range is before its start.');
		}
		$transitions = self::transitions($timezone, $from, $to);
		$observances = [];
		foreach (self::runs($timezone, $transitions, $to) as [$run, $open]) {
			$first = $run[0];
			$properties = [
				Property::create('DTSTART', gmdate('Ymd\THis', $first['ts'] + $first['from'])),
				Property::create('TZOFFSETFROM', UtcOffset::format($first['from'])),
				Property::create('TZOFFSETTO', UtcOffset::format($first['offset'])),
			];
			if (count($run) > 1 || $open) {
				$rule = self::rule($first);
				$rrule = sprintf('FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', $rule[0], $rule[2], array_search($rule[1], Rule::WEEKDAYS, true));
				$properties[] = Property::create('RRULE', $open ? $rrule : $rrule . ';UNTIL=' . gmdate('Ymd\THis\Z', $run[count($run) - 1]['ts']));
			}
			if ($first['abbr'] !== '') {
				$properties[] = Property::create('TZNAME', Text::escape($first['abbr']));
			}
			$observances[] = new Component($first['isdst'] ? 'DAYLIGHT' : 'STANDARD', $properties);
		}
		$name = $timezone->getName();
		return new Component('VTIMEZONE', [
			Property::create('TZID', Text::escape($tzid ?? $name)),
			Property::create('X-LIC-LOCATION', Text::escape($name)),
		], $observances);
	}

	/**
	 * Instants of the values of IANA TZIDs, extended to the end of a recurrence.
	 *
	 * @param array<string, array{int, int}> $ranges TZID => [first, last]
	 * @param-out array<string, array{int, int}> $ranges
	 */
	private static function collect(Component $component, array &$ranges): void {
		$used = [];
		foreach ($component->properties as $property) {
			$tzid = $property->parameter('TZID');
			if ($tzid === null || !PropertyFactory::isTimezoneName($tzid)) {
				continue;
			}
			$timezone = new DateTimeZone($tzid);
			foreach (explode(',', $property->value) as $item) {
				foreach (explode('/', $item, 2) as $part) { // the start and the end of a PERIOD
					if (DateTimeValue::isValid($part)) {
						$timestamp = DateTimeValue::parse($part, false, $tzid, $timezone)->toDateTime($timezone)->getTimestamp();
						$ranges[$tzid] = [min($ranges[$tzid][0] ?? $timestamp, $timestamp), max($ranges[$tzid][1] ?? $timestamp, $timestamp)];
						$used[$tzid] = max($used[$tzid] ?? $timestamp, $timestamp);
					}
				}
			}
		}
		if ($used !== [] && $component->has('RRULE')) {
			$end = self::recurrenceEnd($component, max($used));
			foreach (array_keys($used) as $tzid) {
				[$first, $lastUsed] = $ranges[$tzid] ?? [$end, $end];
				$ranges[$tzid] = [$first, max($lastUsed, $end)];
			}
		}
		foreach ($component->components as $child) {
			self::collect($child, $ranges);
		}
	}

	/**
	 * End of the last occurrence of a recurring component; OPEN_YEARS after its last date without an end.
	 */
	private static function recurrenceEnd(Component $component, int $last): int {
		$open = $last + self::OPEN_YEARS * self::YEAR;
		$item = (new Calendar())->itemOf($component);
		if ($item === null) {
			return $last;
		}
		foreach ($item->recurrenceRules() as $rule) {
			if (!$rule->isGregorian() || ($rule->count === null && $rule->until === null)) {
				return $open;
			}
		}
		try {
			foreach ($item->occurrences(10000) as $occurrence) {
				$last = max($last, $occurrence->end->toDateTime(null, new DateTimeZone('UTC'))->getTimestamp());
			}
		} catch (Exception) {
			return $open; // a limit of the recurrence
		}
		return $last;
	}

	/**
	 * The transition in effect at $from and the transitions until $to, with the offset before each one.
	 *
	 * @return non-empty-list<array{ts: int, from: int, offset: int, isdst: bool, abbr: string}>
	 */
	private static function transitions(DateTimeZone $timezone, int $from, int $to): array {
		$previous = null;
		foreach ([self::YEAR, 10 * self::YEAR, 150 * self::YEAR] as $window) {
			$list = self::changes($timezone, $from - $window, $from);
			if (count($list) > 1) {
				$previous = $list[count($list) - 1];
				break;
			}
		}
		if ($previous === null) {
			// no transition before the range: the offset of the range since 1970 (or the start of the range)
			$state = self::changes($timezone, $from, $from)[0];
			$previous = [...$state, 'ts' => $from >= 0 ? min($from, -$state['offset']) : $from];
		}
		$result = [$previous];
		foreach (array_slice(self::changes($timezone, $from, $to), 1) as $transition) {
			if ($transition['ts'] > $previous['ts']) {
				$result[] = $transition;
			}
		}
		return $result;
	}

	/**
	 * Transitions of getTransitions($begin, $end) with the offset before each one; the first item is
	 * the state at $begin. A transition exactly at $begin is merged into the state.
	 *
	 * @return non-empty-list<array{ts: int, from: int, offset: int, isdst: bool, abbr: string}>
	 */
	private static function changes(DateTimeZone $timezone, int $begin, int $end): array {
		$list = $timezone->getTransitions($begin, $end) ?: [['ts' => $begin, 'offset' => $timezone->getOffset(new DateTimeImmutable('@' . $begin)), 'isdst' => false, 'abbr' => '']];
		$first = array_shift($list);
		$result = [['ts' => $begin, 'from' => (int) $first['offset'], 'offset' => (int) $first['offset'], 'isdst' => (bool) $first['isdst'], 'abbr' => (string) $first['abbr']]];
		$offset = (int) $first['offset'];
		foreach ($list as $transition) {
			if ($transition['ts'] <= $begin) {
				$result[0] = ['ts' => $begin, 'from' => (int) $transition['offset'], 'offset' => (int) $transition['offset'], 'isdst' => (bool) $transition['isdst'], 'abbr' => (string) $transition['abbr']];
				$offset = (int) $transition['offset'];
				continue;
			}
			$result[] = ['ts' => (int) $transition['ts'], 'from' => $offset, 'offset' => (int) $transition['offset'], 'isdst' => (bool) $transition['isdst'], 'abbr' => (string) $transition['abbr']];
			$offset = (int) $transition['offset'];
		}
		return $result;
	}

	/**
	 * Transitions grouped into observances: runs of the same kind and rule in consecutive years;
	 * a run is open when its rule continues after the range.
	 *
	 * @param non-empty-list<array{ts: int, from: int, offset: int, isdst: bool, abbr: string}> $transitions
	 * @return list<array{non-empty-list<array{ts: int, from: int, offset: int, isdst: bool, abbr: string}>, bool}>
	 */
	private static function runs(DateTimeZone $timezone, array $transitions, int $to): array {
		$runs = [];
		$current = []; // kind => index in $runs
		foreach ($transitions as $transition) {
			$kind = self::kind($transition);
			$index = $current[$kind] ?? null;
			if ($index !== null) {
				$last = $runs[$index][count($runs[$index]) - 1];
				if (self::year($transition) === self::year($last) + 1 && self::rule($transition) === self::rule($last)) {
					$runs[$index][] = $transition;
					continue;
				}
			}
			$current[$kind] = count($runs);
			$runs[] = [$transition];
		}

		// the runs of the last transitions of each kind continue when the next one follows the rule
		$after = array_slice(self::changes($timezone, $to, $to + 2 * self::YEAR), 1);
		$result = [];
		foreach ($runs as $index => $run) {
			$last = $run[count($run) - 1];
			$open = false;
			if (($current[self::kind($last)] ?? null) === $index) {
				foreach ($after as $next) {
					if (self::kind($next) === self::kind($last)) {
						$open = self::year($next) === self::year($last) + 1 && self::rule($next) === self::rule($last);
						break;
					}
				}
			}
			$result[] = [$run, $open];
		}
		return $result;
	}

	/**
	 * @param array{ts: int, from: int, offset: int, isdst: bool, abbr: string} $transition
	 */
	private static function kind(array $transition): string {
		return implode('|', [(int) $transition['isdst'], $transition['from'], $transition['offset'], $transition['abbr']]);
	}

	/**
	 * @param array{ts: int, from: int, offset: int, isdst: bool, abbr: string} $transition
	 */
	private static function year(array $transition): int {
		return (int) gmdate('Y', $transition['ts'] + $transition['from']);
	}

	/**
	 * Month, ISO weekday, the week of the month (-1 for the last one) and the local time.
	 *
	 * @param array{ts: int, from: int, offset: int, isdst: bool, abbr: string} $transition
	 * @return array{int, int, int, string}
	 */
	private static function rule(array $transition): array {
		$local = $transition['ts'] + $transition['from'];
		$day = (int) gmdate('j', $local);
		$week = $day + 7 > (int) gmdate('t', $local) ? -1 : intdiv($day - 1, 7) + 1;
		return [(int) gmdate('n', $local), (int) gmdate('N', $local), $week, gmdate('His', $local)];
	}
}
