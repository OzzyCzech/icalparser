<?php
declare(strict_types=1);

namespace om;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use om\RRule\Expander;
use om\RRule\Rule;

/**
 * Finds a PHP timezone for a custom VTIMEZONE definition (RFC 5545, section 3.6.5).
 *
 * PHP cannot create a timezone from custom rules, so the transitions defined by the
 * STANDARD and DAYLIGHT observances are calculated for the years around the reference
 * date and compared with the transitions of the IANA timezones. A definition without
 * transitions becomes a fixed UTC offset. The first matching timezone is returned,
 * zones used by the Windows mapping are preferred.
 */
final class TimezoneGuesser {
	/** @var array<string, DateTimeZone|false> */
	private static array $cache = [];

	/**
	 * @param list<array{start: string, offsetFrom: string, offsetTo: string, rrule?: ?string, rdates?: list<string>}> $observances
	 *        start is the local DTSTART like "19701025T030000", offsets look like "+0100"
	 * @param list<string> $preferred timezones tried first
	 */
	public static function guess(array $observances, ?DateTimeImmutable $reference = null, array $preferred = []): ?DateTimeZone {
		if ($observances === []) {
			return null;
		}
		$year = (int) ($reference ?? new DateTimeImmutable())->format('Y');
		$from = Expander::daysFromCivil($year - 1, 1, 1) * 86400;
		$until = Expander::daysFromCivil($year + 2, 1, 1) * 86400;

		try {
			[$initial, $transitions] = self::transitions($observances, $from, $until);
		} catch (InvalidArgumentException | Exception) {
			return null; // invalid DTSTART, offset or RRULE
		}

		$key = json_encode([$initial, $transitions]) . implode(',', $preferred);
		if (!isset(self::$cache[$key])) {
			self::$cache[$key] = $transitions === []
				? self::fixedOffset($initial)
				: self::find($initial, $transitions, $from, $until, $preferred) ?? false;
		}
		return self::$cache[$key] ?: null;
	}

	/**
	 * Offset in effect at $from and the offset changes in [$from, $until).
	 *
	 * @param list<array{start: string, offsetFrom: string, offsetTo: string, rrule?: ?string, rdates?: list<string>}> $observances
	 * @return array{int, list<array{int, int}>}
	 */
	private static function transitions(array $observances, int $from, int $until): array {
		$utc = new DateTimeZone('UTC');
		$all = [];
		foreach ($observances as $observance) {
			$offsetFrom = self::offset($observance['offsetFrom']);
			$offsetTo = self::offset($observance['offsetTo']);
			// wall-clock times are expanded as if they were UTC, then shifted by TZOFFSETFROM
			$start = new DateTimeImmutable($observance['start'], $utc);
			$local = [];
			if (!empty($observance['rrule'])) {
				foreach (new Expander(Rule::fromString($observance['rrule']), $start, $until + 2 * 86400) as $timestamp) {
					$local[] = $timestamp; // includes DTSTART
				}
			} else {
				$local[] = $start->getTimestamp();
			}
			foreach ($observance['rdates'] ?? [] as $rdate) {
				$local[] = (new DateTimeImmutable($rdate, $utc))->getTimestamp();
			}
			foreach ($local as $timestamp) {
				$all[] = [$timestamp - $offsetFrom, $offsetTo];
			}
		}
		usort($all, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

		$initial = self::offset($observances[0]['offsetFrom']);
		$transitions = [];
		foreach ($all as [$timestamp, $offset]) {
			if ($timestamp < $from) {
				$initial = $offset;
			} elseif ($timestamp < $until) {
				$transitions[] = [$timestamp, $offset];
			}
		}
		return [$initial, self::withoutRepeatedOffsets($initial, $transitions)];
	}

	/**
	 * @param list<array{int, int}> $transitions
	 * @param list<string> $preferred
	 */
	private static function find(int $initial, array $transitions, int $from, int $until, array $preferred): ?DateTimeZone {
		$candidates = array_unique([...$preferred, ...DateTimeZone::listIdentifiers()]);
		foreach ($candidates as $name) {
			try {
				$timezone = new DateTimeZone($name);
			} catch (Exception) {
				continue;
			}
			$known = $timezone->getTransitions($from, $until - 1) ?: [];
			if ($known === [] || $known[0]['offset'] !== $initial) {
				continue;
			}
			$changes = [];
			foreach (array_slice($known, 1) as $transition) {
				$changes[] = [$transition['ts'], $transition['offset']];
			}
			if (self::withoutRepeatedOffsets($initial, $changes) === $transitions) {
				return $timezone;
			}
		}
		return null;
	}

	/**
	 * @param list<array{int, int}> $transitions
	 * @return list<array{int, int}>
	 */
	private static function withoutRepeatedOffsets(int $offset, array $transitions): array {
		$result = [];
		foreach ($transitions as [$timestamp, $next]) {
			if ($next !== $offset) {
				$result[] = [$timestamp, $next];
				$offset = $next;
			}
		}
		return $result;
	}

	private static function fixedOffset(int $offset): DateTimeZone {
		$sign = $offset < 0 ? '-' : '+';
		$offset = abs($offset);
		return new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60)));
	}

	/**
	 * Parse a UTC-OFFSET value ("+0100", "-053000").
	 */
	public static function offset(string $value): int {
		if (!preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/D', trim($value), $match)) {
			throw new InvalidArgumentException("Invalid UTC offset: $value");
		}
		$seconds = (int) $match[2] * 3600 + (int) $match[3] * 60 + (int) ($match[4] ?? 0);
		return $match[1] === '-' ? -$seconds : $seconds;
	}
}
