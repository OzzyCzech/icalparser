<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use om\ICal\Exception\InvalidValueException;
use om\ICal\Exception\TimezoneResolutionException;
use om\RRule\LocalTime;
use Stringable;

/**
 * A DATE or DATE-TIME value that keeps its meaning: date, floating, UTC or zoned time.
 *
 * Floating times and dates have no timezone; they are converted to an instant only when
 * a timezone is given to toDateTime(). Local values can always be read with format().
 */
final readonly class DateTimeValue implements Stringable {
	/**
	 * @param DateTimeImmutable $dateTime the value in its timezone; the wall-clock time in UTC for dates and floating times
	 * @param ?string $tzid TZID parameter, also kept for floating times whose TZID could not be resolved
	 */
	private function __construct(
		public DateTimeType $type,
		private DateTimeImmutable $dateTime,
		public ?string $tzid = null,
	) {
	}

	public static function date(int $year, int $month, int $day): self {
		return new self(DateTimeType::Date, self::wallClock(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day)));
	}

	/**
	 * A floating time with the wall-clock time of the given date.
	 */
	public static function floating(DateTimeInterface $wallClock, ?string $unresolvedTzid = null): self {
		return new self(DateTimeType::Floating, self::wallClock($wallClock->format('Y-m-d H:i:s')), $unresolvedTzid);
	}

	/**
	 * A UTC time for a UTC date, a zoned time otherwise.
	 */
	public static function fromDateTime(DateTimeInterface $dateTime, ?string $tzid = null): self {
		$dateTime = DateTimeImmutable::createFromInterface($dateTime);
		$name = $dateTime->getTimezone()->getName();
		if (in_array($name, ['UTC', 'Z', '+00:00', 'GMT'], true)) {
			return new self(DateTimeType::Utc, $dateTime->setTimezone(new DateTimeZone('UTC')));
		}
		return new self(DateTimeType::Zoned, $dateTime, $tzid ?? $name);
	}

	/**
	 * Parse "20261010", "20261010T100000" or "20261010T100000Z".
	 *
	 * @param ?DateTimeZone $timezone resolved TZID; null keeps a TZID value floating
	 * @throws InvalidValueException
	 */
	public static function parse(string $value, bool $date = false, ?string $tzid = null, ?DateTimeZone $timezone = null): self {
		$raw = $value;
		$value = strtoupper(trim($value));
		if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/D', $value, $match, PREG_UNMATCHED_AS_NULL)
			|| !checkdate((int) $match[2], (int) $match[3], (int) $match[1])
			|| ($match[4] !== null && ((int) $match[4] > 23 || (int) $match[5] > 59 || (int) $match[6] > 60))
			|| ($date && $match[4] !== null)) {
			throw InvalidValueException::create('value.invalid-date-time', 'Invalid DATE-TIME value: ' . $raw, rawValue: $raw);
		}
		[, $year, $month, $day, $hour, $minute, $second, $utc] = $match;
		$local = sprintf('%s-%s-%s %s:%s:%s', $year, $month, $day, $hour ?? '00', $minute ?? '00', min((int) ($second ?? 0), 59));

		if ($hour === null) {
			return new self(DateTimeType::Date, self::wallClock($local));
		}
		if ($utc !== null) {
			return new self(DateTimeType::Utc, new DateTimeImmutable($local, new DateTimeZone('UTC')));
		}
		if ($tzid !== null && $timezone !== null) {
			// ambiguous and nonexistent local times as RFC 5545 requires (PHP is not consistent)
			$instant = LocalTime::timestamp($timezone, self::wallClock($local)->getTimestamp());
			return new self(DateTimeType::Zoned, (new DateTimeImmutable('@' . $instant))->setTimezone($timezone), $tzid);
		}
		return new self(DateTimeType::Floating, self::wallClock($local), $tzid);
	}

	public function isDate(): bool {
		return $this->type === DateTimeType::Date;
	}

	public function isFloating(): bool {
		return $this->type === DateTimeType::Floating;
	}

	public function isUtc(): bool {
		return $this->type === DateTimeType::Utc;
	}

	public function isZoned(): bool {
		return $this->type === DateTimeType::Zoned;
	}

	/**
	 * Timezone of a UTC or zoned value.
	 */
	public function timezone(): ?DateTimeZone {
		return $this->type === DateTimeType::Utc || $this->type === DateTimeType::Zoned ? $this->dateTime->getTimezone() : null;
	}

	/**
	 * Format the local value (see DateTimeInterface::format()) without any conversion.
	 */
	public function format(string $format): string {
		return $this->dateTime->format($format);
	}

	/**
	 * The instant of the value.
	 *
	 * UTC and zoned values keep their timezone unless one is given. Dates (midnight) and floating
	 * times need a timezone: the given one, or the $default one (e.g. X-WR-TIMEZONE).
	 *
	 * @throws TimezoneResolutionException for a date or floating time without a timezone
	 */
	public function toDateTime(?DateTimeZone $timezone = null, ?DateTimeZone $default = null): DateTimeImmutable {
		if ($this->type === DateTimeType::Utc || $this->type === DateTimeType::Zoned) {
			return $timezone === null ? $this->dateTime : $this->dateTime->setTimezone($timezone);
		}
		$timezone ??= $default ?? throw TimezoneResolutionException::create(
			'timezone.floating',
			sprintf('The %s value %s needs a timezone to become an instant.', $this->type === DateTimeType::Date ? 'DATE' : 'floating', $this),
		);
		return new DateTimeImmutable($this->dateTime->format('Y-m-d H:i:s'), $timezone);
	}

	/**
	 * Add a duration: days keep the local time (also across DST), hours are elapsed time
	 * for UTC and zoned values (RFC 5545, section 3.3.6).
	 */
	public function add(DateInterval $interval): self {
		return new self($this->type, $this->dateTime->add($interval), $this->tzid);
	}

	/**
	 * A value of the same kind at another instant or wall-clock time.
	 *
	 * @internal
	 */
	public function withDateTime(DateTimeImmutable $dateTime): self {
		return new self($this->type, match ($this->type) {
			DateTimeType::Utc, DateTimeType::Zoned => $dateTime->setTimezone($this->dateTime->getTimezone()),
			default => self::wallClock($dateTime->format('Y-m-d H:i:s')),
		}, $this->tzid);
	}

	/**
	 * Wall-clock time as a UTC DateTimeImmutable: for recurrence expansion of dates and floating times.
	 *
	 * @internal
	 */
	public function wallClockAsUtc(): DateTimeImmutable {
		return $this->type === DateTimeType::Zoned || $this->type === DateTimeType::Utc
			? self::wallClock($this->dateTime->format('Y-m-d H:i:s'))
			: $this->dateTime;
	}

	/**
	 * The value for recurrence calculations: the instant of UTC and zoned values,
	 * the wall-clock time (as UTC) of dates and floating times.
	 *
	 * @internal
	 */
	public function base(): DateTimeImmutable {
		return $this->dateTime;
	}

	public function equals(self $other): bool {
		return $this->type === $other->type && $this->dateTime == $other->dateTime;
	}

	/**
	 * The iCalendar value, e.g. "20261010T100000Z" (the TZID parameter is not included).
	 */
	public function __toString(): string {
		return match ($this->type) {
			DateTimeType::Date => $this->dateTime->format('Ymd'),
			DateTimeType::Utc => $this->dateTime->format('Ymd\THis\Z'),
			default => $this->dateTime->format('Ymd\THis'),
		};
	}

	private static function wallClock(string $local): DateTimeImmutable {
		try {
			return new DateTimeImmutable($local, new DateTimeZone('UTC'));
		} catch (Exception $e) {
			throw InvalidValueException::create('value.invalid-date-time', 'Invalid DATE-TIME value: ' . $local, rawValue: $local, previous: $e);
		}
	}
}
