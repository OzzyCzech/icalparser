<?php
declare(strict_types=1);

namespace om\RRule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Validated recurrence rule (RFC 5545, section 3.3.10).
 *
 * Weekdays are ISO-8601 numbers (1 = Monday ... 7 = Sunday).
 */
final readonly class Rule {
	public const array WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

	/**
	 * @param list<int> $bySecond
	 * @param list<int> $byMinute
	 * @param list<int> $byHour
	 * @param list<array{int, int}> $byDay pairs of [ordinal (0 = every), weekday]
	 * @param list<int> $byMonthDay
	 * @param list<int> $byYearDay
	 * @param list<int> $byWeekNo
	 * @param list<int> $byMonth
	 * @param list<int> $bySetPos
	 * @param DateTimeImmutable|string|null $until parsed date, or a floating value resolved in the DTSTART timezone
	 */
	public function __construct(
		public Frequency $freq,
		public int $interval = 1,
		public ?int $count = null,
		public DateTimeImmutable|string|null $until = null,
		public array $bySecond = [],
		public array $byMinute = [],
		public array $byHour = [],
		public array $byDay = [],
		public array $byMonthDay = [],
		public array $byYearDay = [],
		public array $byWeekNo = [],
		public array $byMonth = [],
		public array $bySetPos = [],
		public int $wkst = 1,
	) {
		if ($interval < 1) {
			throw new InvalidArgumentException('INTERVAL must be a positive integer.');
		}
		if ($count !== null && $count < 1) {
			throw new InvalidArgumentException('COUNT must be a positive integer.');
		}
	}

	/**
	 * Parse a rule like "FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE".
	 */
	public static function fromString(string $rule): self {
		$parts = [];
		foreach (explode(';', trim($rule)) as $part) {
			if ($part === '') {
				continue;
			}
			$pair = explode('=', $part, 2);
			if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
				throw new InvalidArgumentException('Invalid recurrence rule.');
			}
			$parts[$pair[0]] = $pair[1];
		}
		return self::fromArray($parts);
	}

	/**
	 * Build a rule from rule parts; keys are case-insensitive and unknown parts are ignored.
	 * UNTIL may be a string, a DateTimeInterface or a Unix timestamp.
	 *
	 * @param array<string, mixed> $parts
	 */
	public static function fromArray(array $parts): self {
		$parts = array_change_key_case($parts, CASE_UPPER);
		$freq = Frequency::tryFrom(strtoupper(self::scalar($parts['FREQ'] ?? '', 'FREQ')));
		if ($freq === null) {
			throw new InvalidArgumentException('Unsupported recurrence frequency: ' . self::scalar($parts['FREQ'] ?? '', 'FREQ'));
		}

		$interval = isset($parts['INTERVAL']) ? self::positiveInt($parts['INTERVAL'], 'INTERVAL') : 1;
		$count = isset($parts['COUNT']) ? self::positiveInt($parts['COUNT'], 'COUNT') : null;

		$until = null;
		if (isset($parts['UNTIL'])) {
			$until = self::until($parts['UNTIL']);
		}

		$wkst = 1;
		if (isset($parts['WKST'])) {
			$wkst = self::WEEKDAYS[strtoupper(self::scalar($parts['WKST'], 'WKST'))]
				?? throw new InvalidArgumentException('Invalid WKST value.');
		}

		return new self(
			freq: $freq,
			interval: $interval,
			count: $count,
			until: $until,
			bySecond: self::intList($parts, 'BYSECOND', 0, 60),
			byMinute: self::intList($parts, 'BYMINUTE', 0, 59),
			byHour: self::intList($parts, 'BYHOUR', 0, 23),
			byDay: self::weekdayList($parts),
			byMonthDay: self::intList($parts, 'BYMONTHDAY', -31, 31, false),
			byYearDay: self::intList($parts, 'BYYEARDAY', -366, 366, false),
			byWeekNo: self::intList($parts, 'BYWEEKNO', -53, 53, false),
			byMonth: self::intList($parts, 'BYMONTH', 1, 12),
			bySetPos: self::intList($parts, 'BYSETPOS', -366, 366, false),
			wkst: $wkst,
		);
	}

	/**
	 * Resolve UNTIL to an instant; floating values use the given timezone.
	 * A date-only UNTIL includes the whole day.
	 */
	public function untilTimestamp(DateTimeZone $timezone): ?int {
		if ($this->until === null) {
			return null;
		}
		if ($this->until instanceof DateTimeImmutable) {
			return $this->until->getTimestamp();
		}
		if (strlen($this->until) === 8) {
			return (new DateTimeImmutable($this->until . 'T235959', $timezone))->getTimestamp();
		}
		return (new DateTimeImmutable($this->until, $timezone))->getTimestamp();
	}

	private static function until(mixed $value): DateTimeImmutable|string {
		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}
		if (is_int($value)) {
			return new DateTimeImmutable('@' . $value);
		}
		$value = strtoupper(self::scalar($value, 'UNTIL'));
		if (preg_match('/^\d{8}$/D', $value)) {
			return $value;
		}
		if (preg_match('/^\d{8}T\d{6}Z$/D', $value)) {
			return new DateTimeImmutable($value);
		}
		if (preg_match('/^\d{8}T\d{6}$/D', $value)) {
			return $value;
		}
		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			throw new InvalidArgumentException('UNTIL must be a valid date or timestamp.');
		}
	}

	private static function scalar(mixed $value, string $name): string {
		if (is_string($value) || is_int($value)) {
			return trim((string) $value);
		}
		throw new InvalidArgumentException($name . ' must be a string.');
	}

	private static function positiveInt(mixed $value, string $name): int {
		$value = self::scalar($value, $name);
		if (!preg_match('/^\+?\d{1,9}$/D', $value) || (int) $value < 1) {
			throw new InvalidArgumentException($name . ' must be a positive integer.');
		}
		return (int) $value;
	}

	/**
	 * @param array<string, mixed> $parts
	 * @return list<int>
	 */
	private static function intList(array $parts, string $name, int $min, int $max, bool $allowZero = true): array {
		if (!isset($parts[$name])) {
			return [];
		}
		$values = [];
		foreach (explode(',', self::scalar($parts[$name], $name)) as $item) {
			$item = trim($item);
			if ($item === '') {
				continue; // tolerate "BYHOUR=9," produced by some generators
			}
			if (!preg_match('/^[+-]?\d{1,3}$/D', $item)) {
				throw new InvalidArgumentException("Invalid $name value: $item");
			}
			$value = (int) $item;
			if ($value < $min || $value > $max || (!$allowZero && $value === 0)) {
				throw new InvalidArgumentException("$name value out of range: $item");
			}
			$values[$value] = $value;
		}
		sort($values);
		return $values;
	}

	/**
	 * @param array<string, mixed> $parts
	 * @return list<array{int, int}>
	 */
	private static function weekdayList(array $parts): array {
		if (!isset($parts['BYDAY'])) {
			return [];
		}
		$values = [];
		foreach (explode(',', strtoupper(self::scalar($parts['BYDAY'], 'BYDAY'))) as $item) {
			$item = trim($item);
			if ($item === '') {
				continue;
			}
			if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/D', $item, $match)) {
				throw new InvalidArgumentException("Invalid BYDAY value: $item");
			}
			$ordinal = (int) ($match[1] ?? 0);
			if ($ordinal < -53 || $ordinal > 53) {
				throw new InvalidArgumentException("BYDAY value out of range: $item");
			}
			$values[] = [$ordinal, self::WEEKDAYS[$match[2]]];
		}
		return $values;
	}
}
