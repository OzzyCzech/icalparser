<?php
declare(strict_types=1);

namespace om\ICal\Value;

use om\ICal\Exception\InvalidValueException;

/**
 * UTC-OFFSET values (RFC 5545, section 3.3.14) in seconds.
 */
final class UtcOffset {

	/**
	 * @throws InvalidValueException
	 */
	public static function parse(string $value): int {
		if (!preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/D', trim($value), $match)) {
			throw InvalidValueException::create('value.invalid-utc-offset', "Invalid UTC-OFFSET value: $value", rawValue: $value);
		}
		$seconds = (int) $match[2] * 3600 + (int) $match[3] * 60 + (int) ($match[4] ?? 0);
		return $match[1] === '-' ? -$seconds : $seconds;
	}

	public static function format(int $seconds): string {
		$sign = $seconds < 0 ? '-' : '+';
		$seconds = abs($seconds);
		$result = sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
		return $seconds % 60 ? $result . sprintf('%02d', $seconds % 60) : $result;
	}
}
